=== OliForge Session Control ===
Contributors: oliforge
Tags: session, security, login, authentication, timeout
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage WordPress authentication cookie lifetimes, optionally log users out after inactivity, and review a searchable log of every login.

== Description ==

OliForge Session Control lets administrators configure:

* Normal login session lifetime in hours.
* Remember Me session lifetime in days.
* Optional automatic logout after inactivity.
* Whether the settings apply to administrators, frontend users, or both.

It also adds an Active Sessions screen with:

* A running log of every login, kept in its own database table independent of WordPress's own session-token storage.
* Search by user, filter by role, sortable Logged In / Last Active columns, and pagination (20 per page).
* Terminate Session to sign a still-active device out immediately, available as a row action or bulk action.
* Delete from List to remove a row from the log only — it never signs anyone out.
* Sync current sessions, a one-click backfill for sessions that were already open before this feature was enabled, using WordPress's own stored login time, IP address and user agent.

On WordPress Multisite networks, network administrators also get:

* A Network Sessions screen (Network Admin > Settings) that aggregates every site's session log into one searchable, sortable, paginated table.
* Site, User, Device, IP, Status, Logged In and Last Active columns, with Terminate Session and Delete from List available per row and in bulk across sites.
* A warning notice if a network is too large to fully scan in one pass, instead of silently showing incomplete results.
* Every site keeps its own independent session log and settings; a single-site installation is completely unaffected by this feature and behaves exactly as before.

The plugin uses the WordPress Settings API and native authentication hooks. It does not send data to external services.

Settings are available under Settings > OliForge Session Control, and the session log under its Active Sessions tab.

== Installation ==

1. Upload the `oliforge-session-control` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Settings > OliForge Session Control.
4. Configure the session and idle timeout values.
5. Switch to the Active Sessions tab to review and manage logged-in sessions.
6. On a Multisite network, network administrators can also open Network Admin > Settings > OliForge Network Sessions for an aggregated, network-wide view.

== Frequently Asked Questions ==

= Does the plugin replace WordPress authentication? =

No. It only filters WordPress authentication cookie expiration and can log out inactive users.

= Does the plugin make external requests? =

No. The plugin does not connect to external services or transmit user data.

= What data does the plugin store? =

The plugin stores one settings option, a last-activity timestamp in user meta while idle logout is enabled, and a session-log database table recording each login's user, IP address, user agent, login time and last-seen time.

= Will sessions that existed before I updated the plugin show up in the log? =

Not automatically — the log only records logins from the moment this feature is active. Use "Sync current sessions" on the Active Sessions screen to backfill any sessions that were already open, using the login time, IP and user agent WordPress already stored for them.

= Does "Delete from List" sign the user out? =

No. It only removes that row from the log. Use "Terminate Session" to actually end a live session.

= Does this work on WordPress Multisite? =

Yes. Each site in the network keeps its own independent settings and session log, exactly as on a single-site install. Network administrators additionally get a Network Sessions screen under Network Admin > Settings that aggregates every site's log into one table. On a very large network, results may be capped per pass; a notice on the Network Sessions screen says so rather than showing an incomplete picture silently.

= Are settings removed when the plugin is uninstalled? =

Yes. The plugin removes its settings, legacy activity metadata, scheduled cleanup event, and session-log database table. On multisite, uninstall cleanup runs across all sites in the network.

== Changelog ==

= 2.2.1 =
* Removed "Active Sessions" as a separate item under the Settings menu; it remains reachable as a tab on the OliForge Session Control settings page.
* Hardened direct database queries (session log table name and dynamic IN()/ORDER BY clauses now use `$wpdb->prepare()`'s `%i` identifier placeholder) and corrected several PHPCS suppression comments that had drifted out of sync with the sniffs they were meant to silence.

= 2.2.0 =
* Store only SHA-256 session verifiers in the plugin log; legacy raw tokens are migrated and removed.
* Fix Active/Ended detection, session termination and current-session sync.
* Track idle activity per session instead of per user.
* Add multisite table lifecycle for network activation and new sites.
* Add configurable session-log retention with daily cleanup.
* Split session storage, repository, manager, idle-timeout, schema and admin responsibilities.

= 2.1.0 =
* Added a Network Sessions screen (Network Admin > Settings, multisite only) that aggregates every site's session log into one searchable, role-filterable, sortable, paginated table.
* Added a Site column and cross-site Terminate Session / Delete from List row and bulk actions, switching into each row's own site before acting on it.
* Added a "Network Sessions" tab, shown only to network administrators on a multisite install.
* Added a truncation warning on the Network Sessions screen if a network has more sites, or a site has more matching sessions, than a single pass can merge and sort.
* All multisite code is gated behind is_multisite() and never runs on a single-site install, which continues to behave exactly as before.

= 2.0.0 =
* Added a new Active Sessions screen backed by its own database table — a persistent login history independent of WordPress's own session-token storage.
* Added search by user, role filtering, sortable Logged In / Last Active columns, and pagination (20 sessions per page).
* Added Terminate Session (ends a live session) and Delete from List (removes only the log entry, never signs anyone out) as row and bulk actions.
* Added "Sync current sessions" to backfill sessions that were already active before this feature was installed, using WordPress's own stored login time, IP address and user agent.
* Added a Settings / Active Sessions tab bar shared by both admin screens.
* Converted the on/off settings to toggle switches and redesigned both admin screens with the OliForge brand system: header, cards, styled selects, buttons and self-dismissing notices.
* Extended uninstall cleanup to remove the new session-log database table.

= 1.2.0 =
* Declared compatibility with WordPress 7.0.
* Updated plugin headers and WordPress.org readme metadata.
* Hardened settings sanitization against malformed input.
* Added capability enforcement on the settings screen.
* Avoided idle checks during WordPress cron requests.
* Improved activity timestamp write throttling.
* Added uninstall cleanup for plugin settings and activity metadata.
* Updated code formatting and inline documentation for WordPress coding standards.

= 1.1.0 =
* Rebranded for OliForge.
* Hardened settings validation and redirect handling.
* Added safe upper limits for timeout values.
* Reduced unnecessary user-meta writes.
* Fixed logout activity cleanup.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.2.1 =
Active Sessions is no longer a separate Settings submenu item — find it as a tab on the OliForge Session Control settings page. No functional or data changes.

= 2.2.0 =
Security/session-model upgrade: raw session tokens are migrated to SHA-256 verifiers and removed; Active/Ended, Terminate and Sync are corrected; idle activity is tracked per session; multisite lifecycle and session-log retention are added.

= 2.1.0 =
Adds a Network Sessions aggregate screen for multisite networks (Network Admin > Settings). No effect on single-site installs, which behave exactly as before.

= 2.0.0 =
Adds a new Active Sessions log (own database table) with search, filtering, sorting, pagination, session termination and a redesigned admin UI. Creates a new database table on upgrade; existing settings are unaffected.

= 1.2.0 =
Adds WordPress 7.0 compatibility metadata, stricter validation, and uninstall cleanup.
