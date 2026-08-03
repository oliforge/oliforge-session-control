<?php
/**
 * Main plugin class.
 *
 * @package OliForge_Session_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controls authentication cookie lifetimes and optional idle logout.
 */
final class OliForge_Session_Control {

	/**
	 * Option key used for plugin settings.
	 */
	private const OPTION_NAME = 'oliforge_session_control_settings';

	/**
	 * User-meta key used to store the last activity timestamp.
	 */
	private const LAST_ACTIVITY_META = '_oliforge_session_last_activity';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Adds default options during activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( false === get_option( self::OPTION_NAME ) ) {
			add_option( self::OPTION_NAME, self::defaults() );
		}
	}

	/**
	 * Registers hooks.
	 */
	private function __construct() {
		add_filter( 'auth_cookie_expiration', array( $this, 'filter_auth_cookie_expiration' ), 10, 3 );

		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		add_action( 'init', array( $this, 'maybe_logout_inactive_user' ), 20 );
		add_action( 'wp_login', array( $this, 'set_login_activity_timestamp' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'clear_activity_timestamp' ), 10, 1 );

		add_filter(
			'plugin_action_links_' . plugin_basename( OLIFORGE_SESSION_CONTROL_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Returns default settings.
	 *
	 * @return array<string, int>
	 */
	public static function defaults(): array {
		return array(
			'normal_hours'           => 8,
			'remember_days'          => 14,
			'enable_idle_logout'     => 0,
			'idle_minutes'           => 60,
			'apply_to_admins'        => 1,
			'apply_to_frontend_users'=> 1,
		);
	}

	/**
	 * Returns validated plugin settings merged with defaults.
	 *
	 * @return array<string, int>
	 */
	private function get_settings(): array {
		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, self::defaults() );
	}

	/**
	 * Filters the authentication cookie lifetime.
	 *
	 * @param int  $expiration Existing expiration in seconds.
	 * @param int  $user_id    User ID.
	 * @param bool $remember   Whether Remember Me is enabled.
	 * @return int
	 */
	public function filter_auth_cookie_expiration( int $expiration, int $user_id, bool $remember ): int {
		$settings = $this->get_settings();

		if ( ! $this->should_apply_to_user( $user_id, $settings ) ) {
			return $expiration;
		}

		if ( $remember ) {
			return max( 1, absint( $settings['remember_days'] ) ) * DAY_IN_SECONDS;
		}

		return max( 1, absint( $settings['normal_hours'] ) ) * HOUR_IN_SECONDS;
	}

	/**
	 * Determines whether settings apply to a user.
	 *
	 * @param int                $user_id  User ID.
	 * @param array<string, int> $settings Plugin settings.
	 * @return bool
	 */
	private function should_apply_to_user( int $user_id, array $settings ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		$is_admin = user_can( $user, 'manage_options' );

		if ( $is_admin && empty( $settings['apply_to_admins'] ) ) {
			return false;
		}

		if ( ! $is_admin && empty( $settings['apply_to_frontend_users'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Registers the settings page.
	 *
	 * @return void
	 */
	public function register_settings_page(): void {
		add_options_page(
			__( 'OliForge Session Control', 'oliforge-session-control' ),
			__( 'OliForge Session Control', 'oliforge-session-control' ),
			'manage_options',
			'oliforge-session-control',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers plugin settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'oliforge_session_control_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitizes plugin settings.
	 *
	 * @param mixed $input Raw settings input.
	 * @return array<string, int>
	 */
	public function sanitize_settings( $input ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->get_settings();
		}

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$defaults = self::defaults();

		return array(
			'normal_hours'            => min( 168, max( 1, absint( $input['normal_hours'] ?? $defaults['normal_hours'] ) ) ),
			'remember_days'           => min( 365, max( 1, absint( $input['remember_days'] ?? $defaults['remember_days'] ) ) ),
			'enable_idle_logout'      => empty( $input['enable_idle_logout'] ) ? 0 : 1,
			'idle_minutes'            => min( 10080, max( 1, absint( $input['idle_minutes'] ?? $defaults['idle_minutes'] ) ) ),
			'apply_to_admins'         => empty( $input['apply_to_admins'] ) ? 0 : 1,
			'apply_to_frontend_users' => empty( $input['apply_to_frontend_users'] ) ? 0 : 1,
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'oliforge-session-control' ) );
		}

		$settings = $this->get_settings();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'OliForge Session Control', 'oliforge-session-control' ); ?></h1>
			<p><?php echo esc_html__( 'Manage WordPress login cookie lifetimes and optional logout after inactivity.', 'oliforge-session-control' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'oliforge_session_control_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="oliforge_normal_hours"><?php echo esc_html__( 'Normal session lifetime', 'oliforge-session-control' ); ?></label>
						</th>
						<td>
							<input
								id="oliforge_normal_hours"
								type="number"
								min="1"
								max="168"
								name="<?php echo esc_attr( self::OPTION_NAME ); ?>[normal_hours]"
								value="<?php echo esc_attr( (string) $settings['normal_hours'] ); ?>"
							/>
							<span><?php echo esc_html__( 'hours when Remember Me is not checked.', 'oliforge-session-control' ); ?></span>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="oliforge_remember_days"><?php echo esc_html__( 'Remember Me session lifetime', 'oliforge-session-control' ); ?></label>
						</th>
						<td>
							<input
								id="oliforge_remember_days"
								type="number"
								min="1"
								max="365"
								name="<?php echo esc_attr( self::OPTION_NAME ); ?>[remember_days]"
								value="<?php echo esc_attr( (string) $settings['remember_days'] ); ?>"
							/>
							<span><?php echo esc_html__( 'days when Remember Me is checked.', 'oliforge-session-control' ); ?></span>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__( 'Idle logout', 'oliforge-session-control' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_idle_logout]"
									value="1"
									<?php checked( 1, (int) $settings['enable_idle_logout'] ); ?>
								/>
								<?php echo esc_html__( 'Enable automatic logout after inactivity', 'oliforge-session-control' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="oliforge_idle_minutes"><?php echo esc_html__( 'Idle timeout', 'oliforge-session-control' ); ?></label>
						</th>
						<td>
							<input
								id="oliforge_idle_minutes"
								type="number"
								min="1"
								max="10080"
								name="<?php echo esc_attr( self::OPTION_NAME ); ?>[idle_minutes]"
								value="<?php echo esc_attr( (string) $settings['idle_minutes'] ); ?>"
							/>
							<span><?php echo esc_html__( 'minutes without activity.', 'oliforge-session-control' ); ?></span>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__( 'Apply to users', 'oliforge-session-control' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input
										type="checkbox"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[apply_to_admins]"
										value="1"
										<?php checked( 1, (int) $settings['apply_to_admins'] ); ?>
									/>
									<?php echo esc_html__( 'Apply to administrators', 'oliforge-session-control' ); ?>
								</label>
								<br />
								<label>
									<input
										type="checkbox"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[apply_to_frontend_users]"
										value="1"
										<?php checked( 1, (int) $settings['apply_to_frontend_users'] ); ?>
									/>
									<?php echo esc_html__( 'Apply to frontend users', 'oliforge-session-control' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Logs out inactive users or updates their activity timestamp.
	 *
	 * @return void
	 */
	public function maybe_logout_inactive_user(): void {
		if ( ! is_user_logged_in() || wp_doing_cron() ) {
			return;
		}

		$settings = $this->get_settings();
		$user_id  = get_current_user_id();

		if ( ! $this->should_apply_to_user( $user_id, $settings ) ) {
			return;
		}

		if ( empty( $settings['enable_idle_logout'] ) ) {
			$this->maybe_update_activity_timestamp( $user_id );
			return;
		}

		$now           = time();
		$last_activity = (int) get_user_meta( $user_id, self::LAST_ACTIVITY_META, true );
		$idle_seconds  = max( 1, absint( $settings['idle_minutes'] ) ) * MINUTE_IN_SECONDS;

		if ( $last_activity > 0 && ( $now - $last_activity ) > $idle_seconds ) {
			$request_uri = '/';

			if ( isset( $_SERVER['REQUEST_URI'] ) ) {
				$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			}

			$redirect_to = wp_validate_redirect(
				home_url( $request_uri ),
				home_url( '/' )
			);

			wp_logout();
			nocache_headers();

			wp_safe_redirect(
				wp_login_url(
					add_query_arg(
						'oliforge_session_expired',
						'1',
						$redirect_to
					)
				)
			);
			exit;
		}

		$this->maybe_update_activity_timestamp( $user_id, $last_activity, $now );
	}

	/**
	 * Stores activity at login.
	 *
	 * @param string  $user_login User login.
	 * @param WP_User $user       Logged-in user.
	 * @return void
	 */
	public function set_login_activity_timestamp( string $user_login, WP_User $user ): void {
		unset( $user_login );
		$this->update_activity_timestamp( (int) $user->ID );
	}

	/**
	 * Removes activity data at logout.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function clear_activity_timestamp( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::LAST_ACTIVITY_META );
		}
	}

	/**
	 * Updates activity only when necessary.
	 *
	 * @param int      $user_id       User ID.
	 * @param int|null $last_activity Existing timestamp.
	 * @param int|null $now           Current timestamp.
	 * @return void
	 */
	private function maybe_update_activity_timestamp(
		int $user_id,
		?int $last_activity = null,
		?int $now = null
	): void {
		$now = $now ?? time();

		if ( null === $last_activity ) {
			$last_activity = (int) get_user_meta( $user_id, self::LAST_ACTIVITY_META, true );
		}

		if ( $last_activity <= 0 || ( $now - $last_activity ) >= 60 ) {
			$this->update_activity_timestamp( $user_id, $now );
		}
	}

	/**
	 * Writes the activity timestamp.
	 *
	 * @param int      $user_id User ID.
	 * @param int|null $now     Timestamp.
	 * @return void
	 */
	private function update_activity_timestamp( int $user_id, ?int $now = null ): void {
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::LAST_ACTIVITY_META, $now ?? time() );
		}
	}

	/**
	 * Adds a Settings link to the Plugins screen.
	 *
	 * @param array<int, string> $links Existing plugin action links.
	 * @return array<int, string>
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=oliforge-session-control' ) ),
			esc_html__( 'Settings', 'oliforge-session-control' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
}
