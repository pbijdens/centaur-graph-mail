<?php
namespace Centaur\GraphMail;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end output of the Outlook calendar: the [centaur_calendar] shortcode and the centaur/outlook-calendar block.
 *
 * Renders month grids for this month and the next, followed by a list of the days in that period
 * (from today) that have appointments.
 */
final class Calendar_View {
	public const SHORTCODE    = 'centaur_calendar';
	public const BLOCK        = 'centaur/outlook-calendar';
	public const STYLE_HANDLE = 'centaur-graph-mail-calendar';

	/** 16 distinct marker colors; a category always gets the same one (see category_color()). */
	private const COLORS = array(
		'#e6194b', '#3cb44b', '#ffe119', '#4363d8', '#f58231', '#911eb4', '#42d4f4', '#f032e6',
		'#bfef45', '#fabed4', '#469990', '#dcbeff', '#9a6324', '#800000', '#808000', '#000075',
	);

	private static int $instance = 0;

	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		wp_register_style( self::STYLE_HANDLE, plugins_url( 'assets/calendar.css', CENTAUR_GRAPH_MAIL_FILE ), array(), CENTAUR_GRAPH_MAIL_VERSION );

		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );

		register_block_type(
			CENTAUR_GRAPH_MAIL_DIR . 'blocks/outlook-calendar',
			array( 'render_callback' => array( self::class, 'render_block' ) )
		);
	}

	/** [centaur_calendar months="no" description="no" location="no" categories="no"] hides those parts. */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'months'      => 'yes',
				'description' => 'yes',
				'location'    => 'yes',
				'categories'  => 'yes',
			),
			$atts,
			self::SHORTCODE
		);
		$on   = static fn( $value ) => ! in_array( strtolower( (string) $value ), array( 'no', 'false', '0', 'off' ), true );
		wp_enqueue_style( self::STYLE_HANDLE );
		return self::render(
			array(
				'show_months'      => $on( $atts['months'] ),
				'show_description' => $on( $atts['description'] ),
				'show_location'    => $on( $atts['location'] ),
				'show_categories'  => $on( $atts['categories'] ),
			)
		);
	}

	public static function render_block( array $attributes ): string {
		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes(),
			self::render(
				array(
					'show_months'      => $attributes['showMonths'] ?? true,
					'show_description' => $attributes['showDescription'] ?? true,
					'show_location'    => $attributes['showLocation'] ?? true,
					'show_categories'  => $attributes['showCategories'] ?? true,
				)
			)
		);
	}

	public static function category_color( string $category ): string {
		$colors = apply_filters( 'centaur_graph_mail_category_colors', self::COLORS );
		return $colors[ crc32( mb_strtolower( trim( $category ) ) ) % count( $colors ) ];
	}

	/**
	 * Black or white, whichever contrasts more with a #rgb / #rrggbb background (WCAG relative luminance).
	 *
	 * @return string Empty when the color isn't a hex value; the stylesheet then falls back to the theme's text color.
	 */
	public static function text_color( string $background ): string {
		$hex = ltrim( trim( $background ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return '';
		}
		$channel   = static function ( string $pair ): float {
			$c = hexdec( $pair ) / 255;
			return $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		};
		$luminance = 0.2126 * $channel( substr( $hex, 0, 2 ) ) + 0.7152 * $channel( substr( $hex, 2, 2 ) ) + 0.0722 * $channel( substr( $hex, 4, 2 ) );
		// Contrast with black is (L + 0.05) / 0.05, with white 1.05 / (L + 0.05); they are equal at L ≈ 0.179.
		return $luminance > 0.179 ? '#000' : '#fff';
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */

	public static function render( array $args ): string {
		$editor = current_user_can( 'edit_posts' );

		if ( ! Calendar::is_ready() ) {
			return $editor ? self::notice( __( 'The Outlook calendar is not available: sign in with Microsoft and grant the calendar permissions on Settings → Graph Mail.', 'centaur-graph-mail' ) ) : '';
		}

		$appointments = Calendar::appointments();
		if ( is_wp_error( $appointments ) ) {
			return self::notice( $editor ? $appointments->get_error_message() : __( 'The calendar is temporarily unavailable.', 'centaur-graph-mail' ) );
		}

		return self::render_appointments( $appointments, $args );
	}

	private static function render_appointments( array $appointments, array $args ): string {
		++self::$instance;
		$days = self::group_by_day( $appointments );

		$html = '<div class="cgm-cal">';
		if ( $args['show_months'] ) {
			$html .= self::render_months( $days, $appointments );
		}
		$html .= '<div class="cgm-cal__list">';
		if ( ! $days ) {
			$html .= '<p class="cgm-cal__empty">' . esc_html__( 'No upcoming appointments.', 'centaur-graph-mail' ) . '</p>';
		}
		foreach ( $days as $date => $entries ) {
			$html .= self::render_day( $date, $entries, $args );
		}
		$html .= '</div></div>';

		return $html;
	}

	private static function notice( string $message ): string {
		return '<div class="cgm-cal cgm-cal--notice"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function day_anchor( string $date ): string {
		return 'cgm-day-' . self::$instance . '-' . $date;
	}

	/**
	 * Places every appointment on each day of the period it covers, all-day appointments first.
	 *
	 * @return array<string, array> 'Y-m-d' => list of array( event, day, days, continues_before, continues_after ), in date order.
	 */
	private static function group_by_day( array $appointments ): array {
		$tz                   = wp_timezone();
		list( $from, $until ) = Calendar::period();
		$days                 = array();

		foreach ( $appointments as $event ) {
			if ( $event['all_day'] ) {
				$first = new DateTimeImmutable( $event['start_date'], $tz );
				$last  = ( new DateTimeImmutable( $event['end_date'], $tz ) )->modify( '-1 day' );
			} else {
				$first = ( new DateTimeImmutable( '@' . $event['start'] ) )->setTimezone( $tz )->setTime( 0, 0 );
				// An appointment ending at midnight doesn't occupy the next day.
				$last = ( new DateTimeImmutable( '@' . max( $event['start'], $event['end'] - 1 ) ) )->setTimezone( $tz )->setTime( 0, 0 );
			}
			if ( $last < $first ) {
				$last = $first;
			}

			$count = (int) $first->diff( $last )->days + 1;
			$day   = $first;
			for ( $i = 1; $i <= $count && $day < $until; $i++, $day = $day->modify( '+1 day' ) ) {
				if ( $day < $from ) {
					continue;
				}
				$days[ $day->format( 'Y-m-d' ) ][] = array(
					'event'  => $event,
					'day'    => $i,
					'days'   => $count,
					'before' => $i > 1,
					'after'  => $i < $count,
				);
			}
		}

		ksort( $days );
		foreach ( $days as &$entries ) {
			usort(
				$entries,
				static function ( array $a, array $b ) {
					$ea = $a['event'];
					$eb = $b['event'];
					if ( $ea['all_day'] !== $eb['all_day'] ) {
						return $ea['all_day'] ? -1 : 1;
					}
					if ( $ea['all_day'] ) {
						return strcasecmp( $ea['subject'], $eb['subject'] );
					}
					// Appointments continuing from a previous day start at midnight.
					return array( $a['before'] ? 0 : $ea['start'], $ea['end'] ) <=> array( $b['before'] ? 0 : $eb['start'], $eb['end'] );
				}
			);
		}
		unset( $entries );

		return $days;
	}

	private static function render_day( string $date, array $entries, array $args ): string {
		$tz    = wp_timezone();
		$day   = new DateTimeImmutable( $date, $tz );
		$today = new DateTimeImmutable( 'today', $tz );

		$badge = '';
		if ( $day == $today ) { // phpcs:ignore Universal.Operators.StrictComparisons -- compares DateTime values.
			$badge = __( 'Today', 'centaur-graph-mail' );
		} elseif ( $day == $today->modify( '+1 day' ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
			$badge = __( 'Tomorrow', 'centaur-graph-mail' );
		}

		$html  = sprintf( '<section class="cgm-day" id="%s">', esc_attr( self::day_anchor( $date ) ) );
		$html .= sprintf(
			'<h3 class="cgm-day__heading"><time datetime="%s">%s</time>%s</h3>',
			esc_attr( $date ),
			esc_html( wp_date( _x( 'l j F', 'calendar day heading', 'centaur-graph-mail' ), $day->getTimestamp(), $tz ) ),
			$badge ? ' <span class="cgm-day__badge">' . esc_html( $badge ) . '</span>' : ''
		);
		$html .= '<ul class="cgm-day__events">';
		foreach ( $entries as $entry ) {
			$html .= self::render_event( $entry, $args );
		}
		return $html . '</ul></section>';
	}

	private static function render_event( array $entry, array $args ): string {
		$event       = $entry['event'];
		$time_format = get_option( 'time_format' );

		$marks = '';
		foreach ( $event['categories'] as $category ) {
			// With tags shown the category is already read out there.
			$marks .= sprintf(
				'<span class="cgm-mark" style="--cgm-color:%s" title="%s">%s</span>',
				esc_attr( self::category_color( $category ) ),
				esc_attr( $category ),
				$args['show_categories'] ? '' : '<span class="screen-reader-text">' . esc_html( $category ) . '</span>'
			);
		}

		if ( $event['all_day'] ) {
			$time = '<span class="cgm-event__allday">' . esc_html__( 'All day', 'centaur-graph-mail' ) . '</span>';
			if ( $entry['days'] > 1 ) {
				$time .= '<span class="cgm-event__span">' . esc_html( sprintf( /* translators: 1: day number, 2: total days */ __( 'day %1$d of %2$d', 'centaur-graph-mail' ), $entry['day'], $entry['days'] ) ) . '</span>';
			}
		} else {
			$start = $entry['before'] ? '…' : wp_date( $time_format, $event['start'] );
			$end   = $entry['after'] ? '…' : wp_date( $time_format, $event['end'] );
			$time  = sprintf(
				'<span class="cgm-event__start">%s</span><span class="cgm-event__sep" aria-hidden="true"> – </span><span class="screen-reader-text"> %s </span><span class="cgm-event__end">%s</span>',
				esc_html( $start ),
				esc_html__( 'to', 'centaur-graph-mail' ),
				esc_html( $end )
			);
		}

		$details  = '';
		$location = $event['location'] ?? ''; // Missing in results cached before location was fetched.
		if ( $args['show_location'] && '' !== $location ) {
			$details .= sprintf(
				'<div class="cgm-event__location"><span class="screen-reader-text">%s </span>%s</div>',
				esc_html__( 'Location:', 'centaur-graph-mail' ),
				esc_html( $location )
			);
		}
		if ( $args['show_categories'] && $event['categories'] ) {
			$details .= '<ul class="cgm-event__tags" aria-label="' . esc_attr__( 'Categories', 'centaur-graph-mail' ) . '">';
			foreach ( $event['categories'] as $category ) {
				$color    = self::category_color( $category );
				$text     = self::text_color( $color );
				$details .= sprintf(
					'<li class="cgm-tag" style="--cgm-color:%s%s">%s</li>',
					esc_attr( $color ),
					$text ? esc_attr( ';--cgm-tag-text:' . $text ) : '',
					esc_html( $category )
				);
			}
			$details .= '</ul>';
		}
		if ( $args['show_description'] && '' !== $event['body'] ) {
			$details .= '<div class="cgm-event__desc">' . nl2br( esc_html( $event['body'] ) ) . '</div>';
		}

		return sprintf(
			'<li class="cgm-event%s"><div class="cgm-event__marks">%s</div><div class="cgm-event__time">%s</div><div class="cgm-event__main"><div class="cgm-event__title">%s</div>%s</div></li>',
			$event['all_day'] ? ' cgm-event--allday' : '',
			$marks,
			$time,
			esc_html( '' !== $event['subject'] ? $event['subject'] : __( '(no title)', 'centaur-graph-mail' ) ),
			$details
		);
	}

	/** Month grids for this month and next. Past days are dimmed; days with appointments link to the list. */
	private static function render_months( array $days, array $appointments ): string {
		global $wp_locale;

		$tz         = wp_timezone();
		$today      = new DateTimeImmutable( 'today', $tz );
		$week_start = (int) get_option( 'start_of_week', 1 );

		$html = '<div class="cgm-cal__months">';
		for ( $m = 0; $m < 2; $m++ ) {
			$first = $today->modify( 'first day of this month' )->modify( "+$m month" );

			$html .= '<div class="cgm-month">';
			$html .= '<div class="cgm-month__title">' . esc_html( wp_date( _x( 'F Y', 'calendar month title', 'centaur-graph-mail' ), $first->getTimestamp(), $tz ) ) . '</div>';
			$html .= '<table class="cgm-month__grid"><thead><tr>';
			for ( $i = 0; $i < 7; $i++ ) {
				$weekday = $wp_locale->get_weekday( ( $week_start + $i ) % 7 );
				$html   .= sprintf( '<th scope="col" abbr="%s">%s</th>', esc_attr( $weekday ), esc_html( $wp_locale->get_weekday_initial( $weekday ) ) );
			}
			$html .= '</tr></thead><tbody><tr>';

			$offset = ( (int) $first->format( 'w' ) - $week_start + 7 ) % 7;
			$html  .= str_repeat( '<td class="cgm-month__pad"></td>', $offset );

			$length = (int) $first->format( 't' );
			for ( $d = 1; $d <= $length; $d++ ) {
				$day  = $first->modify( '+' . ( $d - 1 ) . ' days' );
				$date = $day->format( 'Y-m-d' );

				$classes = array( 'cgm-month__day' );
				if ( $day < $today ) {
					$classes[] = 'is-past';
				} elseif ( $date === $today->format( 'Y-m-d' ) ) {
					$classes[] = 'is-today';
				}

				if ( isset( $days[ $date ] ) ) {
					$classes[] = 'has-events';
					$label     = sprintf(
						/* translators: 1: date, 2: number of appointments */
						_n( '%1$s, %2$d appointment', '%1$s, %2$d appointments', count( $days[ $date ] ), 'centaur-graph-mail' ),
						wp_date( get_option( 'date_format' ), $day->getTimestamp(), $tz ),
						count( $days[ $date ] )
					);
					$cell = sprintf( '<a href="#%s" aria-label="%s">%d</a>', esc_attr( self::day_anchor( $date ) ), esc_attr( $label ), $d );
				} else {
					$cell = '<span>' . $d . '</span>';
				}

				$html .= sprintf( '<td class="%s">%s</td>', esc_attr( implode( ' ', $classes ) ), $cell );
				if ( 0 === ( $offset + $d ) % 7 && $d < $length ) {
					$html .= '</tr><tr>';
				}
			}
			$html .= str_repeat( '<td class="cgm-month__pad"></td>', ( 7 - ( $offset + $length ) % 7 ) % 7 );
			$html .= '</tr></tbody></table></div>';
		}

		$html .= self::render_legend( $appointments );
		return $html . '</div>';
	}

	private static function render_legend( array $appointments ): string {
		$categories = array();
		foreach ( $appointments as $event ) {
			foreach ( $event['categories'] as $category ) {
				$categories[ mb_strtolower( $category ) ] = $category;
			}
		}
		if ( ! $categories ) {
			return '';
		}
		natcasesort( $categories );

		$html = '<ul class="cgm-legend" aria-label="' . esc_attr__( 'Categories', 'centaur-graph-mail' ) . '">';
		foreach ( $categories as $category ) {
			$html .= sprintf(
				'<li><span class="cgm-mark" style="--cgm-color:%s" aria-hidden="true"></span>%s</li>',
				esc_attr( self::category_color( $category ) ),
				esc_html( $category )
			);
		}
		return $html . '</ul>';
	}
}
