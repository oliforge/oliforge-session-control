=== OliForge Session Control ===
Contributors: oliforge
Tags: session, security, login, authentication, timeout
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage WordPress authentication cookie lifetimes and optionally log users out after inactivity.

== Description ==

OliForge Session Control lets administrators configure:

* Normal login session lifetime in hours.
* Remember Me session lifetime in days.
* Optional automatic logout after inactivity.
* Whether the settings apply to administrators, frontend users, or both.

The plugin uses the WordPress Settings API and native authentication hooks. It does not send data to external services.

Settings are available under Settings > OliForge Session Control.

== Installation ==

1. Upload the `oliforge-session-control` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Settings > OliForge Session Control.
4. Configure the session and idle timeout values.

== Frequently Asked Questions ==

= Does the plugin replace WordPress authentication? =

No. It only filters WordPress authentication cookie expiration and can log out inactive users.

= Does the plugin make external requests? =

No. The plugin does not connect to external services or transmit user data.

= What data does the plugin store? =

The plugin stores one settings option and, while idle logout is enabled, a last-activity timestamp in user meta.

= Are settings removed when the plugin is uninstalled? =

Yes. The plugin option and its activity user meta are removed during uninstall.

== Changelog ==

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

= 1.2.0 =
Adds WordPress 7.0 compatibility metadata, stricter validation, and uninstall cleanup.
