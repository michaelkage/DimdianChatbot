# WordPress.org release readiness

The installable plugin lives in `wordpress-plugin/chatbridge-widget/`. The plugin package workflow creates a ZIP from that directory only when a `chatbridge-widget-v*` tag is pushed. It does not publish to WordPress.org SVN.

## Prepared in the repository

- Plugin header metadata, GPL-2.0-or-later declaration, text domain and stable tag 0.4.0.
- WordPress readme with installation steps, FAQ, privacy notes and changelog.
- PHP 7.4-compatible plugin code and no runtime Composer/npm dependencies.
- Tenant API key stored in a separate non-autoload WordPress option; it is never localized to the public widget.
- Admin capability and nonce checks on the Meta completion relay.
- Privacy-policy content suggestion through WordPress core.
- Uninstall cleanup removes only plugin-owned WordPress options; remote ChatBridge tenant data is not deleted.
- Package ZIP workflow and a PHPCS / WordPress Coding Standards CI job.
- Stubbed adapter tests for WooCommerce order ownership and WCFM vendor attribution.

## Release blockers

Do not submit the plugin to WordPress.org until these items are complete:

1. Run the official WordPress Plugin Check plugin against the release ZIP in a real WordPress installation and resolve all errors/review warnings.
2. Test the plugin on a current WordPress release with supported PHP versions, WooCommerce, and WCFM Marketplace both enabled and disabled. The repository's PHP stubs are not a substitute for these integration tests.
3. Confirm the WordPress.org contributor username. The current readme contributor slug is a placeholder and must be replaced with the actual account.
4. Add real screenshots for the settings page, visitor widget, WooCommerce order lookup and Meta onboarding UI, using a non-production test site and non-customer data.
5. Publish final support and privacy-policy URLs. The privacy-policy suggestion is not a substitute for the hosted service's full privacy notice, data retention/deletion policy or processor disclosures.
6. Add a release-tested WordPress version to the readme only after the real test matrix passes.
7. Confirm the plugin slug, trademarks, author identity and directory submission details.
8. Review all remote API behavior, external script loading, security disclosures and data flows against the current WordPress.org guidelines.
9. Obtain explicit release approval before publishing or uploading to WordPress.org SVN.

## Current boundary

The package workflow is an artifact builder, not an automatic WordPress.org publisher. No WordPress.org account credentials or SVN deployment secrets are configured. The plugin is still a release candidate, not a directory-approved plugin.
