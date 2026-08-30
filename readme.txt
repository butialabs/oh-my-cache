=== Oh My Cache! ===
Contributors: butialabs
Tags: cache, nginx, redis, cdn, cloudflare
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Clears your NGINX or Redis page cache and then Cloudflare and retries anything that fails instead of losing it.

== Description ==

Clears the page cache on your own server first, then the copy Cloudflare holds at the edge. One
plugin, one list of URLs, so the two cannot disagree about what was cleared.

* NGINX FastCGI and proxy cache, by deleting the cache files or through ngx_cache_purge
* Redis page cache, using SCAN instead of a blocking KEYS sweep
* Cloudflare, one batch per API call, so a failed batch retries on its own
* A queue screen showing what was cleared, what failed, and who asked for it
* Sitemaps too, read from the index your site publishes rather than guessed from its name
* WooCommerce stock, prices and variations, which never fire a post save
* A switch per post type, for the ones whose changes should not clear anything
* A list of extra paths, such as /llms.txt, cleared alongside every purge
* A setup wizard that checks your token, finds your zone and adds two cache rules
* A public API for other plugins, and WP-CLI commands for deploys

== Installation ==

1. Upload the plugin and activate it.
2. Follow the wizard. It detects your NGINX cache folder and Redis, then connects Cloudflare.
3. The last step runs a real purge and tells you whether it worked.

= Keeping your Cloudflare token out of the database =

Add this to `wp-config.php`:

`define( 'OH_MY_CACHE_CF_API_TOKEN', 'your-token' );`

or set `OH_MY_CACHE_CF_API_TOKEN` in the environment. The token is then never written to the
database, so it stays out of backups and staging copies, and the settings screen stops offering
a field for it.

= If DISABLE_WP_CRON is set =

Queued retries need something to run them:

`* * * * * wp omc queue run --all --quiet`

Without a cron entry, anything that falls back to the queue never runs. The dashboard warns you
when that happens.

== Frequently Asked Questions ==

= Does it need the Redis extension? =

Only if you turn the Redis driver on. It needs phpredis 6.0 or newer and does not bundle a
pure-PHP replacement. When phpredis is missing, the driver says so instead of failing quietly.

= How long can a queued purge take? =

Up to about a minute. WordPress will not spawn cron more than once every 60 seconds, so a queued
job is never instant. Local cache clearing always runs immediately for that reason; only
Cloudflare gets deferred.

= Which sitemaps does it clear? =

Whichever your site publishes. Core, Yoast SEO, Rank Math, All in One SEO and SEOPress are
detected, and the index is read twice a day for the file names, so a news sitemap, a taxonomy one
or page two of a paginated one need no configuring. Editing a post clears its post type's files,
its taxonomies, the author file and the news sitemap, and leaves the other types alone.

The dashboard lists what was found. For anything undetected, put its index URL in the paths field
under When to purge, or use the `oh_my_cache_sitemap_urls` filter.

= Does it work with WooCommerce? =

Yes. WooCommerce writes stock, prices and variations straight to the database without saving the
post, so the plugin listens to WooCommerce's own hooks: a price change or the last item selling
out clears the product page, the shop page and the category archives.

The cart, checkout and account pages never reach the edge, and neither do cart fragments. Twice
over: the origin refuses to mark them cacheable, and the Cloudflare rule excludes their paths,
taken from your store's settings rather than the English defaults.

= Is it safe to cache HTML at the edge? =

Only once clearing demonstrably works, which is why the wizard refuses a non-zero edge TTL until
a test purge has actually succeeded. Pages held at the edge while clearing is broken stay stale
for the whole TTL, and a visitor cannot get past them.

== Changelog ==

= 0.1.0 =
* Initial release.

== Credits ==

Licensed GPL-3.0-or-later, because it derives from two GPL projects and one of them is GPLv3.

Nginx Helper by rtCamp, GPL-2.0-or-later, https://github.com/rtCamp/nginx-helper. Source of the
approach to clearing a local page cache: the nginx cache file path derivation, the ngx_cache_purge
alternative, the Redis key shape, and which WordPress events invalidate which URLs.

App for Cloudflare by Digital Point, GPL-3.0, https://wordpress.org/plugins/app-for-cf/. Source of
the approach to Cloudflare: the API client, resolving a zone from the hostname, the URLs a post
change invalidates, the guest-HTML cache rule and its s-maxage header, the static-content
extension list, and the recommended zone settings.

Both were reimplemented rather than copied. No code from App for Cloudflare Pro was used; it is
proprietary, and has no cache clearing logic in any case. Nothing third party is bundled.
