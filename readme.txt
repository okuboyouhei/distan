=== Distan ===
Contributors: youheiokubo
Tags: static site generator, static, export, html, deploy
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.9.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Author in WordPress, deliver static HTML. A static site generator built for handing over HTML files, not a CMS.

== Description ==

Distan turns WordPress into a build environment for static HTML deliverables. You author in WordPress, then export clean, self-contained HTML that runs anywhere. No WordPress, no PHP, and no database are required on the production server.

Unlike static export plugins that mirror a live WordPress site, Distan is built for the case where the HTML files themselves are the deliverable. WordPress stays on the developer's side and is never published. When an update is needed, you edit in WordPress, regenerate, and hand over the difference.

This suits agencies that deliver HTML files rather than a CMS — for example recruitment sites placed under a subdirectory of an existing corporate site, where adding a WordPress install is not an option.

**What it does**

* Generates every published page, the posts archive with pagination, category archives with pagination, and a 404 page.
* Rewrites internal links as document-relative paths, so the output can be opened directly and placed in any directory.
* Flattens WordPress paths: theme files move to `assets/`, uploads move to `media/`. The `wp-content` directory disappears from the deliverable.
* Cleans the HTML: removes the generator tag, REST API links, oEmbed, emoji scripts, speculative loading rules, and the development `noindex`.
* Keeps cache-busting query strings on assets, so file names stay stable and can be overwritten over FTP.
* Reports a diff after each run: which files were added, and which need to be removed from production.
* Audits links and reports any whose target was not generated.
* Downloads the result as a ZIP.

**Requirements**

The only hard requirement is loopback HTTP (the site being able to request itself). No direct SQL, no `exec()`, no external binaries, no external APIs. It runs on Local, MAMP, XAMPP, DDEV, Docker, or shared hosting, and works offline.

**Not for every case**

Distan is not suitable when the client updates the site themselves, when forms, search, comments, or membership are required, or for sites of only a few pages.

== Installation ==

1. Upload the plugin to `wp-content/plugins/`, or install the ZIP from Plugins → Add New → Upload.
2. Activate it.
3. Open the **Distan** menu, check the environment, set the public URL, and generate.

The output is written to `wp-content/uploads/distan/dist/`.

== Frequently Asked Questions ==

= Does the production server need WordPress? =

No. The output is static HTML. That is the point: there is no application to attack or maintain on the production server.

= Do template functions work? =

Yes. Generation sends a normal HTTP request per page, so `get_header()`, `get_template_part()`, template hierarchy, conditional tags, and custom fields all behave exactly as they do on the live site.

= What does not work? =

Anything that needs server-side processing: search forms, comment submission, contact forms, and membership. Values that depend on the current time (a copyright year printed with `date('Y')`, relative dates) are frozen at generation time.

= Can I open the generated files directly? =

Yes, with a classic theme. Block themes load their JavaScript as ES modules, which browsers refuse to load over `file://`. HTML, CSS, images, and links work either way; use a local server for a complete preview.

== Changelog ==

= 0.9.11 =
* Initial public release.
* Added a warning when a page-cache plugin is active.
* Added a warning when the output contains ES modules that will not load over file://.
