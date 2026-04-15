=== KO - Woo Role Tax Overrides ===
Contributors: KO
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later

Override WooCommerce tax rates for specific user roles, with admin tools and order audit visibility.

== Description ==

This plugin allows you to assign specific tax rates to selected WordPress user roles.
Only the roles you configure are overridden. All other users continue using standard WooCommerce tax behavior.

Features:
* Top-level admin menu: Role Based Taxes
* Menu position 57
* Submenus for Settings, Logs, and Debug
* Per-role tax rate overrides
* 0% tax support for tax-exempt roles
* Priority handling for users with multiple matching roles
* Optional cart and checkout notice
* Custom editable notice message
* Optional WooCommerce logging
* Order list column showing applied override
* Order detail audit meta box for clearer admin review

== Installation ==

1. Upload the plugin ZIP in WordPress
2. Activate the plugin
3. Go to Role Based Taxes > Settings
4. Add the roles and tax rates you want to override

== Changelog ==

= 1.1.2 =
* Added a settings field to customize the frontend notice message.

= 1.1.1 =
* Changed frontend notice rendering to print directly once per page context so Divi checkout templates display it reliably without duplication.

= 1.1.0 =
* Moved admin nav to a top-level menu
* Changed label to Role Based Taxes
* Set menu position to 57
* Added submenu structure: Settings, Logs, Debug
* Added active badge/count on settings page
* Added admin notice when overrides are configured
* Added order detail audit meta box
* Fixed repeated checkout notice behavior by using a one-time WooCommerce notice
