=== ScreamingRabbit Connector ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later

Connects a WordPress site to ScreamingRabbit (a Serhan Demirel product, https://serhandemirel.com).

== Description ==

Read-only. Reports WordPress, PHP and MySQL versions, plugins and themes with their update status, SEO and security
related settings and content counts to the ScreamingRabbit account it is paired with. Collects no personal data and
adds nothing to visitor pages. Every request is signed with a per-site secret (HMAC-SHA256) and can be used only once.

== Installation ==

1. In ScreamingRabbit, open the project's WordPress page and download the plugin.
2. In WordPress: Plugins → Add New → Upload Plugin, choose the ZIP and activate it.
3. Settings → ScreamingRabbit: paste the connection code from the same page and press "Bağlan".
