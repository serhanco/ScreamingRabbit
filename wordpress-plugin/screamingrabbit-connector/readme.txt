=== ScreamingRabbit Connector ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPL-2.0-or-later

Connects a WordPress site to ScreamingRabbit (a Serhan Demirel product, https://serhandemirel.com).

== Description ==

Reports WordPress, PHP and MySQL versions, plugins and themes with their update status, SEO and security
related settings, content counts, SEO plugin noindex/sitemap settings, Site Health warnings and core file
integrity (paths of changed core files) to the ScreamingRabbit account it is paired with. Collects no personal data and
adds nothing to visitor pages. Every request is signed with a per-site secret (HMAC-SHA256) and can be used only once.

Changes: off by default. When an administrator ticks "ScreamingRabbit can apply approved changes" under
Settings → ScreamingRabbit, the paired app can change a page's SEO title, meta description and noindex in Yoast SEO or
Rank Math, one approved change at a time. Each change states the value it replaces and is refused if that value has
changed; the last 200 changes are logged on the site. Nothing else can be changed.

When public content is published, updated or unpublished, the plugin sends that URL (and nothing else about the
post) to the paired app, which re-checks it within minutes.

New versions appear on the WordPress Updates screen once the site is paired; the package is downloaded from the
paired ScreamingRabbit app with a link signed by the site's secret.

== Installation ==

1. In ScreamingRabbit, open the project's WordPress page and download the plugin.
2. In WordPress: Plugins → Add New → Upload Plugin, choose the ZIP and activate it.
3. Settings → ScreamingRabbit: paste the connection code from the same page and press "Bağlan".
