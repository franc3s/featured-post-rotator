=== Featured Post Rotator ===
Contributors: franc3s
Tags: featured posts, rotation, tags, editorial, scheduling
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.22.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps your featured blocks fresh by rotating which posts carry a tag, on a schedule and by editorial criteria. Theme templates are never modified.

== Description ==

Many themes show a featured block as "posts with tag X". Featured Post Rotator keeps that block fresh: it decides which posts carry the tag and rotates them on a schedule, according to the editorial rules you set. Your theme keeps working exactly as it does today.

**Configurable slots.** Each slot is one featured block: a name, a tag (picked from the tags already on your site) and the number of posts it shows. Up to 10 slots.

**Scored, weighted selection.** Candidates are scored on recency (with a configurable half-life), category balance, engagement (comment count) and seasonal fit. Weights are set per slot, and the draw is weighted rather than a strict top-N, so the same posts don't always win.

**Editorial rules per slot.** Cadence, a cooldown before a post can return, minimum and maximum post age (rolling window, fixed date or none), and excluded categories and posts. A post is never featured in two slots at once.

**Manual priority.** A shared priority per post plus a small per-slot adjustment, edited on a drag-and-drop ranking page.

**Seasonal calendar.** Recurring events defined by keywords or tags and a date window. Matching posts get a bonus inside the window and are left out outside it.

**Supervised workflow.** Preview the next rotation (dry run), run it manually with one click, or review candidates one by one with the candidate tour.

**History and notifications.** A full rotation history, an email summary after each rotation and an optional Slack warning before an automatic rotation.

**Safe on live sites.** Rotations are locked per slot, so they never run twice in parallel. After a change the plugin purges the page cache of common cache plugins. Data upgrades run automatically, once.

The admin interface is currently in Italian. An English interface with an Italian translation is planned.

= External services =

The plugin can send a message to **Slack** before an automatic rotation. This is off by default: nothing is sent until you paste a Slack incoming webhook URL in the settings.

Once a webhook URL is saved, a message is sent to it shortly before each automatic rotation of any slot with the cron enabled (and when you press the button to send a test message). The warning contains: your site title, the slot name, the scheduled time, a link to the preview page in your admin, and the titles and links of the posts expected to enter and leave the slot.

Slack is provided by Slack Technologies, LLC. [Terms of service](https://slack.com/terms-of-service), [privacy policy](https://slack.com/trust/privacy/privacy-policy).

== Installation ==

1. Upload the plugin from Plugins → Add New → Upload Plugin, or copy the `featured-post-rotator` folder to `wp-content/plugins/`.
2. Activate it.
3. Go to **Rotazione Articoli → Impostazioni → Slot** and choose a tag for each slot. A slot without a tag never rotates.
4. Use **Anteprima** to check the next rotation before enabling the automatic (cron) rotation on a slot.

Slots start disabled. Nothing changes on your site until you run a rotation or enable the cron.

== Frequently Asked Questions ==

= Does it change my theme? =

No. The plugin only adds and removes a tag on your posts. Your theme keeps showing "posts with tag X" as it does now.

= What happens when I deactivate the plugin? =

Nothing is deleted, and the tags stay on your posts as they are. Automatic rotations stop.

= What happens when I delete the plugin? =

Deleting it from the Plugins screen removes all plugin settings and the rotation history table. The tags already on your posts are left as they are.

= Can I run it on a schedule without checking every rotation? =

Yes. Enable the cron for a slot and set its cadence. You can still get an email summary after each rotation and, optionally, a Slack warning before it.

= Are there hooks for developers? =

* `bmfr_rotation_applied( $slot_key, $added, $removed, $trigger )` (action): fired whenever a slot's tags actually change.
* `bmfr_purge_page_cache` (filter): return `false` to skip the built-in page-cache purge.

== Screenshots ==

1. Priority and weights: drag-and-drop ranking of the candidates for a slot.
2. Preview: the posts that would enter and leave the slot in the next rotation.
3. Slot settings: name, tag, number of posts, cadence and editorial rules.
4. Rotation history.

== Changelog ==

= 1.22.4 =
* Plugin header: English description (with Italian translation), plugin and author URIs, minimum and tested WordPress versions.
* Added `readme.txt`.

= 1.22.3 =
* Priority page: with three or more slots, the column next to the rank shows the post's position in every other slot.

= 1.22.2 =
* Full GPL-2.0 license text.

= 1.22.0 =
* First release under the name Featured Post Rotator, with configurable slots and no site-specific defaults.

== Upgrade Notice ==

= 1.22.4 =
Documentation and metadata only. No change in behavior.
