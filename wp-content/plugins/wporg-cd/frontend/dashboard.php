<?php
/**
 * Frontend Dashboard
 * 
 * Displays contributor analytics from pre-computed profiles.
 * 
 * Caching: Full HTML stored in wp_options. Regenerated only when
 * wporgcd_profiles_generated action fires (after profile generation).
 */

if (!defined('ABSPATH')) exit;

add_action('template_redirect', 'wporgcd_render_frontend_dashboard');
add_action('wporgcd_profiles_generated', 'wporgcd_generate_dashboard_cache');

/**
 * Get available date range filter options.
 * 
 * @return array Date range presets with label and days.
 */
function wporgcd_get_date_ranges() {
    return array(
        'last_30'  => array('label' => 'Last 30 days', 'days' => 30),
        'last_90'  => array('label' => 'Last 90 days', 'days' => 90),
        'last_180' => array('label' => 'Last 6 months', 'days' => 180),
        'last_365' => array('label' => 'Last year', 'days' => 365),
        'all_time' => array('label' => 'All time', 'days' => null),
    );
}

/**
 * Generate and cache dashboard HTML (all date range and status combinations).
 */
function wporgcd_generate_dashboard_cache() {
    $date_ranges = wporgcd_get_date_ranges();
    
    foreach ($date_ranges as $range_key => $range) {
        // Cache active-only version
        update_option(
            "wporgcd_dashboard_cache_{$range_key}",
            wporgcd_build_dashboard_html(false, $range_key),
            false
        );
        // Cache all (including inactive) version
        update_option(
            "wporgcd_dashboard_cache_{$range_key}_all",
            wporgcd_build_dashboard_html(true, $range_key),
            false
        );
    }
}

/**
 * Render the dashboard (serves cached HTML, or live with ?preview).
 */
function wporgcd_render_frontend_dashboard() {
    if (is_admin()) return;
    
    $is_admin = current_user_can('manage_options');
    $include_inactive = isset($_GET['all']) && $is_admin;
    
    // Validate and get date range parameter
    $date_ranges = wporgcd_get_date_ranges();
    $range_key = isset($_GET['range']) && array_key_exists($_GET['range'], $date_ranges) 
        ? $_GET['range'] 
        : 'all_time';
    
    // ?preview bypasses cache for testing (admins only)
    if (isset($_GET['preview']) && $is_admin) {
        echo wporgcd_build_dashboard_html($include_inactive, $range_key);
        exit;
    }
    
    // Serve cached version
    $cache_key = $include_inactive 
        ? "wporgcd_dashboard_cache_{$range_key}_all" 
        : "wporgcd_dashboard_cache_{$range_key}";
    $cached = get_option($cache_key);
    
    if ($cached) {
        echo $cached;
        exit;
    }
    
    // No cache - show message
    wp_die(
        '<h1>Contributor Dashboard</h1>' .
        '<p>Dashboard not yet generated.</p>' .
        '<p><a href="' . admin_url('admin.php?page=contributor-profiles') . '">Generate profiles</a> to build the dashboard.</p>',
        'Dashboard Not Ready',
        array('response' => 200)
    );
}
    
/**
 * Build dashboard HTML.
 * 
 * @param bool   $include_inactive Whether to include inactive contributors (default: false)
 * @param string $range_key        Date range filter key (default: 'all_time')
 */
function wporgcd_build_dashboard_html($include_inactive = false, $range_key = 'all_time') {
    global $wpdb;
    $profiles_table = wporgcd_get_table('profiles');
    
    // Get date range configuration
    $date_ranges = wporgcd_get_date_ranges();
    $range = $date_ranges[$range_key] ?? $date_ranges['all_time'];
    
    // Build filters
    $filters = wporgcd_build_profile_filters(array(
        'include_inactive' => $include_inactive,
        'range_days' => $range['days'],
    ));
    $status_filter = $filters['where'];
    $combined_filter_and = $filters['and'];
    
    $profile_count = $wpdb->get_var("SELECT COUNT(*) FROM $profiles_table" . $status_filter);
    $total_contributors = (int) $profile_count;
    $total_events = (int) $wpdb->get_var("SELECT SUM(total_events) FROM $profiles_table" . $status_filter);
    
    // Status breakdown
    $status_counts = $wpdb->get_results("SELECT status, COUNT(*) as count FROM $profiles_table" . $status_filter . " GROUP BY status");
    $active_contributors = $warning_contributors = $inactive_contributors = 0;
    foreach ($status_counts as $row) {
        switch ($row->status) {
            case 'active': $active_contributors = (int) $row->count; break;
            case 'warning': $warning_contributors = (int) $row->count; break;
            case 'inactive': $inactive_contributors = (int) $row->count; break;
        }
    }
    
    $avg_events = $wpdb->get_var("SELECT AVG(total_events) FROM $profiles_table" . $status_filter);
    $single_event = (int) $wpdb->get_var("SELECT COUNT(*) FROM $profiles_table WHERE total_events = 1" . $combined_filter_and);
    $ten_plus_events = (int) $wpdb->get_var("SELECT COUNT(*) FROM $profiles_table WHERE total_events > 10" . $combined_filter_and);
    
    // Date ranges for footer (from events table)
    $data_start_date = wporgcd_get_reference_start_date();
    $data_end_date = wporgcd_get_reference_end_date();
    
    // Ladder distribution
    $ladder_distribution = $wpdb->get_results(
        "SELECT current_ladder, COUNT(*) as count, 
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END) as warning_count,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_count
         FROM $profiles_table" . $status_filter . " GROUP BY current_ladder ORDER BY count DESC"
    );
    
    // Event distribution + first event counts
    $event_distribution = array();
    $first_event_counts = array();
    $profiles = $wpdb->get_results("SELECT event_counts FROM $profiles_table" . $status_filter);
    foreach ($profiles as $p) {
        $counts = json_decode($p->event_counts, true);
        if (is_array($counts)) {
            // Find the type with earliest first_date (this is the contributor's first event type)
            $earliest_type = null;
            $earliest_date = null;
            foreach ($counts as $type => $data) {
                $event_distribution[$type] = ($event_distribution[$type] ?? 0) + $data['count'];
                if ($earliest_date === null || $data['first_date'] < $earliest_date) {
                    $earliest_date = $data['first_date'];
                    $earliest_type = $type;
                }
            }
            if ($earliest_type) {
                $first_event_counts[$earliest_type] = ($first_event_counts[$earliest_type] ?? 0) + 1;
            }
        }
    }
    arsort($first_event_counts);
    $first_event_counts = array_slice($first_event_counts, 0, 10, true);
    
    $avg_time_to_first = $wpdb->get_var(
        "SELECT AVG(DATEDIFF(first_activity, registered_date))
         FROM $profiles_table 
         WHERE registered_date IS NOT NULL AND first_activity IS NOT NULL AND first_activity >= registered_date" . $combined_filter_and
    );
    
    // New contributors in last 30 days (based on registered_date relative to reference date)
    $reference_end = wporgcd_get_reference_end_date();
    $thirty_days_ago = date('Y-m-d', strtotime($reference_end . ' -30 days'));
    $new_contributors_30d = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $profiles_table WHERE registered_date >= %s" . $combined_filter_and,
        $thirty_days_ago
    ));
    
    // Year-over-year comparison: last 90 days vs same period last year
    // Only calculate if we have at least 1 year + 90 days of data
    $reference_start = wporgcd_get_reference_start_date();
    $has_yoy_data = strtotime($reference_end) - strtotime($reference_start) > (365 + 90) * 86400;
    
    $new_contributors_90d = 0;
    $new_contributors_90d_lastyear = 0;
    
    if ($has_yoy_data) {
        $ninety_days_ago = date('Y-m-d', strtotime($reference_end . ' -90 days'));
        // Count users who registered AND had first activity within the same 90-day period
        // This makes it a fair comparison (both periods have same "time to contribute")
        $new_contributors_90d = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $profiles_table 
             WHERE registered_date >= %s AND registered_date <= %s
             AND first_activity >= %s AND first_activity <= %s",
            $ninety_days_ago, $reference_end, $ninety_days_ago, $reference_end
        ));
        
        $last_year_end = date('Y-m-d', strtotime($reference_end . ' -1 year'));
        $last_year_start = date('Y-m-d', strtotime($last_year_end . ' -90 days'));
        $new_contributors_90d_lastyear = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $profiles_table 
             WHERE registered_date >= %s AND registered_date <= %s
             AND first_activity >= %s AND first_activity <= %s",
            $last_year_start, $last_year_end, $last_year_start, $last_year_end
        ));
        
    }
    
    $ladders = wporgcd_get_ladders();
    $event_types = wporgcd_get_event_types();
    
    // Build ladder stats
    $ladder_stats = array();
    foreach ($ladder_distribution as $row) {
        $id = $row->current_ladder ?: 'none';
        $ladder_stats[$id] = array(
            'count' => (int) $row->count,
            'active_count' => (int) $row->active_count,
            'warning_count' => (int) $row->warning_count,
            'inactive_count' => (int) $row->inactive_count,
            );
        }
        
    foreach (array_keys($ladders) as $lid) {
        if (isset($ladder_stats[$lid])) {
            $avg = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(DATEDIFF(%s, JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(ladder_journey, CONCAT('\$[', JSON_LENGTH(ladder_journey) - 1, ']')), '\$.step_joined'))))
                 FROM $profiles_table WHERE current_ladder = %s" . $combined_filter_and, $reference_end, $lid
            ));
            $ladder_stats[$lid]['avg_days'] = $avg ? round($avg) : 0;
        }
    }
    
    $css_url = plugin_dir_url(dirname(__FILE__)) . 'frontend/dashboard.css';
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
        <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Contributor Dashboard</title>
            <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">
        </head>
        <body>
    <div class="dash">
        <div class="header">
            <h1>Contributor Dashboard</h1>
            <div class="filters">
                <div class="range-filter">
                    <?php foreach ($date_ranges as $key => $r): 
                        $range_url = '?range=' . $key . ($include_inactive ? '&all' : '');
                    ?>
                    <a href="<?php echo esc_attr($range_url); ?>" class="<?php echo $range_key === $key ? 'active' : ''; ?>"><?php echo esc_html($r['label']); ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="toggle">
                    <?php $inactive_url = '?range=' . $range_key . ($include_inactive ? '' : '&all'); ?>
                    <a href="<?php echo esc_attr($inactive_url); ?>">
                        <span class="check <?php echo $include_inactive ? 'on' : ''; ?>"><?php if ($include_inactive): ?><svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 6l3 3 5-5"/></svg><?php endif; ?></span>
                        Include inactive users
                    </a>
                </div>
            </div>
        </div>
        
                <section>
                    <div class="grid-4">
                <div class="card stat">
                    <div class="stat-val blue"><?php echo number_format($total_events); ?></div>
                    <div class="stat-lbl">Total Events</div>
                    </div>
                <div class="card stat">
                    <div class="stat-val green"><?php echo number_format($total_contributors); ?></div>
                    <div class="stat-lbl">Contributors</div>
                        </div>
                <div class="card stat">
                    <div class="stat-val purple"><?php echo number_format($avg_events ?? 0, 1); ?></div>
                    <div class="stat-lbl">Avg Events/Contributor</div>
                        </div>
                <div class="card stat">
                    <div class="stat-val yellow"><?php echo number_format($single_event); ?></div>
                    <div class="stat-lbl">One-time Contributors</div>
                    <div class="stat-detail"><?php echo $total_contributors > 0 ? round(($single_event / $total_contributors) * 100) : 0; ?>% drop-off risk</div>
                        </div>
                    </div>
                </section>
                
        <?php if (!empty($ladders)): ?>
                <section>
                    <div class="card">
                        <h2>Contributor Progression</h2>
                            <div class="funnel">
                                <?php 
                    $lids = array_keys($ladders);
                    foreach ($lids as $i => $lid): 
                        $l = $ladders[$lid];
                        $s = $ladder_stats[$lid] ?? array('count' => 0, 'active_count' => 0, 'warning_count' => 0);
                        $cnt = $s['count'];
                        $pct = $total_contributors > 0 ? round(($cnt / $total_contributors) * 100) : 0;
                        $w = max(15, $pct);
                        $days = $s['avg_days'] ?? 0;
                    ?>
                    <div class="funnel-row">
                        <div class="funnel-lbl-wrap">
                            <span class="funnel-lbl"><?php echo esc_html($l['title']); ?></span>
                            <?php if (!empty($l['requirements'])): ?>
                            <span class="info-icon">i<span class="info-tip"><strong>Requires any of:</strong><?php 
                                foreach ($l['requirements'] as $req): 
                                    $et_title = $event_types[$req['event_type']]['title'] ?? $req['event_type'];
                                ?><span class="req">• <?php echo esc_html($et_title); ?> ≥ <?php echo (int) $req['min']; ?></span><?php endforeach; ?></span></span>
                            <?php endif; ?>
                        </div>
                        <div class="funnel-bar-wrap">
                            <div class="funnel-bar" style="width: <?php echo $w; ?>%"><?php echo number_format($cnt); ?></div>
                                            </div>
                        <div class="funnel-info">
                            <?php if ($cnt > 0): ?>
                                <span class="active"><?php echo $s['active_count']; ?> active</span>
                                <?php if ($s['warning_count'] > 0): ?><span class="risk"><?php echo $s['warning_count']; ?> at risk</span><?php endif; ?>
                                            <?php else: ?>
                                <span style="font-style: italic;">No contributors yet</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                    <?php if ($i < count($lids) - 1): 
                        $ns = $ladder_stats[$lids[$i + 1]] ?? array('count' => 0);
                        $conv = $cnt > 0 ? round(($ns['count'] / $cnt) * 100) : 0;
                    ?>
                    <div class="funnel-arrow">↓ <?php echo $conv; ?>% progress<?php if ($days > 0): ?> <span style="color: var(--light);">(~<?php echo $days; ?>d avg)</span><?php endif; ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                    <?php if (($ladder_stats['none']['count'] ?? 0) > 0): ?>
                    <div class="funnel-row" style="margin-top: 20px; opacity: 0.6;">
                        <span class="funnel-lbl" style="font-style: italic;">No Ladder</span>
                        <div class="funnel-bar-wrap">
                            <div class="funnel-bar" style="width: <?php echo max(15, round(($ladder_stats['none']['count'] / $total_contributors) * 100)); ?>%; background: var(--light);"><?php echo number_format($ladder_stats['none']['count']); ?></div>
                                        </div>
                        <div class="funnel-info"><span style="font-style: italic;">Haven't met requirements</span></div>
                            </div>
                    <?php endif; ?>
                </div>
                </div>
                </section>
        <?php endif; ?>
                
                <div class="grid-2">
                <?php if ($total_contributors > 0): ?>
                    <div class="card">
                        <h3>Key Insights</h3>
                <?php if ($avg_time_to_first !== null): ?>
                <div class="insight">
                    <span>Average <strong><?php echo round($avg_time_to_first); ?> days</strong> from account creation to first contribution.</span>
                    <span class="info-icon">i<span class="info-tip">Days between WordPress.org account registration and first recorded contribution event.</span></span>
                </div>
                <?php endif; ?>
                <div class="insight">
                    <span><strong><?php echo $active_contributors; ?></strong> contributors active (<?php echo round(($active_contributors / $total_contributors) * 100); ?>%).<?php if ($warning_contributors > 0): ?> <strong><?php echo $warning_contributors; ?></strong> at risk.<?php endif; ?></span>
                    <span class="info-icon">i<span class="info-tip"><strong>Active:</strong> contributed in the last 30 days.<br><strong>At risk:</strong> last activity was 30-90 days ago.</span></span>
                </div>
                <?php if ($ten_plus_events > 0): ?>
                <div class="insight">
                    <span><strong><?php echo number_format($ten_plus_events); ?></strong> contributors with 10+ contributions (<?php echo round(($ten_plus_events / $total_contributors) * 100); ?>%).</span>
                    <span class="info-icon">i<span class="info-tip">Contributors who have made more than 10 contribution events.</span></span>
                </div>
                <?php endif; ?>
                <?php if ($new_contributors_30d > 0): ?>
                <div class="insight">
                    <span><strong><?php echo number_format($new_contributors_30d); ?></strong> new contributors in the last 30 days.</span>
                    <span class="info-icon">i<span class="info-tip">Contributors whose first recorded activity was within the last 30 days of the data period.</span></span>
                </div>
                <?php endif; ?>
                <?php if ($new_contributors_90d_lastyear > 0): 
                    $yoy_change = $new_contributors_90d - $new_contributors_90d_lastyear;
                    $yoy_pct = round(($yoy_change / $new_contributors_90d_lastyear) * 100);
                    $yoy_direction = $yoy_change >= 0 ? 'up' : 'down';
                    $yoy_color = $yoy_change >= 0 ? 'var(--green)' : 'var(--red)';
                    $yoy_arrow = $yoy_change >= 0 ? '↑' : '↓';
                ?>
                <div class="insight">
                    <span><span style="color: <?php echo $yoy_color; ?>"><?php echo $yoy_arrow; ?> <?php echo abs($yoy_pct); ?>%</span> new contributors vs last year (last 90 days).</span>
                    <span class="info-icon">i<span class="info-tip">Compares users who registered AND made their first contribution within each 90-day period. This ensures a fair comparison by giving both periods the same "window of opportunity" to contribute.</span></span>
                </div>
                <?php endif; ?>
                    </div>
                <?php endif; ?>
                    
                    <div class="card">
                        <h3>First User Contribution</h3>
                        <?php if (!empty($first_event_counts)): 
                    $max_first = reset($first_event_counts); $r = 0;
                    foreach ($first_event_counts as $type => $first_cnt): $r++;
                        $title = $event_types[$type]['title'] ?? $type;
                        $p = $max_first > 0 ? round(($first_cnt / $max_first) * 100) : 0;
                        $total_cnt = $event_distribution[$type] ?? 0;
                ?>
                <div class="item">
                    <span class="item-rank"><?php echo $r; ?></span>
                    <span class="item-name"><?php echo esc_html($title); ?></span>
                    <span class="item-count"><?php echo number_format($first_cnt); ?></span>
                    <div class="bar-wrap"><div class="bar" style="width: <?php echo $p; ?>%"></div></div>
                    <span class="item-total" title="Total events of this type"><?php echo number_format($total_cnt); ?> total</span>
                                </div>
                <?php endforeach; endif; ?>
                    </div>
                </div>
                
        <div class="footer">
            <div style="margin-bottom: 8px;">
                <?php echo number_format($profile_count); ?> profiles<?php echo $include_inactive ? '' : ' (active only)'; ?><?php echo $range['days'] !== null ? ' · Registered: ' . esc_html($range['label']) : ''; ?>
                · Data: <?php echo date('M j, Y', strtotime($data_start_date)); ?> – <?php echo date('M j, Y', strtotime($data_end_date)); ?>
            </div>
            <a href="https://github.com/felipevelzani/wporg-contributor-dashboard" target="_blank">GitHub</a>
            <span style="margin: 0 8px;">·</span>
            Interested in contributing? <a href="https://make.wordpress.org/project/2024/12/19/contributor-working-group-update-december-2024/" target="_blank">Learn more</a>
                </div>
            </div>
        </body>
        </html>
        <?php
    return ob_get_clean();
}
