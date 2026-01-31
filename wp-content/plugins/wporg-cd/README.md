# Contributor Dashboard

Contributors: WordPress.org
Tags: contributors, dashboard, analytics
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin for tracking contributor activity and visualizing engagement across progression ladders.

## Description

The plugin uses a three-tier data model where each layer caches the computation of the previous:

```
Events (raw data)
    ↓ profile generation
Profiles (aggregated per-user)
    ↓ wporgcd_profiles_generated action
Dashboard HTML (pre-rendered, cached)
```

### Tier 1: Events

Raw activity records stored in `wp_wporgcd_events`. Each event has:
- `event_id` — Unique identifier (for deduplication)
- `contributor_id` — Username
- `event_type` — Activity type
- `event_created_date` — When it occurred

Events are immutable once imported. New event types are auto-created during import.

### Tier 2: Profiles

Aggregated data per contributor in `wp_wporgcd_profiles`. Computed from events via WP-Cron batches:
- Event counts by type
- Current ladder stage
- Activity status (active/warning/inactive)
- Ladder journey history

Profile generation runs asynchronously. When complete, fires `wporgcd_profiles_generated`.

### Tier 3: Dashboard

The complete frontend HTML (including CSS) is pre-generated and stored in `wp_options` as a single cache entry (`wporgcd_dashboard_cache`).

Cache is regenerated only when `wporgcd_profiles_generated` fires. Frontend requests serve the cached HTML directly — no database queries on page load.

## Status Thresholds

- **Active** — Last activity within 30 days
- **Warning** — Last activity 30-90 days ago
- **Inactive** — No activity for 90+ days

Status is calculated during profile generation relative to the **reference date** (the newest event date), not "today". This handles delayed imports correctly in case we take more time to import new events.

## Reference Date

All time-based calculations use `wporgcd_reference_date` (stored in wp_options) instead of the current date. This is set automatically from `MAX(event_created_date)` when profile generation starts.

This ensures that if you import December events in January, the status calculations use December as "now", not January.

## CSV Import Format

```
event_id,contributor_id,contributor_registered,event_type,event_date
unique-id-123,username,2024-01-15,support_reply,2024-06-20
```

## Hooks

- `wporgcd_profiles_generated` — Fires after profile generation completes
