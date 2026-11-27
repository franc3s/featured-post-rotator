# Changelog

All notable changes to Featured Post Rotator. Versions follow [semantic versioning](https://semver.org/).

## 1.22.4 — 2026-11-27

- Plugin header: English description (Italian translation in `languages/`), `Plugin URI`, `Author URI`, `Requires at least: 5.3`, `Tested up to: 7.1`, `Domain Path`.
- Added `readme.txt` in the WordPress.org format.
- No change in behavior.

## 1.22.3 — 2026-11-27

- **Priority page with three or more slots.** The column next to the rank used to show the post's position in only one other slot. It is now called "Altri slot" and lists the position in each of the other slots in compact form (for example `T3 Spo5 Spe–`, where `–` means not eligible). Hovering shows the full slot names, which are also read by screen readers.
- Sites with two slots see no change. With a single slot the column stays empty.

## 1.22.2 — 2026-11-27

- `LICENSE` now holds the full GPL-2.0 text. Added `License` and `License URI` to the plugin header.

## 1.22.1 — 2026-11-27

- Added `README.md` and `LICENSE`.

## 1.22.0 — 2026-11-27

First release under the name **Featured Post Rotator**. Earlier versions were developed privately for a single site.

- Plugin folder, main file and text domain are now `featured-post-rotator`. Options (`bmfr_*`), the history table and the `BMFR_` prefix are unchanged.
- No site-specific defaults: new installs get two empty slots, and the email subject uses the site title unless a name is set.

> **Upgrading from a version before 1.22.0:** WordPress sees the renamed plugin as a new one. Deactivate the old plugin and remove its folder by FTP or file manager. **Do not use "Delete" in WordPress**: it runs `uninstall.php` and erases settings and history. Then upload and activate the new zip.
