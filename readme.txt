=== Distan ===
Contributors: youheiokubo
Tags: static site generator, static export, headless, jamstack, deploy
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Author in WordPress, deliver static HTML — built for handing over files, not a CMS. No database, and no deploy credentials, in the deliverable.

== Description ==

Distan turns WordPress into a build environment for static HTML deliverables. You author in WordPress, then export clean, self-contained HTML that runs anywhere. No WordPress, no PHP, and no database are required on the production server.

Most static-export plugins mirror a *live* WordPress site and keep it running behind the scenes. Distan is built for the opposite case: the HTML files themselves are the deliverable, and WordPress stays on the developer's side and is never published. When an update is needed, you edit in WordPress, regenerate, and hand over the difference. (We think of it as "headloss": the head — the static HTML — goes to production, while the body — WordPress — stays in the workshop.)

This suits people who deliver *files*, not a CMS — for example a recruitment site placed under a subdirectory of an existing corporate site, where adding another WordPress install is not an option.

**How Distan is different**

* **It does not deploy for you, and holds no credentials.** Distan writes files and stops there. Deployment is wired through hooks (`distan_after_generate`, `distan_dispatch`), so you connect your own rsync, git push, FTP, or CI. Nothing to trust with your server keys.
* **It enumerates, it does not crawl.** Distan generates the pages WordPress already knows about, rather than spidering links. The result is predictable and light — no queue, no database tables, no build daemon.
* **It stays small.** No bundled framework, no vendor tree, no schema migrations. Easy to read, easy to audit, and nothing left behind when you remove it.

**What it does**

* Generates every published page, the posts archive with pagination, category archives with pagination, and a 404 page.
* Rewrites internal links as document-relative paths, so the output can be opened directly and placed in any directory.
* Flattens WordPress paths: theme files move to `assets/`, uploads move to `media/`. The `wp-content` directory disappears from the deliverable.
* Cleans the HTML: removes the generator tag, REST API links, oEmbed, emoji scripts, speculative loading rules, and the development `noindex`.
* Keeps cache-busting query strings on assets, so file names stay stable and can be overwritten over FTP.
* Optional `sitemap.xml` and a minimal `robots.txt`, built from the pages actually generated, using the production URL.
* Optional Markdown export (`content.md`) that combines every page's main content into one file — handy for feeding a site into AI tools (Gemini Notebook / NotebookLM, and similar).
* Optionally bundle extra files or directories that nothing links to (`distan_extra_assets`) — for assets referenced only from inside a script, such as a JSON file fetched by JavaScript.
* Reports a diff after each run: which files were added, and which need to be removed from production.
* Audits links and reports any whose target was not generated.
* Downloads the result as a ZIP.

**Requirements**

The only hard requirement is loopback HTTP (the site being able to request itself). No direct SQL, no `exec()`, no external binaries, no external APIs. It runs on Local, MAMP, XAMPP, DDEV, Docker, or shared hosting, and works offline.

**Not for every case**

Distan is not suitable when the client updates the site themselves, when forms, search, comments, or membership are required, or for sites of only a few pages. If you need built-in deployment to many destinations, scheduled or change-based publishing, or managed static hosting, a larger tool such as Simply Static or Staatic will fit better.

== Installation ==

1. Upload the plugin to `wp-content/plugins/`, or install the ZIP from Plugins → Add New → Upload.
2. Activate it.
3. Open the **Distan** menu, check the environment, set the public URL, and generate.

The output is written to `wp-content/uploads/distan/dist/`.

== Frequently Asked Questions ==

= Does the production server need WordPress? =

No. The output is static HTML. That is the point: there is no application to attack or maintain on the production server.

= How do I deploy the result? =

Distan does not deploy for you and never stores server credentials. It fires `distan_after_generate` when a run finishes, and an optional `distan_dispatch` action when you press the deploy button after reviewing the output. Hook either one to your own rsync, git push, FTP, or CI step. You can also just download the ZIP.

= Do template functions work? =

Yes. Generation sends a normal HTTP request per page, so `get_header()`, `get_template_part()`, template hierarchy, conditional tags, and custom fields all behave exactly as they do on the live site.

= What does not work? =

Anything that needs server-side processing: search forms, comment submission, contact forms, and membership. Values that depend on the current time (a copyright year printed with `date('Y')`, relative dates) are frozen at generation time.

= A script fetches a file (e.g. JSON) that is not being copied. Why? =

Distan collects assets by reading the generated HTML and CSS, so a path built inside a script — `fetch('../assets/json/data.json')` — is invisible to it and the file is left out. List the file or directory with the `distan_extra_assets` filter to bundle it anyway; its path is flattened the same way as other theme assets, so the relative fetch keeps working.

= Can I export the content for AI tools? =

Yes. Enable the Markdown export and Distan writes `content.md`, combining every page's main content into one file with production URLs — ready to hand to an AI notebook or a retrieval index. An optional `content.local.md` keeps development URLs.

= Can I open the generated files directly? =

Yes, with a classic theme. Block themes load their JavaScript as ES modules, which browsers refuse to load over `file://`. HTML, CSS, images, and links work either way; use a local server for a complete preview.

= Does it work with block (Full Site Editing) themes? =

Yes. Classic and block themes are both supported, including the block navigation's interactivity, importmap paths, and module files.

= A page changes its content based on a URL query parameter (?filter=chair). Can Distan handle it? =

Yes, with an explicit opt-in. Name the query keys that change the page with the distan_variant_keys filter, then register the specific URLs you want to ship with the distan_sources filter — usually built in a loop from the same terms or posts the page's pulldown or checkboxes are built from. Each value becomes its own static file. Because a plain static host addresses files by path only, the query is folded into the path: the published URL becomes /products/filter-chair/ rather than /products/?filter=chair. Distan does not discover these automatically — pulldown and multi-checkbox URLs are not links in the page, and their combinations are unbounded — so you decide which ones to generate. See README.md for copy-paste examples.

== Changelog ==

= 1.2.0 =
* Added: optional support for pages whose content changes with a URL query parameter (for example /guide/?tab=faq versus /guide/?tab=news). List the query keys that matter with the new distan_variant_keys filter, and each value is written as its own static file under a path like /guide/tab-faq/, with internal links to those URLs rewritten to match. Register the specific variant URLs through the existing distan_sources filter so they are discovered and generated. Off by default: with no keys listed, query strings on page URLs are dropped exactly as before, so nothing changes unless you opt in. Query keys you do not list (utm_source, fbclid and the like) are ignored, so this cannot multiply into unwanted files. The folded path format can be changed with the distan_query_variant_segment filter. Because a plain static host can only address files by path, the query has to be folded into the path — the published URL becomes /guide/tab-faq/ rather than /guide/?tab=faq.

= 1.1.9 =
* Added: a help tool pinned to the bottom-right of the Distan admin screen. A small "使い方" button opens a short panel that walks a first-time user through the flow — check environment, generate, then receive or deploy — with each step jumping to the matching section, plus pointers to where the detailed, contextual help already lives. It does not repeat that detail. Behaviour is open/close only (Alpine, already bundled); no new JavaScript file is added. This is admin-only UI — nothing in the generated output changes.
* Changed: simplified the admin masthead to a single calm line under the title, replacing the uppercase English tagline and the two-sentence lede. Admin-only cosmetic change; nothing in the generated output changes.
* Changed: updated the admin colour scheme to match the Distan book cover — a warm cream background with a brown accent on buttons and highlights, in place of the previous grey background and green accent. Admin-only; nothing in the generated output changes.

= 1.1.8 =
* Added: a sticky in-page navigation bar on the Distan admin screen. It follows the page as you scroll and jumps to the environment / generate / settings sections, with the current section highlighted as you move through them. This is admin-only UI — nothing in the generated output changes.

= 1.1.7 =
* Added: provenance on every generated entry. Each page now records what produced it (the post and its ID, the taxonomy term, the archive page), so the generation report names a change — for example "記事タイトル [投稿 #123]" — instead of only its file path. Removed pages keep their label, carried forward from the previous run. This is descriptive only: nothing is skipped or cached, and every page is still rendered on every run.
* Added: the distan_sources filter, a first-class way to register URLs that enumeration cannot discover on its own (plugin-generated routes, virtual pages). Entries are built with Distan_Collector::make_item(), merged before de-duplication, and carry provenance, so an added URL is counted and appears in the diff like any built-in page. This differs from distan_collect, which remains available for raw last-resort edits.
* Fixed: a URL added through the distan_collect filter could collide with a built-in page's output path and silently overwrite it, because the filter ran after de-duplication. The queue is now de-duplicated once more after the filter, so a collision drops the later entry instead of overwriting an already-generated file.
* Added: core-sitemap reconciliation. After a run, Distan reads WordPress core's own sitemap (wp-sitemap) in-process — no HTTP, no crawling — and lists in the report any URL the sitemap declares but the run did not generate, so a coverage gap is visible rather than silent. Optionally (filter distan_use_core_sitemap) those URLs can also seed the queue as a supplementary source; it stays a supplement, since core sitemaps honour noindex and can be disabled.

= 1.1.6 =
* Added: the distan_extra_assets filter, to bundle files or directories that no page links to. Distan collects assets by inspecting the generated HTML and CSS, so a file referenced only from inside a script (for example fetch('../assets/json/data.json')) is not found and is left out. Listing a file or directory here includes it anyway, through the same pipeline as linked assets: the theme (and uploads) path is flattened the same way, so a script that fetches a relative path keeps working; the file is recorded in the report and is never removed by the cleanup; executable extensions are still refused; and anything resolving outside the WordPress root is skipped. URLs inside these files are not rewritten, so they suit data files such as JSON. The default remains reference-based — nothing extra is copied unless you list it.

= 1.1.5 =
* Added: a guard against two people (or two browser tabs) starting a generation at the same time. Progress and output are tracked in a single shared job and written to one output directory, so overlapping runs previously corrupted each other. Starting a run while one is already in progress is now refused with a message naming who started it and when. A run that was abandoned mid-way (browser closed) is detected as stale after a couple of minutes (filter distan_job_stale_after) so it never blocks future runs.

= 1.1.4 =
* Fixed: on top-level pages, the import map address for block-theme modules (e.g. @wordpress/interactivity, used by the navigation block) was written as a bare relative path (wp-includes/…) with no ./ prefix. An import map address that is a relative URL must start with /, ./ or ../, so browsers treated the bare form as a null value and every module import on the page failed ("Failed to resolve module specifier … blocked by a null value"). Deeper pages already got a ../ prefix and worked; top-level pages now get ./ so interactive block-theme features work in relative-link mode too. Sites delivered with an absolute public URL were unaffected.

= 1.1.3 =
* Fixed: pages with a multibyte (e.g. Japanese) slug are now written to disk under their real decoded name (テスト/index.html) instead of a literal percent-encoded name (%E3%83%86…/index.html), while links keep the percent-encoded form. A browser or web server decodes an href before it looks for the file, so the previous literal name only matched when a URL was double-encoded: such pages opened by double-click but broke when reached through their own link (and were silently served by WordPress when the export happened to sit inside a live site). Assets already followed this rule; pages now match. The link audit decodes links before checking the filesystem so it does not report false broken links.

= 1.1.2 =
* Changed: the Markdown export (content.md) is now streamed one page at a time instead of collecting every page's text and joining it at the end. On sites with a very large number of pages this keeps memory flat and stops the job data from growing with page count, the same way binary assets were made stream-copied in 1.1.1. The output file is byte-for-byte identical to before.

= 1.1.1 =
* Changed: binary assets (images, PDFs, fonts, video) are now stream-copied instead of read fully into memory, so large files no longer inflate memory during generation. Stylesheets are still read in memory because their url() references are rewritten.
* Added: files above a size threshold (10 MB by default, filter distan_large_file_threshold) are listed in the report as "large files". They are still copied automatically; the list is a heads-up for delivery size and CDN decisions, not a manual step.

= 1.1.0 =
* Added: optional sitemap.xml generation. Built from the pages that were actually generated, using the production URL, in the standard format Google Search Console accepts. Author and date archives are never collected, so IDs like ?author=1 cannot leak into it.
* Added: sitemap exclusions by slug prefix (/private/ excludes everything under it) or substring (draft excludes any URL containing it), via a setting or the distan_sitemap_exclude filter.
* Added: optional minimal robots.txt (Allow: /, plus a Sitemap: line when the sitemap is enabled).
* The development-URL audit now also scans sitemap.xml and robots.txt, so a leftover development host in them is reported like anywhere else.
* The diff-based cleanup now keeps sitemap.xml, robots.txt and the Markdown files, so they are not removed on the next run.

= 1.0.0 =
* First stable release. Classic and block (FSE) themes are both supported and have been verified across the hard cases: internal links and multibyte slugs, JSON-LD and canonical/OGP, srcset and path flattening, CSS url() and webfonts, custom post types and fields, diff-based cleanup, Markdown export, hooks, and a safe uninstall that never touches the delivered files.

= 0.9.18 =
* Fixed: multibyte (e.g. Japanese) page slugs in internal links were corrupted (0x80-0x9F bytes turned into "_"), breaking the link. WordPress emits same-origin links as raw UTF-8; Distan now normalises every link path to a consistent percent-encoded form, so multibyte slugs survive intact in both the output filename and the rewritten link.
* Added: the generation report and the environment check now surface how many development-domain URLs remain in the output (e.g. in JSON-LD or canonical/OGP tags when the Production URL is unset), so a forgotten Production URL is caught before and after delivery.
* Fixed: the link audit reported existing pages with multibyte slugs as broken links, because it looked for a decoded name while the file is written with a percent-encoded name. The audit now matches the encoded form.
* Fixed: url() references inside external CSS were copied verbatim, so theme fonts/images went uncopied and uploads-absolute paths (url("/wp-content/uploads/…")) leaked into delivered files. CSS url() is now resolved, flattened (theme -> assets/, uploads -> media/), its targets copied, and the reference rewritten relative to the stylesheet.

= 0.9.17 =
* Added the `distan_dispatch` action hook and an optional manual "デプロイ" (dispatch) button on the generate screen. After reviewing the generated output, pressing the button fires `distan_dispatch` so a project can promote the reviewed build (git push, rsync, a build webhook). Distan stores no approval state — only the time of the last dispatch, shown on screen. The button is off by default; enable it in settings.

= 0.9.16 =
* Added the `distan_after_generate` action hook, fired when a generation run completes, for wiring up automatic deployment (git push, rsync, build webhooks, etc.).

= 0.9.15 =
* Redesigned the admin screen ("Distan Dispatch"): a packing-slip worksheet look with monospace figures for counts and file paths, a single stamp-green accent, and improved contrast.

= 0.9.14 =
* Added the `distan_url_replacements` filter for custom production URL replacements (applied across the whole output, including JSON-LD).
* Added an optional Markdown export (content.md) that combines every page's main content into one file for AI tools such as Gemini Notebook (formerly NotebookLM). URLs are rewritten to the production site URL; an optional content.local.md keeps development URLs.

= 0.9.13 =
* Initial public release.
