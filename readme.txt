=== Smart FAQ & Feedback ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A FAQ plugin: administrators manage questions and answers, visitors like or dislike individual answers.

== Description ==

Smart FAQ & Feedback adds a "Smart FAQ" menu to the WordPress dashboard. Each FAQ is a WordPress-native post (custom post type `sff_faq`) with categories (taxonomy `sff_faq_category`). The question is the title field, the answer is the standard WordPress editor.

Insert the `[smart_faq]` shortcode on any page or post (block editor, classic editor, Elementor shortcode widget, etc.) to show an accessible accordion. Every answer has Like and Dislike buttons with live counts, no page reload, no account required.

Features

* Add / edit / trash / permanently delete FAQs (with confirmation prompts), draft or publish.
* Categories, custom display order (drag and drop on the list screen, or the "Order" field), admin search, category filter, status counts (All / Published / Draft).
* Like and dislike totals shown per FAQ in the list table and on the edit screen.
* Smooth, keyboard-accessible accordion (ARIA, arrow keys / Home / End between questions).
* One reaction per visitor per FAQ; change it, or click it again to remove it.
* No external libraries, no external requests, no tracking. About 8 KB of vanilla JS and CSS, loaded only on pages that use the shortcode.
* Configurable helpfulness question and empty-state message (Smart FAQ → Settings).

== Installation ==

1. Download `smart-faq-feedback.zip`.
2. Open the WordPress Admin Dashboard.
3. Go to Plugins → Add New Plugin.
4. Click Upload Plugin.
5. Select `smart-faq-feedback.zip`.
6. Click Install Now.
7. Click Activate Plugin.
8. Open the **Smart FAQ** menu and add FAQs (question = title, answer = editor).
9. Create or edit a page and insert the `[smart_faq]` shortcode.
10. Publish the page and test the voting buttons.

No command-line access is needed. Requirements: WordPress 6.0+, PHP 7.4+ (PHP 8.1+ recommended), MySQL 5.7+/MariaDB 10.3+.

== Shortcode ==

`[smart_faq]`

All attributes are optional. Every value is validated; invalid values fall back to the default and unknown attributes are ignored.

* `category` – one or more category **slugs**, comma separated. Default: all. Example: `category="billing,general"`
* `orderby` – `menu_order`, `date`, `title`, `modified`, `rand`. Default: `menu_order`
* `order` – `ASC` or `DESC`. Default: `ASC`
* `limit` – maximum FAQs to show (1–500). Default: all (capped at 500, filter `sff_max_faqs`)
* `open` – `none`, `first` or `all`: which answers start expanded. Default: `none`
* `feedback` – `yes` or `no`: show the Like/Dislike buttons. Default: `yes`
* `heading_level` – 2 to 6: HTML heading level used for questions. Default: `3`

Examples:

`[smart_faq category="general"]`
`[smart_faq category="billing" orderby="menu_order" order="ASC"]`
`[smart_faq limit="10" open="first"]`

Only published, non-password-protected FAQs are displayed to visitors.

== How voting works (and its limits) ==

* The browser sends only the FAQ ID and "like" or "dislike" to the REST route `POST /wp-json/smart-faq-feedback/v1/vote`. Counts are never sent by the browser; the server recalculates them from database rows, so editing the JavaScript cannot change a count.
* Storage: custom table `{prefix}sff_feedback` with a UNIQUE key on (faq_id, voter_hash), so the database itself prevents duplicate votes. Indexes: `faq_voter` (unique) and `faq_reaction`.
* Visitor identification:
  * Logged-in users are identified by user ID, so their reaction follows them across devices.
  * Logged-out visitors get a random first-party cookie `sff_voter` (HttpOnly, SameSite=Lax, Secure on HTTPS, 1 year). The cookie is created only when the visitor clicks Like/Dislike.
  * Only a salted HMAC-SHA256 of that identifier is stored. No IP address, user agent or other personal data is stored.
* Abuse protection: input validation, only published FAQs accept votes, per-voter rate limit (default 20 votes/minute) and per-IP rate limit (default 100 votes/10 minutes; the IP is only used, hashed, as a temporary transient key). Limits are adjustable with the filters `sff_rate_limit_voter` and `sff_rate_limit_ip` (each returns `array( votes, seconds )`). Behind a reverse proxy/CDN, use the `sff_client_ip` filter to supply the real visitor IP.
* WordPress REST nonce (`X-WP-Nonce`) is sent so logged-in users are recognised. If the nonce on a cached page has expired, the script retries once as a guest. The nonce is NOT relied on for security of anonymous voting (public by design).
* **Limitations of anonymous voting:** a visitor can clear cookies, use another browser or private window, and vote again. Rate limiting only slows automated abuse. No anonymous system can fully prevent repeat voting without requiring accounts or collecting identifying data, which this plugin intentionally avoids. Treat counts as indicative feedback, not an audited poll.
* Privacy: the `sff_voter` cookie is a functional cookie set at the visitor's own request. Mention it in your privacy policy; whether it needs consent depends on your jurisdiction.
* Page caching: the script fetches fresh counts and the visitor's own reaction (`GET .../state`, sent with `Cache-Control: no-store`) after load, so cached pages stay accurate.

== Data handling ==

* Updating or deactivating the plugin never deletes FAQs, categories, settings or feedback.
* Permanently deleting an individual FAQ also deletes that FAQ's feedback rows.
* Deleting the plugin removes data **only** if you ticked "Delete ALL plugin data" under Smart FAQ → Settings (off by default). It then deletes FAQs, categories, the feedback table, settings and rate-limit transients.
* The schema version is stored in the `sff_db_version` option; the table is created/upgraded automatically with `dbDelta()`.

== Frequently Asked Questions ==

= Can editors manage FAQs? =
Yes. The FAQ post type uses the standard `post` capabilities: Contributors can draft, Authors/Editors/Administrators can publish. Only Administrators (`manage_options`) can open the Settings page.

= How do I reorder FAQs? =
On Smart FAQ → All FAQs, drag the handle in the first column (available while the list is sorted by Order with no search/filter active). Reordering moves rows among the order numbers they already occupy on the current page; raise "Number of items per page" in Screen Options for long lists. You can also set the "Order" field in the editor.

= The page shows an unstyled list. =
The shortcode loads its CSS/JS in the footer. Make sure your theme calls `wp_footer()`.

== Changelog ==

= 1.0.0 =
* Initial release.

== Testing notes (honest report) ==

A WordPress installation was NOT available in the environment where this plugin was built (WordPress.org and GitHub downloads were blocked), so the plugin was **not** activated inside a real WordPress/MySQL site. What was and was not verified:

Executed:

* `php -l` on every PHP file (PHP 8.3) – no syntax errors. `node --check` on both JavaScript files – OK.
* The real `SFF_Shortcode` class rendered against minimal WordPress function stubs: markup generated correctly, titles escaped, `<script>` in an answer stripped, empty-state output, and attribute validation (bad `orderby`/`order`/`open`/`heading_level` fall back to defaults; `limit` capped; category slugs sanitised; unknown attributes ignored).
* The real `frontend.css` and `frontend.js` ran in headless Chromium against a mock of the REST API (19 automated checks, all passing): accordion open/close and ARIA state, collapsed content not focusable, keyboard (Enter, Space, arrows, Home, End), visible focus ring, like, dislike, switching reaction updating both counts, removing a reaction, persistence after reload, repeated-click blocking while a request is in flight, loading/error/429/network-failure states, stale-nonce retry, fresh counts replacing cached markup, mobile (375 px) layout without horizontal scroll and 44 px touch targets, no-JavaScript fallback, no console errors.

NOT executed (needs a real WordPress + MySQL site; please run these after installing):

* Plugin activation/deactivation through the Plugins screen, `dbDelta` table creation, schema upgrade.
* Creating/editing/trashing/deleting FAQs, categories, admin list columns, drag-and-drop reorder AJAX, settings page, uninstall routine.
* The PHP REST handlers (`/vote`, `/state`), the SQL in `SFF_Database` (including `INSERT ... ON DUPLICATE KEY UPDATE`), cookie issuing, rate limiting, and logged-in vs logged-out voting against the real server code.
* Behaviour with specific themes, Elementor, caching plugins, multisite.

Suggested smoke test: activate; add 2 FAQs in a category; add `[smart_faq]` to a page; vote as a logged-in user and in a private window; confirm one row per voter in `{prefix}sff_feedback`; switch reaction; remove it; check Smart FAQ → All FAQs shows the counts.
