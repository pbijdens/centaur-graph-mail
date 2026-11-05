<?php
/**
 * Plugin Name:       Centaur Graph Mail
 * Description:       Sends all WordPress e-mail through Microsoft Graph (Exchange Online) as a configured mailbox, using delegated Mail.Send consent from a signed-in Entra ID user, and shows Outlook calendars with a block or the [centaur_calendar] shortcode.
 * Version:           1.2.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Pieter-Bas IJdens
 * License:           GPL-2.0-or-later
 * Text Domain:       centaur-graph-mail
 */

defined( 'ABSPATH' ) || exit;

define( 'CENTAUR_GRAPH_MAIL_VERSION', '1.2.0' );
define( 'CENTAUR_GRAPH_MAIL_FILE', __FILE__ );
define( 'CENTAUR_GRAPH_MAIL_DIR', plugin_dir_path( __FILE__ ) );

require_once CENTAUR_GRAPH_MAIL_DIR . 'includes/class-crypto.php';
require_once CENTAUR_GRAPH_MAIL_DIR . 'includes/class-settings.php';
require_once CENTAUR_GRAPH_MAIL_DIR . 'includes/class-auth.php';
require_once CENTAUR_GRAPH_MAIL_DIR . 'includes/class-mailer.php';
require_once CENTAUR_GRAPH_MAIL_DIR . 'includes/class-calendar.php';
require_once CENTAUR_GRAPH_MAIL_DIR . 'includes/class-calendar-view.php';
require_once CENTAUR_GRAPH_MAIL_DIR . 'includes/class-admin.php';

use Centaur\GraphMail\Admin;
use Centaur\GraphMail\Auth;
use Centaur\GraphMail\Calendar_View;
use Centaur\GraphMail\Mailer;

// Take over wp_mail(). pre_wp_mail runs after the 'wp_mail' filter, so other plugins can still alter the message.
add_filter( 'pre_wp_mail', array( Mailer::class, 'pre_wp_mail' ), 10, 2 );

// Keep the refresh token alive (Entra refresh tokens expire after 90 days of inactivity).
add_action( Auth::KEEPALIVE_HOOK, array( Auth::class, 'keepalive' ) );

Calendar_View::init();

if ( is_admin() ) {
	Admin::init();
}

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! wp_next_scheduled( Auth::KEEPALIVE_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', Auth::KEEPALIVE_HOOK );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( Auth::KEEPALIVE_HOOK );
	}
);
