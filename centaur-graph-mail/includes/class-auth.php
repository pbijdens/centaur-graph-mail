<?php
namespace Centaur\GraphMail;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth 2.0 against Entra ID.
 *
 * An administrator signs in once with the authorization code flow (+PKCE) and grants the delegated
 * Mail.Send / Mail.Send.Shared scopes. The resulting refresh token is stored encrypted and used to obtain
 * access tokens whenever WordPress sends mail, including from cron or anonymous front-end requests.
 */
final class Auth {
	public const KEEPALIVE_HOOK = 'centaur_graph_mail_keepalive';

	private const TOKEN_OPTION     = 'centaur_graph_mail_token';
	private const ACCESS_TRANSIENT = 'centaur_graph_mail_access_token';
	private const STATE_PREFIX     = 'cgm_';
	private const STATE_TTL        = 15 * MINUTE_IN_SECONDS;

	/** Delegated scopes requested from the signed-in user. Mail.Send.Shared covers sending as another mailbox. */
	public const SCOPES = array(
		'openid',
		'profile',
		'offline_access',
		'https://graph.microsoft.com/User.Read',
		'https://graph.microsoft.com/Mail.Send',
		'https://graph.microsoft.com/Mail.Send.Shared',
	);

	/**
	 * Registered as the Web redirect URI of the Entra app. admin-post.php without an "action"
	 * parameter fires the 'admin_post' hook, which keeps the URI free of query strings.
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php' );
	}

	private static function authority(): string {
		return 'https://login.microsoftonline.com/' . rawurlencode( Settings::tenant_id() ) . '/oauth2/v2.0';
	}

	/* ------------------------------------------------------------------ *
	 * Authorization code flow
	 * ------------------------------------------------------------------ */

	public static function authorization_url( int $user_id ): string {
		$state    = self::STATE_PREFIX . wp_generate_password( 32, false );
		$verifier = wp_generate_password( 64, false );

		set_transient(
			'centaur_graph_mail_state_' . $state,
			array(
				'user_id'  => $user_id,
				'verifier' => Crypto::encrypt( $verifier ),
			),
			self::STATE_TTL
		);

		return self::authority() . '/authorize?' . http_build_query(
			array(
				'client_id'             => Settings::client_id(),
				'response_type'         => 'code',
				'redirect_uri'          => self::redirect_uri(),
				'response_mode'         => 'query',
				'scope'                 => implode( ' ', self::SCOPES ),
				'state'                 => $state,
				'prompt'                => 'select_account',
				'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	public static function is_callback( array $query ): bool {
		return isset( $query['state'] ) && is_string( $query['state'] ) && str_starts_with( $query['state'], self::STATE_PREFIX );
	}

	/** @return true|WP_Error */
	public static function handle_callback( array $query, int $user_id ) {
		$state = sanitize_text_field( (string) $query['state'] );
		$key   = 'centaur_graph_mail_state_' . $state;
		$saved = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $saved ) || (int) $saved['user_id'] !== $user_id ) {
			return new WP_Error( 'cgm_state', __( 'The sign-in request expired or was started by another user. Please try again.', 'centaur-graph-mail' ) );
		}

		if ( ! empty( $query['error'] ) ) {
			return new WP_Error(
				'cgm_authorize',
				sprintf( '%s: %s', sanitize_text_field( (string) $query['error'] ), sanitize_text_field( (string) ( $query['error_description'] ?? '' ) ) )
			);
		}

		if ( empty( $query['code'] ) ) {
			return new WP_Error( 'cgm_authorize', __( 'Microsoft did not return an authorization code.', 'centaur-graph-mail' ) );
		}

		$tokens = self::token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => (string) $query['code'],
				'redirect_uri'  => self::redirect_uri(),
				'code_verifier' => Crypto::decrypt( (string) $saved['verifier'] ),
				'scope'         => implode( ' ', self::SCOPES ),
			)
		);
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		if ( empty( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'cgm_token', __( 'No refresh token was returned. Make sure offline_access is allowed for this app.', 'centaur-graph-mail' ) );
		}

		$granted = array_map( 'strtolower', explode( ' ', (string) ( $tokens['scope'] ?? '' ) ) );
		if ( ! in_array( 'https://graph.microsoft.com/mail.send', $granted, true ) && ! in_array( 'mail.send', $granted, true ) ) {
			return new WP_Error( 'cgm_scope', __( 'Mail.Send was not granted. Ask an administrator to allow user consent for Mail.Send or grant it for the organization.', 'centaur-graph-mail' ) );
		}

		self::cache_access_token( $tokens );

		$me = self::graph_get( '/me?$select=id,displayName,userPrincipalName,mail', $tokens['access_token'] );
		if ( is_wp_error( $me ) ) {
			return $me;
		}

		update_option(
			self::TOKEN_OPTION,
			array(
				'refresh_token' => Crypto::encrypt( $tokens['refresh_token'] ),
				'scope'         => (string) ( $tokens['scope'] ?? '' ),
				'account'       => array(
					'id'   => (string) ( $me['id'] ?? '' ),
					'name' => (string) ( $me['displayName'] ?? '' ),
					'upn'  => (string) ( $me['userPrincipalName'] ?? '' ),
					'mail' => (string) ( $me['mail'] ?? '' ),
				),
				'connected_at'  => time(),
				'connected_by'  => $user_id,
				'error'         => '',
			),
			false
		);

		if ( ! wp_next_scheduled( self::KEEPALIVE_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::KEEPALIVE_HOOK );
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Stored connection
	 * ------------------------------------------------------------------ */

	/** @return array|null The stored connection (without decrypted secrets), or null when never connected. */
	public static function connection(): ?array {
		$stored = get_option( self::TOKEN_OPTION );
		return is_array( $stored ) && ! empty( $stored['account'] ) ? $stored : null;
	}

	public static function is_connected(): bool {
		$connection = self::connection();
		return null !== $connection && '' !== Crypto::decrypt( (string) ( $connection['refresh_token'] ?? '' ) );
	}

	public static function disconnect(): void {
		delete_option( self::TOKEN_OPTION );
		delete_transient( self::ACCESS_TRANSIENT );
	}

	/** @return string|WP_Error A valid Graph access token for the connected user. */
	public static function access_token( bool $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = Crypto::decrypt( (string) get_transient( self::ACCESS_TRANSIENT ) );
			if ( '' !== $cached ) {
				return $cached;
			}
		}

		$connection = self::connection();
		$refresh    = $connection ? Crypto::decrypt( (string) ( $connection['refresh_token'] ?? '' ) ) : '';
		if ( '' === $refresh ) {
			return new WP_Error( 'cgm_not_connected', __( 'Centaur Graph Mail is not connected to a Microsoft account. Sign in on Settings → Graph Mail.', 'centaur-graph-mail' ) );
		}

		$tokens = self::token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'scope'         => implode( ' ', self::SCOPES ),
			)
		);

		if ( is_wp_error( $tokens ) ) {
			$data = $tokens->get_error_data();
			if ( is_array( $data ) && 'invalid_grant' === ( $data['error'] ?? '' ) ) {
				// The grant was revoked or expired: a new sign-in is required.
				$connection['refresh_token'] = '';
			}
			$connection['error'] = $tokens->get_error_message();
			update_option( self::TOKEN_OPTION, $connection, false );
			return $tokens;
		}

		// Entra rotates refresh tokens; always keep the newest.
		if ( ! empty( $tokens['refresh_token'] ) ) {
			$connection['refresh_token'] = Crypto::encrypt( $tokens['refresh_token'] );
		}
		$connection['scope'] = (string) ( $tokens['scope'] ?? $connection['scope'] ?? '' );
		$connection['error'] = '';
		update_option( self::TOKEN_OPTION, $connection, false );

		self::cache_access_token( $tokens );
		return $tokens['access_token'];
	}

	public static function keepalive(): void {
		if ( self::is_connected() && Settings::is_configured() ) {
			self::access_token( true );
		}
	}

	private static function cache_access_token( array $tokens ): void {
		$ttl = max( 60, (int) ( $tokens['expires_in'] ?? 3600 ) - 300 );
		set_transient( self::ACCESS_TRANSIENT, Crypto::encrypt( (string) $tokens['access_token'] ), $ttl );
	}

	/* ------------------------------------------------------------------ *
	 * Diagnostics
	 * ------------------------------------------------------------------ */

	/**
	 * Verifies tenant, application ID and secret with a client-credentials request.
	 * This does not use or need any application permissions; it only proves the secret is valid.
	 *
	 * @return true|WP_Error
	 */
	public static function test_app_credentials() {
		$tokens = self::token_request(
			array(
				'grant_type' => 'client_credentials',
				'scope'      => 'https://graph.microsoft.com/.default',
			)
		);
		return is_wp_error( $tokens ) ? $tokens : true;
	}

	/* ------------------------------------------------------------------ *
	 * HTTP helpers
	 * ------------------------------------------------------------------ */

	/** @return array|WP_Error Decoded token response. */
	private static function token_request( array $body ) {
		if ( ! Settings::is_configured() ) {
			return new WP_Error( 'cgm_not_configured', __( 'Tenant ID, Application ID, secret and sender must be configured first.', 'centaur-graph-mail' ) );
		}

		$response = wp_remote_post(
			self::authority() . '/token',
			array(
				'timeout' => 20,
				'body'    => array_merge(
					array(
						'client_id'     => Settings::client_id(),
						'client_secret' => Settings::client_secret(),
					),
					$body
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error = is_array( $data ) ? (string) ( $data['error'] ?? 'unknown_error' ) : 'invalid_response';
			$desc  = is_array( $data ) ? (string) ( $data['error_description'] ?? '' ) : wp_remote_retrieve_body( $response );
			// Keep only the first line; Entra appends trace and correlation IDs.
			$desc = strtok( $desc, "\r\n" );
			return new WP_Error( 'cgm_token', sprintf( '%s: %s', $error, $desc ), array( 'error' => $error ) );
		}

		return $data;
	}

	/** @return array|WP_Error */
	private static function graph_get( string $path, string $access_token ) {
		$response = wp_remote_get(
			'https://graph.microsoft.com/v1.0' . $path,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'cgm_graph', Mailer::graph_error_message( $response ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
