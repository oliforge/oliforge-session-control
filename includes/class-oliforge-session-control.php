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
	 * Slug of the "Active Sessions" admin page.
	 */
	public const SESSIONS_PAGE_SLUG = 'oliforge-session-control-sessions';

	/**
	 * Slug of the Network Admin "Network Sessions" page (multisite only).
	 */
	public const NETWORK_PAGE_SLUG = 'oliforge-session-control-network';

	/**
	 * Safety caps for the network-wide aggregate query: how many sites to
	 * loop over, and how many rows to pull from each site before merging and
	 * sorting in PHP. Cross-site sorting can't be pushed down to SQL without
	 * a shared table, so this bounds the worst case on very large networks.
	 */
	private const NETWORK_SITE_CAP          = 200;
	private const NETWORK_PER_SITE_ROW_CAP  = 500;

	/**
	 * Option holding the installed session-log table schema version, bumped
	 * whenever the table shape changes so dbDelta() re-runs on upgrade.
	 */
	private const DB_VERSION_OPTION = 'oliforge_session_control_db_version';

	/**
	 * Current session-log table schema version.
	 */
	private const DB_VERSION = '1.0';

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
	 * Adds default options and creates the session-log table during activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( false === get_option( self::OPTION_NAME ) ) {
			add_option( self::OPTION_NAME, self::defaults() );
		}

		self::create_or_upgrade_log_table();
	}

	/**
	 * Returns the fully-qualified name of the session-log table.
	 *
	 * @return string
	 */
	private static function log_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'oliforge_session_log';
	}

	/**
	 * Creates the session-log table, or brings an existing one up to date.
	 * Safe to call repeatedly — dbDelta() only applies the diff.
	 *
	 * @return void
	 */
	private static function create_or_upgrade_log_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::log_table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			token VARCHAR(255) NOT NULL,
			ip VARCHAR(100) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			login_at BIGINT UNSIGNED NOT NULL,
			last_seen_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY user_token (user_id, token(64))
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Re-runs the table creation on version bumps for sites that updated the
	 * plugin without deactivating/reactivating it.
	 *
	 * @return void
	 */
	public function maybe_upgrade_log_table(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_or_upgrade_log_table();
		}
	}

	/**
	 * Registers hooks.
	 */
	private function __construct() {
		add_filter( 'auth_cookie_expiration', array( $this, 'filter_auth_cookie_expiration' ), 10, 3 );

		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_log_table' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Network-wide aggregate view — only relevant, and only registered, on multisite.
		// A plain single-site install never fires these hooks, so it keeps behaving exactly
		// as it did before: fully isolated, no network-admin code path involved at all.
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'register_network_page' ) );
			add_action( 'network_admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}

		add_action( 'init', array( $this, 'maybe_logout_inactive_user' ), 20 );
		add_action( 'wp_login', array( $this, 'set_login_activity_timestamp' ), 10, 2 );
		add_action( 'set_auth_cookie', array( $this, 'log_new_session' ), 10, 6 );
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

		$sessions_hook = add_submenu_page(
			'options-general.php',
			__( 'Active Sessions', 'oliforge-session-control' ),
			__( 'OliForge Active Sessions', 'oliforge-session-control' ),
			'manage_options',
			self::SESSIONS_PAGE_SLUG,
			array( $this, 'render_sessions_page' )
		);

		if ( $sessions_hook ) {
			add_action( 'load-' . $sessions_hook, array( $this, 'handle_sessions_table_actions' ) );
		}
	}

	/**
	 * Registers the Network Admin "Network Sessions" page (multisite only).
	 *
	 * @return void
	 */
	public function register_network_page(): void {
		$network_hook = add_submenu_page(
			'settings.php',
			__( 'Network Sessions', 'oliforge-session-control' ),
			__( 'OliForge Network Sessions', 'oliforge-session-control' ),
			'manage_network_users',
			self::NETWORK_PAGE_SLUG,
			array( $this, 'render_network_sessions_page' )
		);

		if ( $network_hook ) {
			add_action( 'load-' . $network_hook, array( $this, 'handle_network_sessions_table_actions' ) );
		}
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
	 * Enqueues the settings-page stylesheet and script.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		$allowed_hooks = array(
			'settings_page_oliforge-session-control',
			'settings_page_' . self::SESSIONS_PAGE_SLUG,
			'settings_page_' . self::NETWORK_PAGE_SLUG,
		);

		if ( ! in_array( $hook_suffix, $allowed_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'oliforge-session-control-admin',
			OLIFORGE_SESSION_CONTROL_URL . 'assets/css/admin.min.css',
			array(),
			OLIFORGE_SESSION_CONTROL_VERSION
		);

		wp_enqueue_script(
			'oliforge-session-control-admin',
			OLIFORGE_SESSION_CONTROL_URL . 'assets/js/admin.min.js',
			array(),
			OLIFORGE_SESSION_CONTROL_VERSION,
			true
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
		<div class="wrap oliforge-session-control-ui">
			<?php
			$this->render_brand_header( __( 'Session Control', 'oliforge-session-control' ), __( 'Settings', 'oliforge-session-control' ) );
			$this->render_nav_tabs( 'settings' );
			?>
			<p class="oliforge-lede"><?php echo esc_html__( 'Manage WordPress login cookie lifetimes and optional logout after inactivity.', 'oliforge-session-control' ); ?></p>

			<form method="post" action="options.php" class="oliforge-card">
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
							<label class="oliforge-toggle">
								<input
									class="oliforge-toggle__input"
									type="checkbox"
									data-oliforge-idle-toggle
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_idle_logout]"
									value="1"
									<?php checked( 1, (int) $settings['enable_idle_logout'] ); ?>
								/>
								<span class="oliforge-toggle__track"><span class="oliforge-toggle__thumb"></span></span>
								<span class="oliforge-toggle__label"><?php echo esc_html__( 'Enable automatic logout after inactivity', 'oliforge-session-control' ); ?></span>
							</label>
						</td>
					</tr>

					<tr class="oliforge-conditional-row" data-oliforge-idle-row>
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
								<label class="oliforge-toggle">
									<input
										class="oliforge-toggle__input"
										type="checkbox"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[apply_to_admins]"
										value="1"
										<?php checked( 1, (int) $settings['apply_to_admins'] ); ?>
									/>
									<span class="oliforge-toggle__track"><span class="oliforge-toggle__thumb"></span></span>
									<span class="oliforge-toggle__label"><?php echo esc_html__( 'Apply to administrators', 'oliforge-session-control' ); ?></span>
								</label>
								<label class="oliforge-toggle">
									<input
										class="oliforge-toggle__input"
										type="checkbox"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[apply_to_frontend_users]"
										value="1"
										<?php checked( 1, (int) $settings['apply_to_frontend_users'] ); ?>
									/>
									<span class="oliforge-toggle__track"><span class="oliforge-toggle__thumb"></span></span>
									<span class="oliforge-toggle__label"><?php echo esc_html__( 'Apply to frontend users', 'oliforge-session-control' ); ?></span>
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
	 * Renders the shared branded header used by both admin pages.
	 *
	 * @param string $title_main   Main (dark) part of the page title.
	 * @param string $title_accent Accented (orange) part of the page title.
	 * @return void
	 */
	private function render_brand_header( string $title_main, string $title_accent ): void {
		?>
		<div class="oliforge-header">
			<div class="oliforge-header__brand">
				<img class="oliforge-header__logo" src="<?php echo esc_url( OLIFORGE_SESSION_CONTROL_URL . 'src/OliForge_logo.png' ); ?>" alt="<?php esc_attr_e( 'OliForge', 'oliforge-session-control' ); ?>" width="64" height="64">
				<div class="oliforge-header__brandtext">
					<span class="oliforge-header__name"><?php echo esc_html__( 'OliForge', 'oliforge-session-control' ); ?></span>
					<span class="oliforge-header__tagline"><?php echo esc_html__( 'Engineering without complexity.', 'oliforge-session-control' ); ?></span>
				</div>
			</div>
			<div class="oliforge-header__title">
				<h1><?php echo esc_html( $title_main ); ?> <span class="oliforge-accent"><?php echo esc_html( $title_accent ); ?></span></h1>
				<span class="oliforge-badge oliforge-badge--version">v<?php echo esc_html( OLIFORGE_SESSION_CONTROL_VERSION ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Settings / Active Sessions (/ Network Sessions) tab bar.
	 *
	 * @param string $current One of 'settings', 'sessions', 'network'.
	 * @return void
	 */
	private function render_nav_tabs( string $current ): void {
		$tabs = array(
			'settings' => array(
				'label' => __( 'Settings', 'oliforge-session-control' ),
				'url'   => admin_url( 'options-general.php?page=oliforge-session-control' ),
			),
			'sessions' => array(
				'label' => __( 'Active Sessions', 'oliforge-session-control' ),
				'url'   => admin_url( 'options-general.php?page=' . self::SESSIONS_PAGE_SLUG ),
			),
		);

		// Only network admins on a multisite install can reach the aggregate view.
		if ( is_multisite() && current_user_can( 'manage_network_users' ) ) {
			$tabs['network'] = array(
				'label' => __( 'Network Sessions', 'oliforge-session-control' ),
				'url'   => network_admin_url( 'settings.php?page=' . self::NETWORK_PAGE_SLUG ),
			);
		}
		?>
		<nav class="oliforge-tabs">
			<?php foreach ( $tabs as $key => $tab ) : ?>
				<a href="<?php echo esc_url( $tab['url'] ); ?>" class="oliforge-tabs__link<?php echo $key === $current ? ' is-active' : ''; ?>">
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Renders the "Active Sessions" admin page.
	 *
	 * @return void
	 */
	public function render_sessions_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'oliforge-session-control' ) );
		}

		require_once OLIFORGE_SESSION_CONTROL_PATH . 'includes/class-oliforge-session-control-list-table.php';

		$list_table = new OliForge_Session_List_Table();
		$list_table->prepare_items();
		?>
		<div class="wrap oliforge-session-control-ui">
			<?php
			$this->render_brand_header( __( 'Session Control', 'oliforge-session-control' ), __( 'Active Sessions', 'oliforge-session-control' ) );
			$this->render_nav_tabs( 'sessions' );
			?>
			<p class="oliforge-lede"><?php echo esc_html__( 'A running log of every login across all users. Terminate a still-active session to sign that device out, or delete a row to remove it from this list. Only logins since this feature was enabled are logged — use "Sync current sessions" to backfill sessions that were already open when it was turned on.', 'oliforge-session-control' ); ?></p>

			<?php $this->render_sync_notice(); ?>

			<p>
				<a href="<?php echo esc_url( $this->sync_url() ); ?>" class="button oliforge-sync-button"><?php echo esc_html__( 'Sync current sessions', 'oliforge-session-control' ); ?></a>
			</p>

			<div class="oliforge-card oliforge-card--table">
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SESSIONS_PAGE_SLUG ); ?>" />
					<?php
					$list_table->search_box( __( 'Search sessions', 'oliforge-session-control' ), 'oliforge-session' );
					$list_table->display();
					?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Network Admin "Network Sessions" aggregate page.
	 *
	 * @return void
	 */
	public function render_network_sessions_page(): void {
		if ( ! current_user_can( 'manage_network_users' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'oliforge-session-control' ) );
		}

		require_once OLIFORGE_SESSION_CONTROL_PATH . 'includes/class-oliforge-network-session-list-table.php';

		$list_table = new OliForge_Network_Session_List_Table();
		$list_table->prepare_items();
		?>
		<div class="wrap oliforge-session-control-ui">
			<?php
			$this->render_brand_header( __( 'Session Control', 'oliforge-session-control' ), __( 'Network Sessions', 'oliforge-session-control' ) );
			$this->render_nav_tabs( 'network' );
			?>
			<p class="oliforge-lede"><?php echo esc_html__( 'Every logged session across all sites in this network, in one place. Terminate a still-active session to sign that device out, or delete a row to remove it from the log. Each site keeps its own log — run "Sync current sessions" on a site\'s own Active Sessions screen to backfill sessions that predate this feature there.', 'oliforge-session-control' ); ?></p>

			<?php $list_table->maybe_render_truncation_notice(); ?>

			<div class="oliforge-card oliforge-card--table">
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::NETWORK_PAGE_SLUG ); ?>" />
					<?php
					$list_table->search_box( __( 'Search sessions', 'oliforge-session-control' ), 'oliforge-session' );
					$list_table->display();
					?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Processes the "Active Sessions" table's row and bulk actions. Runs on
	 * the page's `load-` hook, i.e. before any HTML has been sent, so it can
	 * still redirect.
	 *
	 * @return void
	 */
	public function handle_sessions_table_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['oliforge_terminate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified via check_admin_referer() below.
			check_admin_referer( 'oliforge_terminate_session' );
			$this->terminate_sessions( array( absint( $_GET['oliforge_terminate'] ) ) );
			wp_safe_redirect( remove_query_arg( array( 'oliforge_terminate', '_wpnonce' ) ) );
			exit;
		}

		if ( isset( $_GET['oliforge_delete'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified via check_admin_referer() below.
			check_admin_referer( 'oliforge_delete_session' );
			$this->delete_log_rows( array( absint( $_GET['oliforge_delete'] ) ) );
			wp_safe_redirect( remove_query_arg( array( 'oliforge_delete', '_wpnonce' ) ) );
			exit;
		}

		if ( isset( $_GET['oliforge_sync_sessions'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified via check_admin_referer() below.
			check_admin_referer( 'oliforge_sync_sessions' );
			$synced = $this->sync_live_sessions();
			wp_safe_redirect(
				add_query_arg(
					'oliforge_synced',
					$synced,
					remove_query_arg( array( 'oliforge_sync_sessions', '_wpnonce' ) )
				)
			);
			exit;
		}

		$action = '';
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified via check_admin_referer() below.
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( in_array( $action, array( 'terminate', 'delete' ), true ) && isset( $_REQUEST['session'] ) ) {
			check_admin_referer( 'bulk-sessions' );
			$selected = array_map( 'absint', (array) wp_unslash( $_REQUEST['session'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash

			if ( 'terminate' === $action ) {
				$this->terminate_sessions( $selected );
			} else {
				$this->delete_log_rows( $selected );
			}

			wp_safe_redirect( remove_query_arg( array( 'action', 'action2', 'session', '_wpnonce' ) ) );
			exit;
		}
	}

	/**
	 * Ends the live WordPress session for one or more log rows (looked up by
	 * row id), without deleting the log entry itself — it now simply shows
	 * as "Ended" on the next render.
	 *
	 * @param int[] $ids Session-log row ids.
	 * @return void
	 */
	private function terminate_sessions( array $ids ): void {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return;
		}

		global $wpdb;
		$table        = self::log_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders built above from a fixed count of %d tokens.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, token FROM {$table} WHERE id IN ( {$placeholders} )", $ids ) );

		foreach ( $rows as $row ) {
			WP_Session_Tokens::get_instance( (int) $row->user_id )->destroy( $row->token );
		}
	}

	/**
	 * Permanently deletes one or more rows from the session-log table
	 * ("Delete from List"). Does not touch the underlying live session, if
	 * any — a still-active session just stops being logged/tracked.
	 *
	 * @param int[] $ids Session-log row ids.
	 * @return void
	 */
	private function delete_log_rows( array $ids ): void {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return;
		}

		global $wpdb;
		$table        = self::log_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders built above from a fixed count of %d tokens.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ( {$placeholders} )", $ids ) );
	}

	/**
	 * Queries the session-log table with search/role filtering, sorting and
	 * pagination applied at the SQL level.
	 *
	 * Search/role are resolved to a set of user ids first via get_users()
	 * (reusing WP's own robust search/role logic instead of hand-rolling a
	 * LIKE query against serialized usermeta), then used to filter the log.
	 *
	 * @param string $search   Search term (login/email/display name).
	 * @param string $role     Role slug.
	 * @param string $orderby  'logged_in' or 'last_active'.
	 * @param string $order    'asc' or 'desc'.
	 * @param int    $per_page Rows per page.
	 * @param int    $page     1-based page number.
	 * @return array{items: object[], total: int}
	 */
	public function query_sessions( string $search, string $role, string $orderby, string $order, int $per_page, int $page ): array {
		global $wpdb;
		$table = self::log_table();

		$where = '';
		if ( '' !== $search || '' !== $role ) {
			$user_args = array( 'fields' => 'ID' );

			if ( '' !== $search ) {
				$user_args['search']         = '*' . $search . '*';
				$user_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
			}

			if ( '' !== $role ) {
				$user_args['role'] = $role;
			}

			$user_ids = array_map( 'absint', get_users( $user_args ) );

			if ( ! $user_ids ) {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}

			$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $placeholders built above from a fixed count of %d tokens.
			$where = $wpdb->prepare( " WHERE user_id IN ( {$placeholders} )", $user_ids );
		}

		$orderby_column = 'last_active' === $orderby ? 'last_seen_at' : 'login_at';
		$order          = 'asc' === $order ? 'ASC' : 'DESC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where is already safely prepared above.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where}" );

		$offset = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where already safely prepared above; $orderby_column/$order come from a fixed whitelist above, not raw user input.
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}{$where} ORDER BY {$orderby_column} {$order} LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Whether a session-log row's token still corresponds to a live,
	 * non-expired WordPress session.
	 *
	 * @param int    $user_id User id.
	 * @param string $token   Session verifier token.
	 * @return bool
	 */
	public function is_session_active( int $user_id, string $token ): bool {
		$tokens = WP_Session_Tokens::get_instance( $user_id )->get_all();

		return isset( $tokens[ $token ] ) && ! empty( $tokens[ $token ]['expiration'] ) && $tokens[ $token ]['expiration'] >= time();
	}

	/**
	 * Processes the "Network Sessions" table's row and bulk actions. Same
	 * shape as handle_sessions_table_actions(), but refs are "blog_id:row_id"
	 * since a row can belong to any site in the network.
	 *
	 * @return void
	 */
	public function handle_network_sessions_table_actions(): void {
		if ( ! current_user_can( 'manage_network_users' ) ) {
			return;
		}

		if ( isset( $_GET['oliforge_net_terminate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified via check_admin_referer() below.
			check_admin_referer( 'oliforge_terminate_session' );
			$this->terminate_network_sessions( array( sanitize_text_field( wp_unslash( $_GET['oliforge_net_terminate'] ) ) ) );
			wp_safe_redirect( remove_query_arg( array( 'oliforge_net_terminate', '_wpnonce' ) ) );
			exit;
		}

		if ( isset( $_GET['oliforge_net_delete'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified via check_admin_referer() below.
			check_admin_referer( 'oliforge_delete_session' );
			$this->delete_network_log_rows( array( sanitize_text_field( wp_unslash( $_GET['oliforge_net_delete'] ) ) ) );
			wp_safe_redirect( remove_query_arg( array( 'oliforge_net_delete', '_wpnonce' ) ) );
			exit;
		}

		$action = '';
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified via check_admin_referer() below.
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( in_array( $action, array( 'terminate', 'delete' ), true ) && isset( $_REQUEST['session'] ) ) {
			check_admin_referer( 'bulk-sessions' );
			$selected = array_map( 'sanitize_text_field', wp_unslash( (array) $_REQUEST['session'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash

			if ( 'terminate' === $action ) {
				$this->terminate_network_sessions( $selected );
			} else {
				$this->delete_network_log_rows( $selected );
			}

			wp_safe_redirect( remove_query_arg( array( 'action', 'action2', 'session', '_wpnonce' ) ) );
			exit;
		}
	}

	/**
	 * Ends the live session for one or more "blog_id:row_id" refs, switching
	 * to each row's site first so its log table is the one being read.
	 *
	 * @param string[] $refs "blog_id:row_id" refs.
	 * @return void
	 */
	private function terminate_network_sessions( array $refs ): void {
		foreach ( $this->parse_network_refs( $refs ) as $blog_id => $row_ids ) {
			switch_to_blog( $blog_id );
			$this->terminate_sessions( $row_ids );
			restore_current_blog();
		}
	}

	/**
	 * Deletes one or more "blog_id:row_id" log rows ("Delete from List"),
	 * switching to each row's site first.
	 *
	 * @param string[] $refs "blog_id:row_id" refs.
	 * @return void
	 */
	private function delete_network_log_rows( array $refs ): void {
		foreach ( $this->parse_network_refs( $refs ) as $blog_id => $row_ids ) {
			switch_to_blog( $blog_id );
			$this->delete_log_rows( $row_ids );
			restore_current_blog();
		}
	}

	/**
	 * Groups "blog_id:row_id" refs by blog id, so each site is only
	 * switched to once regardless of how many of its rows were selected.
	 *
	 * @param string[] $refs "blog_id:row_id" refs.
	 * @return array<int, int[]> blog_id => row ids.
	 */
	private function parse_network_refs( array $refs ): array {
		$grouped = array();

		foreach ( $refs as $ref ) {
			$parts = explode( ':', (string) $ref, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$blog_id = absint( $parts[0] );
			$row_id  = absint( $parts[1] );

			if ( $blog_id <= 0 || $row_id <= 0 ) {
				continue;
			}

			$grouped[ $blog_id ][] = $row_id;
		}

		return $grouped;
	}

	/**
	 * Aggregates session-log rows across every site in the network.
	 *
	 * Search/role filtering and per-site sorting reuse query_sessions() by
	 * switching into each site in turn (session tokens themselves live in
	 * shared, network-wide usermeta, but the log table and role/capability
	 * checks are per-site). Cross-site sorting can't be pushed down to SQL
	 * without a shared table, so each site contributes up to
	 * NETWORK_PER_SITE_ROW_CAP rows, which are then merged, sorted and
	 * paginated in PHP. See maybe_render_truncation_notice() for what
	 * happens when a network or a single site is larger than the caps.
	 *
	 * @param string $search   Search term (login/email/display name).
	 * @param string $role     Role slug.
	 * @param string $orderby  'logged_in' or 'last_active'.
	 * @param string $order    'asc' or 'desc'.
	 * @param int    $per_page Rows per page.
	 * @param int    $page     1-based page number.
	 * @return array{items: object[], total: int, sites_truncated: bool, rows_truncated: bool}
	 */
	public function query_network_sessions( string $search, string $role, string $orderby, string $order, int $per_page, int $page ): array {
		$sites            = get_sites( array( 'number' => self::NETWORK_SITE_CAP ) );
		$sites_truncated  = count( $sites ) >= self::NETWORK_SITE_CAP;
		$rows_truncated   = false;
		$all              = array();

		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;
			switch_to_blog( $blog_id );

			$result = $this->query_sessions( $search, $role, $orderby, $order, self::NETWORK_PER_SITE_ROW_CAP, 1 );

			if ( $result['total'] > self::NETWORK_PER_SITE_ROW_CAP ) {
				$rows_truncated = true;
			}

			foreach ( $result['items'] as $row ) {
				$row->blog_id = $blog_id;
				$all[]        = $row;
			}

			restore_current_blog();
		}

		$orderby_prop = 'last_active' === $orderby ? 'last_seen_at' : 'login_at';
		usort(
			$all,
			static function ( $a, $b ) use ( $orderby_prop, $order ) {
				$cmp = ( (int) $a->$orderby_prop ) <=> ( (int) $b->$orderby_prop );

				return 'asc' === $order ? $cmp : -$cmp;
			}
		);

		$total  = count( $all );
		$offset = max( 0, ( $page - 1 ) * $per_page );

		return array(
			'items'           => array_slice( $all, $offset, $per_page ),
			'total'           => $total,
			'sites_truncated' => $sites_truncated,
			'rows_truncated'  => $rows_truncated,
		);
	}

	/**
	 * Backfills the log with any currently-live WordPress session that
	 * predates this feature (i.e. was never caught by the `set_auth_cookie`
	 * hook). WP's own token storage already carries `login`, `ip` and `ua`
	 * for each token, so the backfilled rows are accurate, not guessed.
	 *
	 * Safe to run repeatedly — sessions already present in the log are
	 * skipped, never duplicated.
	 *
	 * @return int Number of rows inserted.
	 */
	public function sync_live_sessions(): int {
		global $wpdb;
		$table = self::log_table();

		$existing = $wpdb->get_results( "SELECT user_id, token FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$known = array();
		foreach ( $existing as $row ) {
			$known[ $row->user_id . ':' . $row->token ] = true;
		}

		$users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'session_tokens',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$now      = time();
		$inserted = 0;

		foreach ( $users as $user ) {
			$tokens = WP_Session_Tokens::get_instance( $user->ID )->get_all();

			if ( ! $tokens ) {
				continue;
			}

			$last_active = (int) get_user_meta( $user->ID, self::LAST_ACTIVITY_META, true );

			foreach ( $tokens as $token => $session ) {
				if ( empty( $session['expiration'] ) || $session['expiration'] < $now ) {
					continue;
				}

				if ( isset( $known[ $user->ID . ':' . $token ] ) ) {
					continue;
				}

				$login_at = isset( $session['login'] ) ? (int) $session['login'] : $now;
				$ip       = isset( $session['ip'] ) ? (string) $session['ip'] : '';
				$ua       = isset( $session['ua'] ) ? (string) $session['ua'] : '';

				$wpdb->insert(
					$table,
					array(
						'user_id'      => $user->ID,
						'token'        => $token,
						'ip'           => mb_substr( $ip, 0, 100 ),
						'user_agent'   => mb_substr( $ua, 0, 255 ),
						'login_at'     => $login_at,
						'last_seen_at' => $last_active > 0 ? $last_active : $login_at,
					),
					array( '%d', '%s', '%s', '%s', '%d', '%d' )
				);

				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Builds the nonce-protected "Sync current sessions" link.
	 *
	 * @return string
	 */
	private function sync_url(): string {
		$url = add_query_arg(
			array(
				'page'                    => self::SESSIONS_PAGE_SLUG,
				'oliforge_sync_sessions'  => '1',
			),
			admin_url( 'options-general.php' )
		);

		return wp_nonce_url( $url, 'oliforge_sync_sessions' );
	}

	/**
	 * Shows a one-off "N session(s) synced" notice after a sync, based on
	 * the redirect query arg.
	 *
	 * @return void
	 */
	private function render_sync_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects which notice text to display.
		if ( ! isset( $_GET['oliforge_synced'] ) ) {
			return;
		}

		$count = absint( $_GET['oliforge_synced'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-success is-dismissible oliforge-auto-dismiss"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of sessions added to the log. */
					_n( '%d session added to the log.', '%d sessions added to the log.', $count, 'oliforge-session-control' ),
					$count
				)
			)
		);
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
	 * Inserts a session-log row the moment a new auth cookie (i.e. a new
	 * session token) is created. Fires for every login, "remember me" or
	 * not, and hands us the token directly — unlike `wp_login`, where the
	 * new cookie isn't yet reflected in the current request's $_COOKIE.
	 *
	 * @param string $auth_cookie Raw cookie value (unused).
	 * @param int    $expire      Login-grace-period expiry (unused).
	 * @param int    $expiration  Session expiration (unused).
	 * @param int    $user_id     User the session belongs to.
	 * @param string $scheme      Cookie scheme (unused).
	 * @param string $token       Session verifier token.
	 * @return void
	 */
	public function log_new_session( string $auth_cookie, int $expire, int $expiration, int $user_id, string $scheme, string $token ): void {
		unset( $auth_cookie, $expire, $expiration, $scheme );

		if ( $user_id <= 0 || '' === $token ) {
			return;
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$now = time();

		global $wpdb;
		$wpdb->insert(
			self::log_table(),
			array(
				'user_id'      => $user_id,
				'token'        => $token,
				'ip'           => mb_substr( $ip, 0, 100 ),
				'user_agent'   => mb_substr( $ua, 0, 255 ),
				'login_at'     => $now,
				'last_seen_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d' )
		);
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
		if ( $user_id <= 0 ) {
			return;
		}

		$now = $now ?? time();
		update_user_meta( $user_id, self::LAST_ACTIVITY_META, $now );

		// Also stamp the specific session row for this request, when we can
		// identify it — not available at the login moment itself (the new
		// cookie isn't in $_COOKIE yet), only on later requests.
		$token = wp_get_session_token();
		if ( '' !== $token ) {
			global $wpdb;
			$wpdb->update(
				self::log_table(),
				array( 'last_seen_at' => $now ),
				array(
					'user_id' => $user_id,
					'token'   => $token,
				),
				array( '%d' ),
				array( '%d', '%s' )
			);
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
