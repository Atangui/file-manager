=== Filora — File Manager & Secure Sharing ===
Contributors: Charles A.
Tags: file manager, share files, client portal, secure download, code editor
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modern, fast and secure file manager for WordPress, with Google-Drive-like private share links for clients and partners.

== Description ==

Filora lets you manage your WordPress files without FTP, and share files or folders with clients and partners through private links — without exposing anything else on your server.

It is written from scratch: no jQuery, no elFinder, and **no shell command is ever executed**.

= File manager =

* Fast single-page app (Preact, ~11 KB gzipped on load), light and dark themes, responsive.
* Virtualized list and grid views with image thumbnails — huge folders stay smooth.
* Drag & drop of files and whole folders, internal drag to move/copy, context menu, keyboard shortcuts, command palette (Ctrl/Cmd+K).
* Chunked uploads: PHP upload limits no longer cap file sizes. Upload from URL (admins).
* Code editor (WordPress' bundled CodeMirror, loaded on demand) with find & replace.
* PHP syntax check before saving, atomic saves, conflict detection, automatic version history with diff and restore.
* Private trash with one-click undo, ZIP compress/extract (Zip-Slip, symlink and zip-bomb safe), image tools (rotate, flip, resize, WebP/AVIF conversion), permissions editor, name and content search.
* Per-role profiles: own root folder (with placeholders such as {user_login}), allowed actions, hidden and read-only patterns, upload types, size limits and quotas.
* Activity log with CSV export, password confirmation ("sudo mode"), read-only mode, respects DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS.
* Security tools: WordPress core integrity check, PHP-in-uploads detection, recently modified files, disk usage, configuration score.
* Front-end file manager with the [filora] shortcode (client portals).

= Secure share links =

* Modes: view only, view & download, collaborate (upload), file request (upload only — visitors never see the folder content).
* Expiration, password, access restricted to email addresses (one-time codes), download limit, IP allow-list, notifications, QR code.
* Tokens are stored hashed, visitor sessions are signed, every request is rate limited, pages are never cached or indexed and never leak the URL through the Referer header.
* Link previewers (Slack, Teams, Outlook Safe Links…) never consume download limits.
* Files are always streamed through PHP with a sandboxing Content-Security-Policy: nothing shared can execute on your site.
* A protected "Vault" folder keeps confidential files unreachable by direct URL, with a server self-test and an Nginx snippet.

== Installation ==

1. Upload the plugin ZIP through Plugins → Add New → Upload Plugin, and activate it.
2. Open the new **Files** menu.
3. Visit **Files → Security tools** to check your setup.

For maximum security, store private data outside the web root by adding to wp-config.php:
`define( 'FILORA_PRIVATE_DIR', '/path/outside/webroot/filora-private' );`

== Frequently Asked Questions ==

= Can someone who has the link see my other files? =

No. A link only gives access to the shared file or folder. Sensitive files (wp-config.php, .env, database dumps, keys, .git…) and hidden files are never served, even inside a shared folder, and WordPress system folders cannot be shared.

= My server uses Nginx. =

Nginx ignores .htaccess files. Add the rule shown in Files → Security tools to block direct access to the Vault and the private storage.

= Does Filora call external services? =

Only the optional core integrity check contacts api.wordpress.org. There are no external fonts, CDNs or trackers.

== Changelog ==

= 1.0.3 =
* Author information updated.

= 1.0.2 =
* Role templates (View & download, Document drop, Document manager, Personal space); contributors get "Document drop" on new installs.
* New "replace" permission: update a document in place (same web address), previous version kept. Overwriting now always needs replace/delete rights.
* Settings export / import between sites; warnings for roles given to self-registered accounts.
* The app now talks to the server in the user's language.

= 1.0.1 =
* Public share page redesigned for non-technical visitors; follows the visitor's language.
* Security: script-capable types checked on every extension segment, trailing dots refused, ZIP extraction follows role rules, thumbnails disabled on download-limited links, 8-character share passwords.
* Visible theme switch, Quick access (Vault, favorites) and Recent folders in the sidebar; lasso selection; keyboard shortcuts sheet.

= 1.0.0 =
* Initial release.
