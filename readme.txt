=== OliForge Session Control ===
Contributors: oliforge
Tags: session, security, login, authentication, timeout
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.0.0
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

The plugin uses the WordPress Settings API and native authentication hooks. It does not send data to external services.

Settings are available under Settings > OliForge Session Control, and the session log under Settings > OliForge Active Sessions.

== Installation ==

1. Upload the `oliforge-session-control` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Settings > OliForge Session Control.
4. Configure the session and idle timeout values.
5. Open Settings > OliForge Active Sessions to review and manage logged-in sessions.

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

= Are settings removed when the plugin is uninstalled? =

Yes. The plugin's settings option, activity user meta, and session-log database table are all removed during uninstall.

== Changelog ==

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

= 2.0.0 =
Adds a new Active Sessions log (own database table) with search, filtering, sorting, pagination, session termination and a redesigned admin UI. Creates a new database table on upgrade; existing settings are unaffected.

= 1.2.0 =
Adds WordPress 7.0 compatibility metadata, stricter validation, and uninstall cleanup.
