<?php
/**
 * Session-log list table.
 *
 * @package OliForge_Session_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists every logged session — active or ended — from the plugin's own
 * session-log table, with search, role filtering, sorting and pagination.
 */
final class OliForge_Session_List_Table extends WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'session',
				'plural'   => 'sessions',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'          => '<input type="checkbox" />',
			'user'        => __( 'User', 'oliforge-session-control' ),
			'device'      => __( 'Device', 'oliforge-session-control' ),
			'ip'          => __( 'IP Address', 'oliforge-session-control' ),
			'status'      => __( 'Status', 'oliforge-session-control' ),
			'logged_in'   => __( 'Logged In', 'oliforge-session-control' ),
			'last_active' => __( 'Last Active', 'oliforge-session-control' ),
		);
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'logged_in'   => array( 'logged_in', true ),
			'last_active' => array( 'last_active', false ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'terminate' => __( 'Terminate Session', 'oliforge-session-control' ),
			'delete'    => __( 'Delete from List', 'oliforge-session-control' ),
		);
	}

	public function no_items(): void {
		esc_html_e( 'No sessions logged yet.', 'oliforge-session-control' );
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="session[]" value="%d" />', (int) $item->id );
	}

	protected function column_user( $item ): string {
		$user = get_userdata( (int) $item->user_id );

		if ( ! $user instanceof WP_User ) {
			return sprintf(
				'<div class="oliforge-session-user"><div class="oliforge-session-user__text"><em>%1$s</em><div>%2$s</div></div></div>',
				esc_html__( 'Deleted user', 'oliforge-session-control' ),
				$this->row_actions( $this->row_action_links( $item, false ), true )
			);
		}

		$is_you    = (int) get_current_user_id() === (int) $user->ID
			&& hash_equals( OliForge_Session_Control::instance()->current_session_hash(), (string) $item->session_hash );
		$you_badge = $is_you
			? ' <span class="oliforge-badge oliforge-badge--you">' . esc_html__( 'You', 'oliforge-session-control' ) . '</span>'
			: '';

		$is_active = OliForge_Session_Control::instance()->is_session_active( (int) $user->ID, (string) $item->session_hash );
		$role      = $this->primary_role_label( $user );

		return sprintf(
			'<div class="oliforge-session-user">%1$s<div class="oliforge-session-user__text"><strong>%2$s</strong>%3$s%4$s<div>%5$s</div>%6$s</div></div>',
			get_avatar( $user->ID, 32 ),
			esc_html( $user->display_name ),
			'' !== $role ? ' | ' . esc_html( $role ) : '',
			$you_badge,
			esc_html( $user->user_email ),
			$this->row_actions( $this->row_action_links( $item, $is_active ), true )
		);
	}

	/**
	 * Returns the user's first role, translated for display (e.g.
	 * "Administrator"), or an empty string if they have none.
	 */
	private function primary_role_label( WP_User $user ): string {
		if ( empty( $user->roles ) ) {
			return '';
		}

		$names = wp_roles()->get_names();
		$slug  = $user->roles[0];
		$label = $names[ $slug ] ?? $slug;

		return translate_user_role( $label );
	}

	/**
	 * Builds the "Terminate Session" (only while active) and
	 * "Delete from List" row-action links for one session row.
	 *
	 * @param object $item
	 * @return array<string, string>
	 */
	private function row_action_links( $item, bool $is_active ): array {
		$actions = array();

		if ( $is_active ) {
			$actions['terminate'] = sprintf(
				'<a href="%1$s" class="oliforge-terminate-link" data-oliforge-confirm="%2$s">%3$s</a>',
				esc_url( $this->action_url( 'oliforge_terminate', 'oliforge_terminate_session', (int) $item->id ) ),
				esc_attr__( 'Terminate this session? The user will be signed out on that device.', 'oliforge-session-control' ),
				esc_html__( 'Terminate Session', 'oliforge-session-control' )
			);
		}

		$actions['delete'] = sprintf(
			'<a href="%1$s" class="oliforge-dismiss-link" data-oliforge-confirm="%2$s">%3$s</a>',
			esc_url( $this->action_url( 'oliforge_delete', 'oliforge_delete_session', (int) $item->id ) ),
			esc_attr__( 'Remove this row from the list? This only affects the log — it does not sign the user out.', 'oliforge-session-control' ),
			esc_html__( 'Delete from List', 'oliforge-session-control' )
		);

		return $actions;
	}

	private function action_url( string $query_arg, string $nonce_action, int $row_id ): string {
		$url = add_query_arg(
			array(
				'page'      => OliForge_Session_Control::SESSIONS_PAGE_SLUG,
				$query_arg  => $row_id,
			),
			admin_url( 'options-general.php' )
		);

		return wp_nonce_url( $url, $nonce_action );
	}

	protected function column_device( $item ): string {
		$parsed = self::parse_user_agent( (string) $item->user_agent );

		return esc_html( $parsed['os'] . ' / ' . $parsed['browser'] );
	}

	protected function column_ip( $item ): string {
		return '' !== $item->ip ? esc_html( $item->ip ) : '&#8212;';
	}

	protected function column_status( $item ): string {
		$is_active = OliForge_Session_Control::instance()->is_session_active( (int) $item->user_id, (string) $item->session_hash );

		return $is_active
			? '<span class="oliforge-badge oliforge-badge--active">' . esc_html__( 'Active', 'oliforge-session-control' ) . '</span>'
			: '<span class="oliforge-badge oliforge-badge--ended">' . esc_html__( 'Ended', 'oliforge-session-control' ) . '</span>';
	}

	protected function column_logged_in( $item ): string {
		return $this->format_time_ago( (int) $item->login_at );
	}

	protected function column_last_active( $item ): string {
		$last = (int) $item->last_seen_at;

		return $this->format_time_ago( $last > 0 ? $last : (int) $item->login_at );
	}

	private function format_time_ago( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '&#8212;';
		}

		return esc_html(
			sprintf(
				/* translators: %s: human-readable time difference, e.g. "5 minutes". */
				__( '%s ago', 'oliforge-session-control' ),
				human_time_diff( $timestamp, time() )
			)
		);
	}

	/**
	 * Very small user-agent parser: enough to label the device/browser
	 * without pulling in a full UA-parsing library. Order matters — more
	 * specific patterns (Edge, Opera, Chrome-on-iOS, Firefox-on-iOS) are
	 * checked before the generic ones they'd otherwise also match.
	 *
	 * @return array{os: string, browser: string}
	 */
	private static function parse_user_agent( string $ua ): array {
		$os      = __( 'Unknown OS', 'oliforge-session-control' );
		$browser = __( 'Unknown browser', 'oliforge-session-control' );

		$os_patterns = array(
			'/windows nt/i' => 'Windows',
			'/iphone/i'     => 'iPhone',
			'/ipad/i'       => 'iPad',
			'/mac os x/i'   => 'Mac OS',
			'/android/i'    => 'Android',
			'/linux/i'      => 'Linux',
		);

		foreach ( $os_patterns as $pattern => $label ) {
			if ( preg_match( $pattern, $ua ) ) {
				$os = $label;
				break;
			}
		}

		$browser_patterns = array(
			'/edg\//i'   => 'Edge',
			'/opr\//i'   => 'Opera',
			'/crios/i'   => 'Chrome',
			'/chrome/i'  => 'Chrome',
			'/fxios/i'   => 'Firefox',
			'/firefox/i' => 'Firefox',
			'/safari/i'  => 'Safari',
		);

		foreach ( $browser_patterns as $pattern => $label ) {
			if ( preg_match( $pattern, $ua ) ) {
				$browser = $label;
				break;
			}
		}

		return array(
			'os'      => $os,
			'browser' => $browser,
		);
	}

	/**
	 * Renders the role-filter dropdown above the table.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$current = isset( $_REQUEST['role_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['role_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter value.
		?>
		<div class="alignleft actions">
			<select name="role_filter" id="oliforge-role-filter">
				<option value=""><?php esc_html_e( 'All roles', 'oliforge-session-control' ); ?></option>
				<?php foreach ( wp_roles()->get_names() as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>><?php echo esc_html( translate_user_role( $label ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'oliforge-session-control' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search value.
		$role    = isset( $_REQUEST['role_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['role_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'logged_in'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_REQUEST['order'] ) ? strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = OliForge_Session_Control::instance()->query_sessions(
			$search,
			$role,
			$orderby,
			$order,
			self::PER_PAGE,
			$this->get_pagenum()
		);

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $result['total'] / self::PER_PAGE ),
			)
		);
	}
}
