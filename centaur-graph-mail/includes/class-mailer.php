<?php
namespace Centaur\GraphMail;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Translates a wp_mail() call into a Microsoft Graph sendMail request.
 */
final class Mailer {
	/** Graph rejects sendMail requests over 4 MB; larger attachments need an upload session (Mail.ReadWrite). */
	private const MAX_REQUEST_BYTES = 4 * 1024 * 1024 - 64 * 1024;

	/**
	 * 'pre_wp_mail' filter. Returning a bool short-circuits core's PHPMailer; null lets core handle the mail.
	 *
	 * @param null|bool $return Short-circuit value from an earlier filter.
	 * @param array     $atts   wp_mail() arguments, after the 'wp_mail' filter.
	 * @return null|bool
	 */
	public static function pre_wp_mail( $return, $atts ) {
		if ( null !== $return ) {
			return $return;
		}

		if ( ! Settings::is_configured() || ! Auth::is_connected() ) {
			if ( Settings::fallback() ) {
				return null;
			}
			return self::fail(
				new WP_Error( 'cgm_not_connected', __( 'Centaur Graph Mail is not configured or not connected, and fallback to PHP mail is disabled.', 'centaur-graph-mail' ) ),
				self::mail_data( (array) $atts )
			);
		}

		return self::send( (array) $atts );
	}

	/** Sends a message through Graph and fires wp_mail_succeeded / wp_mail_failed like core does. */
	public static function send( array $atts ): bool {
		$mail_data = self::mail_data( $atts );

		try {
			$payload = self::build_payload( $atts );
			if ( is_wp_error( $payload ) ) {
				return self::fail( $payload, $mail_data );
			}

			$result = self::post_send_mail( $payload );
			if ( is_wp_error( $result ) ) {
				return self::fail( $result, $mail_data );
			}
		} catch ( \Throwable $e ) {
			return self::fail( new WP_Error( 'cgm_exception', $e->getMessage() ), $mail_data );
		}

		update_option( 'centaur_graph_mail_last_error', '', false );

		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_succeeded', $mail_data );
		return true;
	}

	private static function fail( WP_Error $error, array $mail_data ): bool {
		update_option(
			'centaur_graph_mail_last_error',
			array(
				'time'    => time(),
				'message' => $error->get_error_message(),
				'subject' => (string) ( $mail_data['subject'] ?? '' ),
			),
			false
		);

		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $error->get_error_message(), $mail_data ) );
		return false;
	}

	/* ------------------------------------------------------------------ *
	 * Building the Graph message
	 * ------------------------------------------------------------------ */

	/** @return array|WP_Error */
	public static function build_payload( array $atts ) {
		$headers = self::parse_headers( $atts['headers'] ?? array() );

		// Split strings with the quote-aware parser rather than core's naive explode( ',' ).
		$to = array();
		foreach ( (array) ( $atts['to'] ?? array() ) as $entry ) {
			$to = array_merge( $to, self::split_addresses( (string) $entry ) );
		}

		$recipients = array(
			'toRecipients'  => self::recipients( $to ),
			'ccRecipients'  => self::recipients( $headers['cc'] ),
			'bccRecipients' => self::recipients( $headers['bcc'] ),
		);
		if ( ! array_filter( $recipients ) ) {
			return new WP_Error( 'cgm_no_recipients', __( 'The message has no valid recipients.', 'centaur-graph-mail' ) );
		}

		// Keep core's filters working even though Graph always sends as the configured mailbox.
		$header_from = $headers['from'];
		$from_email  = apply_filters( 'wp_mail_from', $header_from['email'] ?? self::default_from_email() );
		$from_name   = apply_filters( 'wp_mail_from_name', $header_from['name'] ?? 'WordPress' );

		$content_type = apply_filters( 'wp_mail_content_type', $headers['content_type'] ?: 'text/plain' );
		$charset      = apply_filters( 'wp_mail_charset', $headers['charset'] ?: get_bloginfo( 'charset' ) );

		$subject = (string) ( $atts['subject'] ?? '' );
		$body    = (string) ( $atts['message'] ?? '' );
		if ( $charset && 'UTF-8' !== strtoupper( $charset ) && function_exists( 'mb_convert_encoding' ) ) {
			$subject = mb_convert_encoding( $subject, 'UTF-8', $charset );
			$body    = mb_convert_encoding( $body, 'UTF-8', $charset );
		}

		$sender  = Settings::sender();
		$message = array(
			'subject' => $subject,
			'body'    => array(
				'contentType' => false !== stripos( (string) $content_type, 'html' ) ? 'HTML' : 'Text',
				'content'     => $body,
			),
			'from'    => array( 'emailAddress' => array( 'address' => $sender ) ),
		);

		$foreign_from = $header_from && is_email( $from_email ) && strtolower( $from_email ) !== $sender;

		// Only pass a display name the caller asked for; otherwise Exchange uses the mailbox's own name.
		if ( ! $foreign_from && 'WordPress' !== $from_name && '' !== trim( (string) $from_name ) ) {
			$message['from']['emailAddress']['name'] = (string) $from_name;
		}

		$message = array_merge( $message, array_filter( $recipients ) );

		$reply_to = self::recipients( $headers['reply_to'] );
		if ( ! $reply_to && $foreign_from ) {
			// A From the mailbox cannot send as (e.g. a contact form visitor) becomes the reply address.
			$reply_to = self::recipients(
				array(
					array(
						'email' => $from_email,
						'name'  => (string) $from_name,
					),
				)
			);
		}
		if ( $reply_to ) {
			$message['replyTo'] = $reply_to;
		}

		if ( $headers['importance'] ) {
			$message['importance'] = $headers['importance'];
		}

		if ( $headers['custom'] ) {
			$message['internetMessageHeaders'] = $headers['custom'];
		}

		$attachments = array_merge(
			self::file_attachments( $atts['attachments'] ?? array() ),
			self::embedded_attachments( $atts['embeds'] ?? array() )
		);
		if ( $attachments ) {
			$message['attachments'] = $attachments;
		}

		$payload = array(
			'message'         => $message,
			'saveToSentItems' => Settings::save_to_sent_items(),
		);

		/**
		 * Filters the Graph sendMail request body before it is sent.
		 *
		 * @param array $payload Request body for POST /users/{sender}/sendMail.
		 * @param array $atts    The wp_mail() arguments.
		 */
		return apply_filters( 'centaur_graph_mail_payload', $payload, $atts );
	}

	/**
	 * Parses wp_mail() headers the same way core does.
	 *
	 * @param string|string[] $raw
	 */
	public static function parse_headers( $raw ): array {
		$parsed = array(
			'from'         => null,
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
			'content_type' => '',
			'charset'      => '',
			'importance'   => '',
			'custom'       => array(),
		);

		foreach ( self::to_list( $raw, "\n" ) as $header ) {
			if ( ! str_contains( $header, ':' ) ) {
				continue;
			}
			[ $name, $value ] = array_map( 'trim', explode( ':', $header, 2 ) );
			if ( '' === $value ) {
				continue;
			}

			switch ( strtolower( $name ) ) {
				case 'from':
					$from           = self::split_addresses( $value );
					$parsed['from'] = $from[0] ?? null;
					break;

				case 'content-type':
					$parts                  = array_map( 'trim', explode( ';', $value ) );
					$parsed['content_type'] = array_shift( $parts );
					foreach ( $parts as $param ) {
						if ( 0 === stripos( $param, 'charset=' ) ) {
							$parsed['charset'] = trim( substr( $param, 8 ), '"\'' );
						}
					}
					break;

				case 'cc':
				case 'bcc':
				case 'reply-to':
					$key            = str_replace( '-', '_', strtolower( $name ) );
					$parsed[ $key ] = array_merge( $parsed[ $key ], self::split_addresses( $value ) );
					break;

				case 'importance':
				case 'priority':
					$value = strtolower( $value );
					if ( str_contains( $value, 'high' ) || 'urgent' === $value ) {
						$parsed['importance'] = 'high';
					} elseif ( str_contains( $value, 'low' ) || 'non-urgent' === $value ) {
						$parsed['importance'] = 'low';
					}
					break;

				case 'x-priority':
					$level                = (int) $value;
					$parsed['importance'] = $level > 0 && $level < 3 ? 'high' : ( $level > 3 ? 'low' : 'normal' );
					break;

				default:
					// Graph only accepts custom headers whose names start with "x-".
					if ( 0 === stripos( $name, 'x-' ) && preg_match( '/^[\x21-\x39\x3B-\x7E]+$/', $name ) ) {
						$parsed['custom'][] = array(
							'name'  => $name,
							'value' => $value,
						);
					}
			}
		}

		return $parsed;
	}

	/**
	 * Splits "A <a@x>, \"Doe, Jane\" <j@x>, b@x" into address/name pairs, respecting quotes and angle brackets.
	 *
	 * @return array<int, array{email: string, name: string}>
	 */
	public static function split_addresses( string $value ): array {
		$parts  = array();
		$buffer = '';
		$quoted = false;
		$angle  = false;
		$length = strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];
			if ( '"' === $char ) {
				$quoted = ! $quoted;
			} elseif ( '<' === $char && ! $quoted ) {
				$angle = true;
			} elseif ( '>' === $char && ! $quoted ) {
				$angle = false;
			} elseif ( ( ',' === $char || ';' === $char ) && ! $quoted && ! $angle ) {
				$parts[] = $buffer;
				$buffer  = '';
				continue;
			}
			$buffer .= $char;
		}
		$parts[] = $buffer;

		$addresses = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match( '/^(.*)<([^>]+)>\s*$/s', $part, $m ) ) {
				$addresses[] = array(
					'email' => trim( $m[2] ),
					'name'  => trim( trim( $m[1] ), '"\' ' ),
				);
			} else {
				$addresses[] = array(
					'email' => $part,
					'name'  => '',
				);
			}
		}
		return $addresses;
	}

	private static function recipients( array $addresses ): array {
		$out  = array();
		$seen = array();
		foreach ( $addresses as $address ) {
			$email = trim( (string) $address['email'] );
			if ( ! is_email( $email ) || isset( $seen[ strtolower( $email ) ] ) ) {
				continue;
			}
			$seen[ strtolower( $email ) ] = true;

			$entry = array( 'address' => $email );
			if ( '' !== $address['name'] ) {
				$entry['name'] = $address['name'];
			}
			$out[] = array( 'emailAddress' => $entry );
		}
		return $out;
	}

	private static function file_attachments( $attachments ): array {
		$out = array();
		foreach ( self::to_list( $attachments, "\n", true ) as $key => $path ) {
			$name = is_string( $key ) && '' !== $key ? $key : basename( $path );
			$item = self::file_attachment( $path, $name );
			if ( $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	private static function embedded_attachments( $embeds ): array {
		$out = array();
		foreach ( self::to_list( $embeds, "\n", true ) as $key => $path ) {
			/** This filter is documented in wp-includes/pluggable.php */
			$args = apply_filters(
				'wp_mail_embed_args',
				array(
					'path'        => $path,
					'cid'         => (string) $key,
					'name'        => basename( $path ),
					'encoding'    => 'base64',
					'type'        => '',
					'disposition' => 'inline',
				)
			);

			$item = self::file_attachment( (string) $args['path'], (string) $args['name'], (string) $args['type'] );
			if ( $item ) {
				$item['contentId'] = (string) $args['cid'];
				$item['isInline']  = 'inline' === $args['disposition'];
				$out[]             = $item;
			}
		}
		return $out;
	}

	/** Like core's PHPMailer handling, unreadable files are skipped rather than failing the whole message. */
	private static function file_attachment( string $path, string $name, string $type = '' ): ?array {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return null;
		}

		if ( '' === $type ) {
			$type = wp_check_filetype( $name )['type'] ?: ( function_exists( 'mime_content_type' ) ? (string) mime_content_type( $path ) : '' );
		}

		return array(
			'@odata.type'  => '#microsoft.graph.fileAttachment',
			'name'         => $name,
			'contentType'  => $type ?: 'application/octet-stream',
			'contentBytes' => base64_encode( $contents ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Sending
	 * ------------------------------------------------------------------ */

	/** @return true|WP_Error */
	private static function post_send_mail( array $payload ) {
		$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $body ) {
			return new WP_Error( 'cgm_json', __( 'The message could not be encoded as JSON.', 'centaur-graph-mail' ) );
		}
		if ( strlen( $body ) > self::MAX_REQUEST_BYTES ) {
			return new WP_Error(
				'cgm_too_large',
				sprintf(
					/* translators: %s: size limit */
					__( 'The message including attachments exceeds the Microsoft Graph sendMail limit of about %s.', 'centaur-graph-mail' ),
					size_format( self::MAX_REQUEST_BYTES * 3 / 4 )
				)
			);
		}

		$url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode( Settings::sender() ) . '/sendMail';

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$token = Auth::access_token( $attempt > 0 );
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => $body,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 202 === $code || 200 === $code ) {
				return true;
			}
			// A cached token may have been revoked or lost a scope; retry once with a fresh one.
			if ( 401 !== $code ) {
				break;
			}
		}

		return new WP_Error( 'cgm_graph', self::graph_error_message( $response ) );
	}

	public static function graph_error_message( $response ): string {
		$code  = (int) wp_remote_retrieve_response_code( $response );
		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$error = is_array( $data ) ? (string) ( $data['error']['code'] ?? '' ) : '';
		$text  = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';

		$message = sprintf( 'Microsoft Graph HTTP %d%s%s', $code, $error ? " $error" : '', $text ? ": $text" : '' );

		if ( 'ErrorSendAsDenied' === $error || ( 403 === $code && str_contains( strtolower( $error . $text ), 'access' ) ) ) {
			$account  = Auth::connection()['account']['upn'] ?? '';
			$message .= ' ' . sprintf(
				/* translators: 1: connected account, 2: sender mailbox */
				__( '— check that %1$s has Send As (or Full Access) rights on %2$s in Exchange Online.', 'centaur-graph-mail' ),
				$account ?: __( 'the connected account', 'centaur-graph-mail' ),
				Settings::sender()
			);
		}
		return $message;
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Normalizes wp_mail()'s "string or array" arguments into a trimmed, non-empty list.
	 *
	 * @param string|array $value
	 */
	private static function to_list( $value, string $separator, bool $keep_keys = false ): array {
		if ( empty( $value ) ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			$value = explode( $separator, str_replace( "\r\n", "\n", (string) $value ) );
		}
		$value = array_filter( array_map( static fn( $v ) => trim( (string) $v ), $value ), 'strlen' );
		return $keep_keys ? $value : array_values( $value );
	}

	private static function default_from_email(): string {
		$host = wp_parse_url( network_home_url(), PHP_URL_HOST );
		return 'wordpress@' . ( $host ? preg_replace( '/^www\./', '', $host ) : '' );
	}

	/** Mirrors the $mail_data array core passes to wp_mail_succeeded / wp_mail_failed. */
	private static function mail_data( array $atts ): array {
		$to = $atts['to'] ?? array();
		return array(
			'to'          => is_array( $to ) ? $to : explode( ',', (string) $to ),
			'subject'     => $atts['subject'] ?? '',
			'message'     => $atts['message'] ?? '',
			'headers'     => $atts['headers'] ?? array(),
			'attachments' => $atts['attachments'] ?? array(),
			'embeds'      => $atts['embeds'] ?? array(),
		);
	}
}
