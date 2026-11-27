# Featured Post Rotator

[![Latest release](https://img.shields.io/github/v/release/franc3s/featured-post-rotator)](https://github.com/franc3s/featured-post-rotator/releases/latest)
![WordPress 5.3+](https://img.shields.io/badge/WordPress-5.3%2B-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

A WordPress plugin that keeps a blog's "featured" blocks fresh by rotating which posts carry a tag, on a schedule, according to editorial criteria. Your theme keeps showing "posts with tag X" as it does today; the plugin only decides which posts have that tag. Theme templates are never modified.

> The admin interface is in English, with a complete Italian translation included (`languages/`). To add a language, start from `languages/featured-post-rotator.pot`.

## Features

- **Configurable slots.** Each slot is one featured block: a name, a tag (picked from the site's existing tags) and the number of posts it shows. Up to 10 slots.
- **Scored, weighted selection.** Candidates are scored on recency (with a configurable half-life), category balance, engagement (comment count) and seasonal fit. The weights are set per slot, and the draw is weighted rather than a strict top-N.
- **Editorial rules per slot.** Cadence, cooldown before a post can return, minimum and maximum post age (rolling window, fixed date or none), and excluded categories and posts. A post can't be featured in two slots at once.
- **Manual priority.** A shared priority per post plus a small per-slot adjustment, edited on a drag-and-drop ranking page.
- **Seasonal calendar.** Recurring events (keywords or tags plus a date window). Matching posts get a bonus inside the window and are excluded outside it.
- **Supervised workflow.** A preview (dry run) of the next rotation, an "Esegui ora" button for a supervised manual run, and a candidate tour to review posts once per slot.
- **History and notifications.** Full rotation history, an email summary after each rotation, and an optional Slack warning before an automatic rotation.
- **Safe on live sites.** Rotations are locked per slot, so they never run twice in parallel. After a change, the plugin purges the page cache of common cache plugins. Data upgrades run automatically and only once.

## Requirements

- WordPress 5.3 or later (tested up to 7.1), with standard post tags
- PHP 7.4 or later

## Installation

1. Download `featured-post-rotator-vX.Y.Z.zip` from the [latest release](https://github.com/franc3s/featured-post-rotator/releases/latest). Don't use GitHub's "Source code" zip: its folder name doesn't match the plugin, so WordPress would install it as a different plugin.
2. In WordPress, go to Plugins → Add New → Upload Plugin, then upload and activate it.
3. Open **Rotazione Articoli → Impostazioni → Slot** and choose a tag for each slot. A slot without a tag never rotates.
4. Use **Anteprima** to check the next rotation before enabling the automatic (cron) rotation on a slot.

Slots start disabled. Nothing changes on your site until you run a rotation or enable the cron.

## Uninstall

Deactivating the plugin keeps all data. **Deleting** it from the Plugins screen runs `uninstall.php`, which removes all plugin options and the rotation history table. The tags already on your posts are left as they are.

## Hooks

- `bmfr_rotation_applied( $slot_key, $added, $removed, $trigger )`: fired whenever a slot's tags actually change.
- `bmfr_purge_page_cache`: filter. Return `false` to skip the built-in page-cache purge.

## Changelog, contributing, security

- What changed in each version: [CHANGELOG.md](CHANGELOG.md)
- Bugs and ideas: [open an issue](https://github.com/franc3s/featured-post-rotator/issues/new/choose). See [CONTRIBUTING.md](.github/CONTRIBUTING.md) before sending a pull request.
- Security problems: report them privately, as described in [SECURITY.md](.github/SECURITY.md).

## License

Copyright (C) 2026 franc3s

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version. See [LICENSE](LICENSE) for the full text.
