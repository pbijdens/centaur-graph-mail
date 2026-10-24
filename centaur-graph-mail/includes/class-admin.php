<?php
namespace Centaur\GraphMail;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Graph Mail screen: configuration, Microsoft sign-in, diagnostics and test mail.
 */
final class Admin {
	private const PAGE       = 'centaur-graph-mail';
	private const CAPABILITY = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CENTAUR_GRAPH_MAIL_FILE ), array( self::class, 'action_links' ) );

		add_action( 'admin_post', array( self::class, 'oauth_callback' ) );
		add_action( 'admin_post_centaur_graph_mail_connect', array( self::class, 'connect' ) );
		add_action( 'admin_post_centaur_graph_mail_disconnect', array( self::class, 'disconnect' ) );
		add_action( 'admin_post_centaur_graph_mail_test_app', array( self::class, 'test_app' ) );
		add_action( 'admin_post_centaur_graph_mail_test_mail', array( self::class, 'test_mail' ) );
	}

	public static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	public static function menu(): void {
		add_options_page( __( 'Graph Mail', 'centaur-graph-mail' ), __( 'Graph Mail', 'centaur-graph-mail' ), self::CAPABILITY, self::PAGE, array( self::class, 'render' ) );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'centaur-graph-mail' ) ) );
		return $links;
	}

	public static function register_settings(): void {
		register_setting(
			self::PAGE,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
			)
		);
	}

	public static function notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$on_page = isset( $_GET['page'] ) && self::PAGE === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification

		$connection = Auth::connection();
		if ( ! $on_page && ( ! Settings::is_configured() || ! Auth::is_connected() ) ) {
			$reason = $connection && ! empty( $connection['error'] )
				? sprintf( /* translators: %s: error */ __( 'The Microsoft sign-in is no longer valid (%s).', 'centaur-graph-mail' ), $connection['error'] )
				: __( 'It is not connected to Microsoft yet.', 'centaur-graph-mail' );
			printf(
				'<div class="notice notice-warning"><p><strong>Centaur Graph Mail:</strong> %s %s <a href="%s">%s</a></p></div>',
				esc_html( $reason ),
				esc_html( Settings::fallback() ? __( 'WordPress is using PHP mail instead.', 'centaur-graph-mail' ) : __( 'WordPress cannot send e-mail.', 'centaur-graph-mail' ) ),
				esc_url( self::page_url() ),
				esc_html__( 'Open settings', 'centaur-graph-mail' )
			);
		}

		if ( ! $on_page ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$status  = isset( $_GET['cgm_status'] ) ? sanitize_key( $_GET['cgm_status'] ) : '';
		$message = isset( $_GET['cgm_message'] ) ? sanitize_text_field( wp_unslash( $_GET['cgm_message'] ) ) : '';
		// phpcs:enable
		if ( $status ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				'success' === $status ? 'success' : 'error',
				esc_html( $message )
			);
		}
	}

	/* ------------------------------------------------------------------ *
	 * Actions
	 * ------------------------------------------------------------------ */

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Graph Mail.', 'centaur-graph-mail' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( bool $ok, string $message ): void {
		wp_safe_redirect(
			self::page_url(
				array(
					'cgm_status'  => $ok ? 'success' : 'error',
					'cgm_message' => rawurlencode( $message ),
				)
			)
		);
		exit;
	}

	public static function connect(): void {
		self::guard( 'centaur_graph_mail_connect' );
		if ( ! Settings::is_configured() ) {
			self::back( false, __( 'Save the Tenant ID, Application ID, secret and sender mailbox first.', 'centaur-graph-mail' ) );
		}
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external Microsoft login.
		wp_redirect( Auth::authorization_url( get_current_user_id() ) );
		exit;
	}

	/** Microsoft redirects back to admin-post.php?code=…&state=… (no "action" parameter). */
	public static function oauth_callback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- protected by the OAuth state parameter.
		$query = wp_unslash( $_GET );
		if ( ! Auth::is_callback( $query ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Graph Mail.', 'centaur-graph-mail' ), 403 );
		}

		$result = Auth::handle_callback( $query, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::back( false, __( 'Microsoft sign-in failed: ', 'centaur-graph-mail' ) . $result->get_error_message() );
		}

		$account = Auth::connection()['account'];
		self::back(
			true,
			sprintf(
				/* translators: 1: account, 2: sender mailbox */
				__( 'Connected as %1$s. Send a test message to confirm this account may send as %2$s.', 'centaur-graph-mail' ),
				$account['upn'],
				Settings::sender()
			)
		);
	}

	public static function disconnect(): void {
		self::guard( 'centaur_graph_mail_disconnect' );
		Auth::disconnect();
		self::back( true, __( 'Disconnected from Microsoft.', 'centaur-graph-mail' ) );
	}

	public static function test_app(): void {
		self::guard( 'centaur_graph_mail_test_app' );
		$result = Auth::test_app_credentials();
		if ( is_wp_error( $result ) ) {
			self::back( false, __( 'Application credentials rejected: ', 'centaur-graph-mail' ) . $result->get_error_message() );
		}
		self::back( true, __( 'Tenant ID, Application ID and secret are valid.', 'centaur-graph-mail' ) );
	}

	public static function test_mail(): void {
		self::guard( 'centaur_graph_mail_test_mail' );
		$to = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
		if ( ! is_email( $to ) ) {
			self::back( false, __( 'Enter a valid recipient address.', 'centaur-graph-mail' ) );
		}

		$error = null;
		$catch = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error;
		};
		add_action( 'wp_mail_failed', $catch );

		$attachment = wp_tempnam( 'graph-mail-test.txt' );
		file_put_contents( $attachment, "This attachment was sent by Centaur Graph Mail.\n" );

		$sent = wp_mail(
			$to,
			sprintf( /* translators: %s: site name */ __( 'Test e-mail from %s', 'centaur-graph-mail' ), get_bloginfo( 'name' ) ),
			sprintf(
				'<p>%s</p><p>%s</p>',
				esc_html__( 'This is a test message sent through Microsoft Graph by the Centaur Graph Mail plugin.', 'centaur-graph-mail' ),
				esc_html( sprintf( 'Sender: %s · Site: %s · %s', Settings::sender(), home_url(), wp_date( 'c' ) ) )
			),
			array( 'Content-Type: text/html; charset=UTF-8' ),
			array( 'graph-mail-test.txt' => $attachment )
		);

		wp_delete_file( $attachment );
		remove_action( 'wp_mail_failed', $catch );

		if ( ! $sent ) {
			self::back( false, __( 'Test e-mail failed: ', 'centaur-graph-mail' ) . ( $error ? $error->get_error_message() : __( 'unknown error', 'centaur-graph-mail' ) ) );
		}
		self::back(
			true,
			sprintf( /* translators: 1: recipient, 2: sender */ __( 'Test e-mail sent to %1$s from %2$s.', 'centaur-graph-mail' ), $to, Settings::sender() )
		);
	}

	/* ------------------------------------------------------------------ *
	 * Screen
	 * ------------------------------------------------------------------ */

	private static function action_form( string $action, string $label, string $class = 'button', string $extra = '' ): void {
		printf( '<form method="post" action="%s" style="display:inline-block;margin:0 8px 8px 0">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		wp_nonce_field( $action );
		echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts by the caller.
		printf( '<button type="submit" class="%s">%s</button></form>', esc_attr( $class ), esc_html( $label ) );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings   = Settings::all();
		$connection = Auth::connection();
		$connected  = Auth::is_connected();
		$last_error = get_option( 'centaur_graph_mail_last_error' );

		$field = static function ( string $key, string $label, string $type, string $help = '' ) use ( $settings ) {
			$locked = Settings::is_constant( $key );
			$value  = 'client_secret' === $key ? '' : (string) ( $locked ? Settings::get( $key ) : $settings[ $key ] );
			$hint   = 'client_secret' === $key && ( $locked || '' !== $settings['client_secret'] )
				? __( 'Stored. Leave blank to keep the current secret.', 'centaur-graph-mail' )
				: '';

			printf( '<tr><th scope="row"><label for="cgm_%1$s">%2$s</label></th><td>', esc_attr( $key ), esc_html( $label ) );
			printf(
				'<input class="regular-text" type="%1$s" id="cgm_%2$s" name="%3$s[%2$s]" value="%4$s" placeholder="%5$s" autocomplete="off" %6$s>',
				esc_attr( $type ),
				esc_attr( $key ),
				esc_attr( Settings::OPTION ),
				esc_attr( $value ),
				esc_attr( $hint ),
				$locked ? 'disabled' : ''
			);
			if ( $locked ) {
				echo '<p class="description">' . esc_html__( 'Defined in wp-config.php.', 'centaur-graph-mail' ) . '</p>';
			}
			if ( $help ) {
				echo '<p class="description">' . wp_kses( $help, array( 'code' => array(), 'em' => array() ) ) . '</p>';
			}
			echo '</td></tr>';
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Graph Mail', 'centaur-graph-mail' ); ?></h1>
			<p><?php esc_html_e( 'All e-mail sent by WordPress is delivered through Microsoft Graph, as the sender mailbox below.', 'centaur-graph-mail' ); ?></p>

			<h2><?php esc_html_e( 'Status', 'centaur-graph-mail' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr>
						<th style="width:220px"><?php esc_html_e( 'Configuration', 'centaur-graph-mail' ); ?></th>
						<td><?php echo Settings::is_configured() ? '✅ ' . esc_html__( 'Complete', 'centaur-graph-mail' ) : '⚠️ ' . esc_html__( 'Incomplete', 'centaur-graph-mail' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Microsoft account', 'centaur-graph-mail' ); ?></th>
						<td>
							<?php
							if ( $connected ) {
								printf(
									'✅ %s &lt;%s&gt; — %s',
									esc_html( $connection['account']['name'] ),
									esc_html( $connection['account']['upn'] ),
									esc_html( sprintf( /* translators: %s: date */ __( 'connected %s', 'centaur-graph-mail' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $connection['connected_at'] ) ) )
								);
							} elseif ( $connection && ! empty( $connection['error'] ) ) {
								echo '❌ ' . esc_html( __( 'Sign-in expired or revoked: ', 'centaur-graph-mail' ) . $connection['error'] );
							} else {
								echo '⚠️ ' . esc_html__( 'Not connected', 'centaur-graph-mail' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Sends as', 'centaur-graph-mail' ); ?></th>
						<td><code><?php echo esc_html( Settings::sender() ?: '—' ); ?></code></td>
					</tr>
					<?php if ( is_array( $last_error ) && ! empty( $last_error['message'] ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Last failure', 'centaur-graph-mail' ); ?></th>
						<td><?php echo esc_html( sprintf( '%s — "%s": %s', wp_date( 'Y-m-d H:i', (int) $last_error['time'] ), $last_error['subject'], $last_error['message'] ) ); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( '1. Entra ID application', 'centaur-graph-mail' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<?php
					$field( 'tenant_id', __( 'Tenant ID', 'centaur-graph-mail' ), 'text' );
					$field( 'client_id', __( 'Application (client) ID', 'centaur-graph-mail' ), 'text' );
					$field( 'client_secret', __( 'Application secret', 'centaur-graph-mail' ), 'password', __( 'The secret <em>value</em>, not the secret ID.', 'centaur-graph-mail' ) );
					$field( 'sender', __( 'Send as mailbox', 'centaur-graph-mail' ), 'email', __( 'User or shared mailbox every message is sent from. The account you sign in with below needs Send As or Full Access rights on it (or be this mailbox).', 'centaur-graph-mail' ) );
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Redirect URI', 'centaur-graph-mail' ); ?></th>
						<td>
							<input class="large-text code" type="text" readonly value="<?php echo esc_attr( Auth::redirect_uri() ); ?>" onclick="this.select()">
							<p class="description"><?php echo wp_kses( __( 'Add this exact URI under <code>Authentication → Web → Redirect URIs</code> of the app registration.', 'centaur-graph-mail' ), array( 'code' => array() ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'centaur-graph-mail' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[save_to_sent_items]" value="1" <?php checked( $settings['save_to_sent_items'] ); ?>> <?php esc_html_e( 'Save a copy in the mailbox\'s Sent Items', 'centaur-graph-mail' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[fallback]" value="1" <?php checked( $settings['fallback'] ); ?>> <?php esc_html_e( 'Use PHP mail while Graph Mail is not configured or connected', 'centaur-graph-mail' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'centaur-graph-mail' ) ); ?>
			</form>
			<?php self::action_form( 'centaur_graph_mail_test_app', __( 'Test application credentials', 'centaur-graph-mail' ) ); ?>

			<h2><?php esc_html_e( '2. Grant Mail.Send', 'centaur-graph-mail' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: sender mailbox */
					esc_html__( 'Sign in with a Microsoft 365 account that may send e-mail as %s. You will be asked to consent to the delegated permissions Mail.Send and Mail.Send.Shared; WordPress keeps a refresh token so mail keeps working when you are logged out.', 'centaur-graph-mail' ),
					'<code>' . esc_html( Settings::sender() ?: '…' ) . '</code>'
				);
				?>
			</p>
			<?php
			self::action_form( 'centaur_graph_mail_connect', $connected ? __( 'Reconnect with Microsoft', 'centaur-graph-mail' ) : __( 'Sign in with Microsoft', 'centaur-graph-mail' ), 'button button-primary' );
			if ( $connection ) {
				self::action_form( 'centaur_graph_mail_disconnect', __( 'Disconnect', 'centaur-graph-mail' ) );
			}
			?>

			<h2><?php esc_html_e( '3. Send a test e-mail', 'centaur-graph-mail' ); ?></h2>
			<?php
			self::action_form(
				'centaur_graph_mail_test_mail',
				__( 'Send test e-mail', 'centaur-graph-mail' ),
				'button',
				sprintf(
					'<input type="email" name="to" class="regular-text" required value="%s" style="margin-right:8px"> ',
					esc_attr( wp_get_current_user()->user_email )
				)
			);
			?>
		</div>
		<?php
	}
}
