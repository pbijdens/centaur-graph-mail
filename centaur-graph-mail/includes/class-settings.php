<?php
namespace Centaur\GraphMail;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin configuration. Every value can be pinned in wp-config.php with a constant, e.g.
 * define( 'CENTAUR_GRAPH_MAIL_CLIENT_SECRET', '...' ); — constants win over the settings screen.
 */
final class Settings {
	public const OPTION = 'centaur_graph_mail_settings';

	private const CONSTANTS = array(
		'tenant_id'     => 'CENTAUR_GRAPH_MAIL_TENANT_ID',
		'client_id'     => 'CENTAUR_GRAPH_MAIL_CLIENT_ID',
		'client_secret' => 'CENTAUR_GRAPH_MAIL_CLIENT_SECRET',
		'sender'        => 'CENTAUR_GRAPH_MAIL_SENDER',
	);

	private const DEFAULTS = array(
		'tenant_id'          => '',
		'client_id'          => '',
		'client_secret'      => '', // Stored encrypted.
		'sender'             => '',
		'save_to_sent_items' => true,
		'fallback'           => false,
	);

	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	public static function get( string $key ) {
		if ( isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] ) ) {
			return (string) constant( self::CONSTANTS[ $key ] );
		}
		$value = self::all()[ $key ] ?? null;
		return 'client_secret' === $key ? Crypto::decrypt( (string) $value ) : $value;
	}

	public static function is_constant( string $key ): bool {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	public static function tenant_id(): string {
		return trim( (string) self::get( 'tenant_id' ) );
	}

	public static function client_id(): string {
		return trim( (string) self::get( 'client_id' ) );
	}

	public static function client_secret(): string {
		return (string) self::get( 'client_secret' );
	}

	public static function sender(): string {
		return strtolower( trim( (string) self::get( 'sender' ) ) );
	}

	public static function save_to_sent_items(): bool {
		return (bool) self::get( 'save_to_sent_items' );
	}

	public static function fallback(): bool {
		return (bool) self::get( 'fallback' );
	}

	public static function is_configured(): bool {
		return '' !== self::tenant_id() && '' !== self::client_id() && '' !== self::client_secret() && '' !== self::sender();
	}

	/** Sanitize callback for register_setting(). A blank secret keeps the existing one. */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::all();
		$out     = $current;

		$out['tenant_id']          = sanitize_text_field( $input['tenant_id'] ?? '' );
		$out['client_id']          = sanitize_text_field( $input['client_id'] ?? '' );
		$out['sender']             = sanitize_email( $input['sender'] ?? '' );
		$out['save_to_sent_items'] = ! empty( $input['save_to_sent_items'] );
		$out['fallback']           = ! empty( $input['fallback'] );

		$secret = trim( (string) ( $input['client_secret'] ?? '' ) );
		if ( '' !== Crypto::decrypt( $secret ) ) {
			// Already encrypted: WordPress runs this callback twice when the option is first created.
			$out['client_secret'] = $secret;
		} elseif ( '' !== $secret ) {
			$out['client_secret'] = Crypto::encrypt( $secret );
		}

		// Changing the app or tenant invalidates the stored delegated token.
		if ( $out['tenant_id'] !== $current['tenant_id'] || $out['client_id'] !== $current['client_id'] ) {
			Auth::disconnect();
		}

		return $out;
	}
}
