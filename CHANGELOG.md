# Changelog

All notable changes to this module are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the versions follow
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-09-30

First release, for Magento Open Source and Adobe Commerce 2.3.0 to 2.4.8.

### Added

- Connection to Ovebot.ai through OAuth with PKCE, from **Connect** or **Start Free**; disconnect from the module
  page, which also takes the feed URL and the order endpoint credentials out of use. The tokens are stored encrypted
  and refreshed under a lock. No request reaches Ovebot.ai before an administrator connects the store.
- Module page under **Marketing > Ovebot AI > AI Chat Agent**: a setup wizard (connect account, website pages,
  products, go live), a dashboard and a settings page.
- Knowledge base: the chosen CMS pages are sent to the AI agent as knowledge base entries; saving or deleting one of
  them in Magento updates its entry.
- Product feed in JSON, protected by a hash in the URL: one item per orderable variant of a configurable product,
  prices in the display currency of the default store view, stock from MSI or the legacy inventory. The feed is kept
  for 30 minutes and rebuilt by the first request after that, without cron.
- Order lookup endpoint for the AI agent (status and shipment tracking), protected by HTTP Basic authentication, with
  a block of wrong credentials after 10 failed authentications from the same IP within an hour; the right
  credentials are never blocked.
- Shipment tracking from Magento's shipments, with built-in tracking pages for Sameday, FAN Courier, Cargus, DPD, GLS,
  FedEx, UPS, DHL and USPS; more templates and tracking sources through Stores > Configuration and `etc/di.xml`
  (`Ovebot\Chat\Api\TrackingFinderInterface`).
- Chat widget on every store view, left out of the checkout pages, compatible with the full page cache and Varnish;
  changes on the settings page reach the storefront at once. Purchase event on the order success page, a chat preview link from the admin panel and a
  Content Security Policy list for the storefront.
- Advanced options in **Stores > Configuration > Ovebot AI**: maximum order age, tracking sources, tracking URL
  templates, account and API hosts.
- Permissions for opening the module page, for managing the connection and settings, and for the advanced options.
- English and Romanian translations.
- Uninstall script for `bin/magento module:uninstall --remove-data`: disconnects the store, removes the tables, the
  saved options and the feed files.
