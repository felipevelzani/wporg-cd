=== Contributor Dashboard ===
Contributors: wordpressdotorg
Tags: contributors, dashboard, analytics, community
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin for tracking contributor activity and visualizing engagement across progression ladders.

== Description ==

The Contributor Dashboard plugin provides analytics and visualization for tracking contributor activity across your WordPress community.

= Features =

* Import contributor events from CSV files
* Define custom event types and progression ladders
* Automatic status tracking (active/warning/inactive)
* Pre-rendered dashboard for fast page loads
* Background processing via WP-Cron

= Architecture =

The plugin uses a three-tier data model where each layer caches the computation of the previous:

1. **Events** - Raw activity records
2. **Profiles** - Aggregated per-user data computed via WP-Cron
3. **Dashboard** - Pre-rendered HTML cached in wp_options

= Status Thresholds =

* **Active** — Last activity within 30 days
* **Warning** — Last activity 30-90 days ago
* **Inactive** — No activity for 90+ days

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wporg-cd/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to Contributors > Event Types to define your event types
4. Navigate to Contributors > Ladders to define progression ladders
5. Use Contributors > Import to import events from CSV

== Frequently Asked Questions ==

= What CSV format is expected? =

The CSV should have the following columns:
`event_id,contributor_id,contributor_registered,event_type,event_date`

= How is status calculated? =

Status is calculated during profile generation relative to the reference date (the newest event date), not "today". This handles delayed imports correctly.

= How do I regenerate profiles? =

Navigate to Contributors > Profiles and click "Start Generation". This runs asynchronously via WP-Cron.

== Changelog ==

= 1.0.0 =
* Initial release
