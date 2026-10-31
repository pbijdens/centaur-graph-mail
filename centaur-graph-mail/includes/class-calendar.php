<?php
namespace Centaur\GraphMail;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Outlook calendars of the connected account through Microsoft Graph (delegated Calendars.Read and
 * Calendars.Read.Shared) and turns them into a filtered list of appointments for the calendar view.
 */
final class Calendar {
	public const OPTION       = 'centaur_graph_mail_calendar';
	public const OPTION_GROUP = 'centaur-graph-mail-calendar';

	private const LAST_GOOD_OPTION = 'centaur_graph_mail_calendar_last';
	private const CACHE_PREFIX     = 'cgm_cal_';
	private const CACHE_VERSION    = 3; // Bump when normalize() output changes, so results cached in the old shape are fetched again.
	private const GRAPH            = 'https://graph.microsoft.com/v1.0';

	private const DEFAULTS = array(
		'mailboxes'     => array(), // Extra mailboxes whose calendars the account may read.
		'calendars'     => array(), // Selected calendars: list of array( mailbox, id, name ).
		'ignore'        => array(), // Regular expressions; matching appointments are hidden.
		'hide_private'  => true,
		'cache_minutes' => 15,
	);

	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	public static function is_ready(): bool {
		return Auth::is_connected() && Auth::has_scope( 'Calendars.Read' );
	}

	/** Sanitize callback for register_setting(). */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::DEFAULTS;

		foreach ( self::lines( $input['mailboxes'] ?? '' ) as $line ) {
			$email = strtolower( sanitize_email( $line ) );
			if ( is_email( $email ) && ! in_array( $email, $out['mailboxes'], true ) ) {
				$out['mailboxes'][] = $email;
			}
		}

		$seen = array();
		foreach ( (array) ( $input['calendars'] ?? array() ) as $raw ) {
			$calendar = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			if ( ! is_array( $calendar ) || empty( $calendar['id'] ) ) {
				continue;
			}
			$entry = array(
				'mailbox' => strtolower( sanitize_email( (string) ( $calendar['mailbox'] ?? '' ) ) ),
				'id'      => preg_replace( '/[^A-Za-z0-9_\-=+\/]/', '', (string) $calendar['id'] ),
				'name'    => sanitize_text_field( (string) ( $calendar['name'] ?? '' ) ),
			);
			$key = self::calendar_key( $entry );
			if ( '' !== $entry['id'] && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ]       = true;
				$out['calendars'][] = $entry;
			}
		}

		foreach ( self::lines( $input['ignore'] ?? '' ) as $pattern ) {
			$out['ignore'][] = $pattern;
			if ( null === self::compile( $pattern ) && function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION,
					'cgm_regex_' . md5( $pattern ),
					sprintf( /* translators: %s: regular expression */ __( 'Ignored appointment pattern "%s" is not a valid regular expression and has no effect.', 'centaur-graph-mail' ), $pattern )
				);
			}
		}

		$out['hide_private']  = ! empty( $input['hide_private'] );
		$out['cache_minutes'] = max( 1, min( 1440, (int) ( $input['cache_minutes'] ?? self::DEFAULTS['cache_minutes'] ) ) );

		return $out;
	}

	/** @return string[] Non-empty trimmed lines. */
	private static function lines( $value ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $value ) ), 'strlen' ) );
	}

	public static function calendar_key( array $calendar ): string {
		return ( $calendar['mailbox'] ?? '' ) . '|' . ( $calendar['id'] ?? '' );
	}

	/**
	 * Patterns are written without delimiters and match case-insensitively, e.g. "^private" or "\[intern\]".
	 *
	 * @return string|null The PCRE pattern, or null when it doesn't compile.
	 */
	private static function compile( string $pattern ): ?string {
		$regex = '~' . str_replace( '~', '\~', $pattern ) . '~iu';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid patterns are reported, not fatal.
		return false === @preg_match( $regex, '' ) ? null : $regex;
	}

	/* ------------------------------------------------------------------ *
	 * Calendar discovery (settings screen)
	 * ------------------------------------------------------------------ */

	/**
	 * Calendars of the connected account plus those of the configured extra mailboxes.
	 *
	 * @return array<string, array> Keyed by mailbox ('' for the signed-in account): list of calendars, or a WP_Error.
	 */
	public static function available_calendars(): array {
		$result = array();
		foreach ( array_merge( array( '' ), self::all()['mailboxes'] ) as $mailbox ) {
			$items = self::graph_get_all( self::mailbox_path( $mailbox ) . '/calendars?$select=id,name,owner,canEdit,isDefaultCalendar,hexColor&$top=100' );
			if ( is_wp_error( $items ) ) {
				$result[ $mailbox ] = $items;
				continue;
			}
			$result[ $mailbox ] = array_map(
				static function ( array $item ) use ( $mailbox ) {
					return array(
						'mailbox' => $mailbox,
						'id'      => (string) $item['id'],
						'name'    => (string) ( $item['name'] ?? '' ),
						'owner'   => (string) ( $item['owner']['address'] ?? '' ),
						'default' => ! empty( $item['isDefaultCalendar'] ),
						'color'   => (string) ( $item['hexColor'] ?? '' ),
					);
				},
				$items
			);
		}
		return $result;
	}

	private static function mailbox_path( string $mailbox ): string {
		return '' === $mailbox ? '/me' : '/users/' . rawurlencode( $mailbox );
	}

	/* ------------------------------------------------------------------ *
	 * Appointments
	 * ------------------------------------------------------------------ */

	/**
	 * The rendered period: today up to and including the last day of next month, in the site's time zone.
	 *
	 * @return DateTimeImmutable[] array( first day 00:00, first day after the period 00:00 )
	 */
	public static function period(): array {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		return array( $today, $today->modify( 'first day of next month' )->modify( '+1 month' ) );
	}

	/**
	 * Appointments in period(), with ignored, private and cancelled ones removed.
	 *
	 * @return array|WP_Error List of normalized appointments (see normalize()).
	 */
	public static function appointments() {
		$settings = self::all();
		if ( ! $settings['calendars'] ) {
			return new WP_Error( 'cgm_no_calendars', __( 'No calendars are selected on Settings → Graph Mail.', 'centaur-graph-mail' ) );
		}

		$events = self::fetch_cached( $settings );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$patterns = array_filter( array_map( array( self::class, 'compile' ), $settings['ignore'] ) );

		return array_values(
			array_filter(
				$events,
				static function ( array $event ) use ( $settings, $patterns ) {
					if ( $event['cancelled'] || ( $settings['hide_private'] && $event['private'] ) ) {
						return false;
					}
					$texts = array_merge( array( $event['subject'], $event['body'] ), $event['categories'] );
					foreach ( $patterns as $regex ) {
						foreach ( $texts as $text ) {
							if ( '' !== $text && preg_match( $regex, $text ) ) {
								return false;
							}
						}
					}
					return true;
				}
			)
		);
	}

	/** @return array|WP_Error */
	private static function fetch_cached( array $settings ) {
		list( $from, $until ) = self::period();
		$calendars_key        = md5( self::CACHE_VERSION . wp_json_encode( $settings['calendars'] ) );
		$cache_key            = self::CACHE_PREFIX . md5( $calendars_key . $from->format( 'Y-m-d' ) );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$events = self::fetch( $settings['calendars'], $from, $until );
		if ( ! is_wp_error( $events ) ) {
			set_transient( $cache_key, $events, $settings['cache_minutes'] * MINUTE_IN_SECONDS );
			update_option(
				self::LAST_GOOD_OPTION,
				array(
					'calendars' => $calendars_key,
					'events'    => $events,
					'time'      => time(),
				),
				false
			);
			return $events;
		}

		// Graph unavailable: keep showing the last successful result for the same calendars. Past days drop out at render time.
		$last = get_option( self::LAST_GOOD_OPTION );
		if ( is_array( $last ) && ( $last['calendars'] ?? '' ) === $calendars_key && time() - (int) $last['time'] < WEEK_IN_SECONDS ) {
			set_transient( $cache_key, $last['events'], 5 * MINUTE_IN_SECONDS );
			return $last['events'];
		}
		return $events;
	}

	/** @return array|WP_Error */
	private static function fetch( array $calendars, DateTimeImmutable $from, DateTimeImmutable $until ) {
		$utc   = new DateTimeZone( 'UTC' );
		$query = http_build_query(
			array(
				'startDateTime' => $from->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' ),
				'endDateTime'   => $until->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' ),
				'$select'       => 'iCalUId,subject,body,location,categories,start,end,isAllDay,isCancelled,sensitivity',
				'$orderby'      => 'start/dateTime',
				'$top'          => 250,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$events = array();
		foreach ( $calendars as $calendar ) {
			$items = self::graph_get_all(
				self::mailbox_path( $calendar['mailbox'] ) . '/calendars/' . rawurlencode( $calendar['id'] ) . '/calendarView?' . $query,
				array( 'Prefer' => 'outlook.timezone="UTC", outlook.body-content-type="text"' )
			);
			if ( is_wp_error( $items ) ) {
				return new WP_Error(
					$items->get_error_code(),
					sprintf( /* translators: 1: calendar name, 2: error */ __( 'Calendar "%1$s" could not be read: %2$s', 'centaur-graph-mail' ), $calendar['name'], $items->get_error_message() )
				);
			}
			foreach ( $items as $item ) {
				$event = self::normalize( $item );
				// The same meeting can appear in several selected calendars.
				$events[ $event['uid'] . '@' . $event['sort'] ] = $event;
			}
		}

		return array_values( $events );
	}

	/**
	 * Timed appointments carry UTC timestamps. All-day appointments are floating dates: Graph reports them
	 * as midnight-to-midnight, so only the date part is used (end date exclusive).
	 */
	private static function normalize( array $item ): array {
		$all_day = ! empty( $item['isAllDay'] );
		$start   = (string) ( $item['start']['dateTime'] ?? '' );
		$end     = (string) ( $item['end']['dateTime'] ?? $start );

		$body = str_replace( array( "\r\n", "\r", "\u{00A0}" ), array( "\n", "\n", ' ' ), (string) ( $item['body']['content'] ?? '' ) );
		$body = trim( preg_replace( array( '/[ \t]+\n/', '/\n{3,}/' ), array( "\n", "\n\n" ), $body ) );

		$event = array(
			'uid'        => (string) ( $item['iCalUId'] ?? $item['id'] ?? '' ),
			'subject'    => trim( (string) ( $item['subject'] ?? '' ) ),
			'body'       => $body,
			// Outlook stores place name and address on separate lines; show them as one.
			'location'   => trim( preg_replace( '/\s*\R\s*/u', ', ', trim( (string) ( $item['location']['displayName'] ?? '' ) ) ) ),
			'categories' => array_values( array_filter( array_map( 'trim', (array) ( $item['categories'] ?? array() ) ), 'strlen' ) ),
			'all_day'    => $all_day,
			'cancelled'  => ! empty( $item['isCancelled'] ),
			'private'    => in_array( $item['sensitivity'] ?? 'normal', array( 'private', 'confidential' ), true ),
		);

		if ( $all_day ) {
			$event['start_date'] = substr( $start, 0, 10 );
			$event['end_date']   = substr( $end, 0, 10 );
			$event['sort']       = $event['start_date'];
		} else {
			$utc            = new DateTimeZone( 'UTC' );
			$event['start'] = ( new DateTimeImmutable( substr( $start, 0, 19 ), $utc ) )->getTimestamp();
			$event['end']   = ( new DateTimeImmutable( substr( $end, 0, 19 ), $utc ) )->getTimestamp();
			$event['sort']  = (string) $event['start'];
		}
		return $event;
	}

	/* ------------------------------------------------------------------ *
	 * Graph
	 * ------------------------------------------------------------------ */

	/** @return array|WP_Error All "value" items, following @odata.nextLink. */
	private static function graph_get_all( string $path, array $headers = array() ) {
		$items = array();
		$url   = self::GRAPH . $path;
		for ( $page = 0; $url && $page < 20; $page++ ) {
			$data = self::graph_get( $url, $headers );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$items = array_merge( $items, (array) ( $data['value'] ?? array() ) );
			$url   = (string) ( $data['@odata.nextLink'] ?? '' );
		}
		return $items;
	}

	/** @return array|WP_Error */
	private static function graph_get( string $url, array $headers ) {
		if ( ! Auth::has_scope( 'Calendars.Read' ) ) {
			return new WP_Error( 'cgm_scope', __( 'Calendars.Read has not been granted. Reconnect with Microsoft on Settings → Graph Mail.', 'centaur-graph-mail' ) );
		}

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$token = Auth::access_token( $attempt > 0 );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 20,
					'headers' => array_merge( $headers, array( 'Authorization' => 'Bearer ' . $token ) ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 === $code ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				return is_array( $data ) ? $data : array();
			}
			// A cached token may predate a newly granted scope; retry once with a fresh one.
			if ( 401 !== $code ) {
				break;
			}
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return new WP_Error(
			'cgm_graph',
			sprintf(
				'Microsoft Graph HTTP %d %s: %s',
				$code,
				is_array( $data ) ? (string) ( $data['error']['code'] ?? '' ) : '',
				is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : ''
			)
		);
	}
}
