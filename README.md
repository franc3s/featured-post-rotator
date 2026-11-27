# Featured Post Rotator

A WordPress plugin that keeps a blog's "featured" blocks fresh by rotating which posts carry a tag, on a schedule, according to editorial criteria. Your theme keeps showing "posts with tag X" as it does today; the plugin only decides which posts have that tag. Theme templates are never modified.

> The admin interface is currently in Italian. An English source with an Italian translation is planned.

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

- WordPress with standard post tags
- PHP 7.4 or later

## Installation

1. Download the release zip, or zip the plugin folder so that it contains `featured-post-rotator/featured-post-rotator.php`.
2. In WordPress, go to Plugins → Add New → Upload Plugin, then upload and activate it.
3. Open **Rotazione Articoli → Impostazioni → Slot** and choose a tag for each slot. A slot without a tag never rotates.
4. Use **Anteprima** to check the next rotation before enabling the automatic (cron) rotation on a slot.

Slots start disabled. Nothing changes on your site until you run a rotation or enable the cron.

## Uninstall

Deactivating the plugin keeps all data. **Deleting** it from the Plugins screen runs `uninstall.php`, which removes all plugin options and the rotation history table. The tags already on your posts are left as they are.

## Hooks

- `bmfr_rotation_applied( $slot_key, $added, $removed, $trigger )`: fired whenever a slot's tags actually change.
- `bmfr_purge_page_cache`: filter. Return `false` to skip the built-in page-cache purge.

## License

Copyright (C) 2026 franc3s

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version. See [LICENSE](LICENSE) for the full text.
