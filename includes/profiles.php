<?php
/**
 * Contributor Profiles
 * 
 * Manages the wporgcd_profiles table which stores aggregated user data
 * computed asynchronously from raw events.
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// DATABASE SETUP
// ============================================================================

/**
 * Create the wporgcd_profiles table
 */
function wporgcd_create_profiles_table() {
    global $wpdb;
    $table_name = wporgcd_get_table('profiles');
    $charset_collate = $wpdb->get_charset_collate();
    
    // Profile structure:
    // - user_id: The contributor's WordPress.org user ID/username
    // - registered_date: When the user account was created on WP.org
    // - ladder_journey: JSON array tracking progression through ladder steps
    //   [
    //     {
    //       "ladder_id": "connect",
    //       "step_joined": "2024-01-15T10:30:00Z",    // When they reached this step
    //       "step_left": "2024-03-20T14:22:00Z",      // When they moved to next step (null if current)
    //       "time_in_step_days": 64,                   // Calculated days in this step
    //       "first_event_id": "support-reply-12345",   // First qualifying event
    //       "first_event_type": "support_reply",
    //       "first_event_date": "2024-01-15T10:30:00Z",
    //       "last_event_id": "support-reply-67890",    // Last event before moving
    //       "last_event_type": "support_reply",
    //       "last_event_date": "2024-03-20T14:22:00Z",
    //       "events_in_step": 47,                      // Total events while in this step
    //       "requirement_met": {                       // Which requirement qualified them
    //         "event_type": "support_reply",
    //         "min": 5,
    //         "achieved": 5
    //       }
    //     },
    //     ...
    //   ]
    // - event_counts: JSON object with event type counts and dates
    //   {
    //     "support_reply": {
    //       "count": 47,
    //       "first_date": "2024-01-15T10:30:00Z",
    //       "last_date": "2024-06-15T09:15:00Z"
    //     },
    //     "trac_ticket": {
    //       "count": 12,
    //       "first_date": "2024-02-01T11:00:00Z",
    //       "last_date": "2024-05-20T16:45:00Z"
    //     }
    //   }
    // - current_ladder: The current/highest ladder step achieved
    // - total_events: Quick count of all events
    // - first_activity: Date of first recorded event
    // - last_activity: Date of most recent event
    // - status: Activity status based on last_activity
    //   - 'active': Last activity within 30 days
    //   - 'warning': Last activity 30-90 days ago
    //   - 'inactive': Last activity more than 90 days ago
    // - profile_computed_at: When this profile was last computed/updated
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id varchar(255) NOT NULL,
        registered_date datetime DEFAULT NULL,
        ladder_journey longtext DEFAULT NULL,
        event_counts longtext DEFAULT NULL,
        current_ladder varchar(100) DEFAULT NULL,
        total_events int(11) DEFAULT 0,
        first_activity datetime DEFAULT NULL,
        last_activity datetime DEFAULT NULL,
        status varchar(20) DEFAULT 'inactive',
        profile_computed_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_id (user_id),
        KEY current_ladder (current_ladder),
        KEY registered_date (registered_date),
        KEY last_activity (last_activity),
        KEY status (status),
        KEY profile_computed_at (profile_computed_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// ============================================================================
// CONFIGURATION CONSTANTS
// ============================================================================

// Status thresholds (in days)
define('WPORGCD_STATUS_ACTIVE_DAYS', 30);
define('WPORGCD_STATUS_WARNING_DAYS', 90);

// ============================================================================
// STATUS HELPERS
// ============================================================================

/**
 * Compute status from last activity date
 * 
 * @param string|null $last_activity DateTime string or null
 * @return string 'active', 'warning', or 'inactive'
 */
function wporgcd_compute_status($last_activity) {
    if (empty($last_activity)) {
        return 'inactive';
    }
    
    $reference_time = strtotime(wporgcd_get_reference_end_date());
    $last_activity_time = strtotime($last_activity);
    $days_since_activity = ($reference_time - $last_activity_time) / DAY_IN_SECONDS;
    
    if ($days_since_activity <= WPORGCD_STATUS_ACTIVE_DAYS) {
        return 'active';
    } elseif ($days_since_activity <= WPORGCD_STATUS_WARNING_DAYS) {
        return 'warning';
    } else {
        return 'inactive';
    }
}

// ============================================================================
// ASYNC PROFILE COMPUTATION (WP-Cron based)
// ============================================================================

// Batch size for processing profiles per cron run
define('WPORGCD_PROFILE_BATCH_SIZE', 500);

// Register WP-Cron hook
add_action('wporgcd_cron_process_profiles', 'wporgcd_process_profile_batch');

/**
 * Process a batch of profiles
 */
function wporgcd_process_profile_batch() {
    global $wpdb;
    $events_table = wporgcd_get_table('events');
    $profiles_table = wporgcd_get_table('profiles');
    
    // Check if there's an active generation
    $state = get_option('wporgcd_profile_generation_state');
    if (!$state || $state['status'] !== 'processing') {
        return;
    }
    
    // Build the registration date filter
    $date_filter = '';
    if (!empty($state['min_registered_date'])) {
        $date_filter = $wpdb->prepare(" AND e.contributor_created_date >= %s", $state['min_registered_date']);
    }
    
    // Get batch of unique contributor IDs that need profile updates
    // Only include users who have at least one event that isn't 'updated_profile'
    $user_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT e.contributor_id 
         FROM $events_table e
         LEFT JOIN $profiles_table p ON e.contributor_id = p.user_id
         WHERE (p.id IS NULL OR e.event_created_date > p.profile_computed_at)
         AND e.event_type != 'updated_profile'
         $date_filter
         LIMIT %d",
        WPORGCD_PROFILE_BATCH_SIZE
    ));
    
    if (empty($user_ids)) {
        // All done!
        $state['status'] = 'completed';
        $state['completed_at'] = current_time('mysql');
        update_option('wporgcd_profile_generation_state', $state);
        wp_clear_scheduled_hook('wporgcd_cron_process_profiles');
        
        // Fire action to regenerate dashboard cache
        do_action('wporgcd_profiles_generated');
        
        return;
    }
    
    $batch_processed = 0;
    
    // Process each user in this batch
    foreach ($user_ids as $user_id) {
        wporgcd_compute_user_profile($user_id);
        $batch_processed++;
    }
    
    // Update state
    $state['processed'] += $batch_processed;
    update_option('wporgcd_profile_generation_state', $state);
    
    // Schedule next batch in 1 second
    if (!wp_next_scheduled('wporgcd_cron_process_profiles')) {
        wp_schedule_single_event(time() + 1, 'wporgcd_cron_process_profiles');
    }
}

/**
 * Compute profile for a single user (called directly, not scheduled)
 */

function wporgcd_compute_user_profile($user_id) {
    global $wpdb;
    $events_table = wporgcd_get_table('events');
    $profiles_table = wporgcd_get_table('profiles');
    
    // Event types to ignore when computing profiles
    $ignored_event_types = array('updated_profile');
    $ignored_placeholders = implode(',', array_fill(0, count($ignored_event_types), '%s'));
    
    // Get all events for this user, ordered by date (excluding ignored types)
    $query_args = array_merge(array($user_id), $ignored_event_types);
    $events = $wpdb->get_results($wpdb->prepare(
        "SELECT event_id, event_type, event_created_date, contributor_created_date
         FROM $events_table 
         WHERE contributor_id = %s 
         AND event_type NOT IN ($ignored_placeholders)
         ORDER BY event_created_date ASC",
        $query_args
    ));
    
    if (empty($events)) {
        return; // No events for this user
    }
    
    // Get registered date from first event that has it
    $registered_date = null;
    foreach ($events as $event) {
        if (!empty($event->contributor_created_date)) {
            $registered_date = $event->contributor_created_date;
            break;
        }
    }
    
    // Build event counts
    $event_counts = array();
    foreach ($events as $event) {
        $type = $event->event_type;
        if (!isset($event_counts[$type])) {
            $event_counts[$type] = array(
                'count' => 0,
                'first_date' => $event->event_created_date,
                'last_date' => $event->event_created_date,
            );
        }
        $event_counts[$type]['count']++;
        $event_counts[$type]['last_date'] = $event->event_created_date;
    }
    
    // Build ladder journey
    $ladder_journey = wporgcd_compute_ladder_journey($events, $event_counts);
    
    // Determine current ladder (last in journey)
    $current_ladder = !empty($ladder_journey) ? end($ladder_journey)['ladder_id'] : null;
    
    // Get activity dates
    $first_activity = $events[0]->event_created_date;
    $last_activity = end($events)->event_created_date;
    $total_events = count($events);
    
    // Compute status based on last activity
    $status = wporgcd_compute_status($last_activity);
    
    // Upsert profile
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $profiles_table WHERE user_id = %s",
        $user_id
    ));
    
    $profile_data = array(
        'user_id' => $user_id,
        'registered_date' => $registered_date,
        'ladder_journey' => wp_json_encode($ladder_journey),
        'event_counts' => wp_json_encode($event_counts),
        'current_ladder' => $current_ladder,
        'total_events' => $total_events,
        'first_activity' => $first_activity,
        'last_activity' => $last_activity,
        'status' => $status,
        'profile_computed_at' => current_time('mysql'),
    );
    
    if ($existing) {
        $wpdb->update(
            $profiles_table,
            $profile_data,
            array('id' => $existing),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'),
            array('%d')
        );
    } else {
        $wpdb->insert(
            $profiles_table,
            $profile_data,
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );
    }
}

/**
 * Compute ladder journey from events
 * 
 * @param array $events Array of event objects (sorted by date)
 * @param array $event_counts Running event counts by type
 * @return array Ladder journey array
 */
function wporgcd_compute_ladder_journey($events, $event_counts) {
    $ladders = wporgcd_get_ladders();
    
    if (empty($ladders)) {
        return array();
    }
    
    $journey = array();
    $running_counts = array(); // Running count as we iterate through events
    $current_ladder_index = -1;
    $ladder_keys = array_keys($ladders);
    
    // Track events per ladder step
    $step_events = array();
    $step_first_event = array();
    $step_last_event = array();
    
    foreach ($events as $event) {
        $type = $event->event_type;
        
        if (!isset($running_counts[$type])) {
            $running_counts[$type] = 0;
        }
        $running_counts[$type]++;
        
        // Check if user qualifies for next ladder
        $next_ladder_index = $current_ladder_index + 1;
        
        if ($next_ladder_index < count($ladder_keys)) {
            $next_ladder_id = $ladder_keys[$next_ladder_index];
            $next_ladder = $ladders[$next_ladder_id];
            $requirement_met = wporgcd_check_ladder_requirements($next_ladder, $running_counts);
            
            if ($requirement_met) {
                // Close previous ladder step if exists
                if ($current_ladder_index >= 0) {
                    $prev_ladder_id = $ladder_keys[$current_ladder_index];
                    $journey_index = count($journey) - 1;
                    
                    $journey[$journey_index]['step_left'] = $event->event_created_date;
                    $journey[$journey_index]['last_event_id'] = $step_last_event[$prev_ladder_id]['event_id'] ?? null;
                    $journey[$journey_index]['last_event_type'] = $step_last_event[$prev_ladder_id]['event_type'] ?? null;
                    $journey[$journey_index]['last_event_date'] = $step_last_event[$prev_ladder_id]['event_date'] ?? null;
                    $journey[$journey_index]['events_in_step'] = $step_events[$prev_ladder_id] ?? 0;
                    
                    // Calculate time in step
                    if (!empty($journey[$journey_index]['step_joined'])) {
                        $joined = strtotime($journey[$journey_index]['step_joined']);
                        $left = strtotime($event->event_created_date);
                        $journey[$journey_index]['time_in_step_days'] = round(($left - $joined) / DAY_IN_SECONDS);
                    }
                }
                
                // Start new ladder step
                $current_ladder_index = $next_ladder_index;
                $step_events[$next_ladder_id] = 1;
                $step_first_event[$next_ladder_id] = array(
                    'event_id' => $event->event_id,
                    'event_type' => $event->event_type,
                    'event_date' => $event->event_created_date,
                );
                $step_last_event[$next_ladder_id] = array(
                    'event_id' => $event->event_id,
                    'event_type' => $event->event_type,
                    'event_date' => $event->event_created_date,
                );
                
                $journey[] = array(
                    'ladder_id' => $next_ladder_id,
                    'step_joined' => $event->event_created_date,
                    'step_left' => null,
                    'time_in_step_days' => null,
                    'first_event_id' => $event->event_id,
                    'first_event_type' => $event->event_type,
                    'first_event_date' => $event->event_created_date,
                    'last_event_id' => null,
                    'last_event_type' => null,
                    'last_event_date' => null,
                    'events_in_step' => 0,
                    'requirement_met' => $requirement_met,
                );
                
                // Continue checking if they immediately qualify for more ladders
                // (in case they already have enough events)
                while ($current_ladder_index + 1 < count($ladder_keys)) {
                    $check_index = $current_ladder_index + 1;
                    $check_ladder_id = $ladder_keys[$check_index];
                    $check_ladder = $ladders[$check_ladder_id];
                    $check_requirement = wporgcd_check_ladder_requirements($check_ladder, $running_counts);
                    
                    if ($check_requirement) {
                        // Immediately transition to next ladder
                        $journey[count($journey) - 1]['step_left'] = $event->event_created_date;
                        $journey[count($journey) - 1]['last_event_id'] = $event->event_id;
                        $journey[count($journey) - 1]['last_event_type'] = $event->event_type;
                        $journey[count($journey) - 1]['last_event_date'] = $event->event_created_date;
                        $journey[count($journey) - 1]['events_in_step'] = 1;
                        $journey[count($journey) - 1]['time_in_step_days'] = 0;
                        
                        $current_ladder_index = $check_index;
                        $step_events[$check_ladder_id] = 1;
                        $step_first_event[$check_ladder_id] = array(
                            'event_id' => $event->event_id,
                            'event_type' => $event->event_type,
                            'event_date' => $event->event_created_date,
                        );
                        $step_last_event[$check_ladder_id] = array(
                            'event_id' => $event->event_id,
                            'event_type' => $event->event_type,
                            'event_date' => $event->event_created_date,
                        );
                        
                        $journey[] = array(
                            'ladder_id' => $check_ladder_id,
                            'step_joined' => $event->event_created_date,
                            'step_left' => null,
                            'time_in_step_days' => null,
                            'first_event_id' => $event->event_id,
                            'first_event_type' => $event->event_type,
                            'first_event_date' => $event->event_created_date,
                            'last_event_id' => null,
                            'last_event_type' => null,
                            'last_event_date' => null,
                            'events_in_step' => 0,
                            'requirement_met' => $check_requirement,
                        );
                    } else {
                        break;
                    }
                }
            }
        }
        
        // Track events for current ladder step
        if ($current_ladder_index >= 0) {
            $current_ladder_id = $ladder_keys[$current_ladder_index];
            if (!isset($step_events[$current_ladder_id])) {
                $step_events[$current_ladder_id] = 0;
            }
            $step_events[$current_ladder_id]++;
            $step_last_event[$current_ladder_id] = array(
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
                'event_date' => $event->event_created_date,
            );
        }
    }
    
    // Finalize current (last) step
    if (!empty($journey)) {
        $last_index = count($journey) - 1;
        $last_ladder_id = $journey[$last_index]['ladder_id'];
        
        $journey[$last_index]['last_event_id'] = $step_last_event[$last_ladder_id]['event_id'] ?? null;
        $journey[$last_index]['last_event_type'] = $step_last_event[$last_ladder_id]['event_type'] ?? null;
        $journey[$last_index]['last_event_date'] = $step_last_event[$last_ladder_id]['event_date'] ?? null;
        $journey[$last_index]['events_in_step'] = $step_events[$last_ladder_id] ?? 0;
        
        // Calculate time in current step (ongoing)
        if (!empty($journey[$last_index]['step_joined'])) {
            $joined = strtotime($journey[$last_index]['step_joined']);
            $reference_time = strtotime(wporgcd_get_reference_end_date());
            $journey[$last_index]['time_in_step_days'] = round(($reference_time - $joined) / DAY_IN_SECONDS);
        }
    }
    
    return $journey;
}

/**
 * Check if ladder requirements are met
 * 
 * @param array $ladder Ladder configuration
 * @param array $counts Event counts by type
 * @return array|false The met requirement or false
 */
function wporgcd_check_ladder_requirements($ladder, $counts) {
    if (empty($ladder['requirements'])) {
        return false;
    }
    
    // Check if ANY requirement is met
    foreach ($ladder['requirements'] as $req) {
        $event_type = $req['event_type'];
        $min = $req['min'];
        
        if (isset($counts[$event_type]) && $counts[$event_type] >= $min) {
            return array(
                'event_type' => $event_type,
                'min' => $min,
                'achieved' => $counts[$event_type],
            );
        }
    }
    
    return false;
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Get a user's profile
 * 
 * @param string $user_id The contributor ID
 * @return object|null The profile data or null
 */
function wporgcd_get_profile($user_id) {
    global $wpdb;
    $table_name = wporgcd_get_table('profiles');
    
    $profile = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %s",
        $user_id
    ));
    
    if ($profile) {
        $profile->ladder_journey = json_decode($profile->ladder_journey, true);
        $profile->event_counts = json_decode($profile->event_counts, true);
    }
    
    return $profile;
}


/**
 * Get profile statistics
 * 
 * @return array Statistics about profiles
 */
function wporgcd_get_profile_stats() {
    global $wpdb;
    $profiles_table = wporgcd_get_table('profiles');
    $events_table = wporgcd_get_table('events');
    
    $stats = array(
        'total_profiles' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $profiles_table"),
        'by_ladder' => array(),
        'by_status' => array(
            'active' => 0,
            'warning' => 0,
            'inactive' => 0,
        ),
        'stale_profiles' => 0,
        'profiles_needing_update' => 0,
    );
    
    // Count by ladder
    $by_ladder = $wpdb->get_results(
        "SELECT current_ladder, COUNT(*) as count 
         FROM $profiles_table 
         GROUP BY current_ladder 
         ORDER BY count DESC"
    );
    
    foreach ($by_ladder as $row) {
        $stats['by_ladder'][$row->current_ladder ?: 'none'] = (int) $row->count;
    }
    
    // Count by status
    $by_status = $wpdb->get_results(
        "SELECT status, COUNT(*) as count 
         FROM $profiles_table 
         GROUP BY status"
    );
    
    foreach ($by_status as $row) {
        if (isset($stats['by_status'][$row->status])) {
            $stats['by_status'][$row->status] = (int) $row->count;
        }
    }
    
    // Stale profiles (not updated in 24 hours)
    $stats['stale_profiles'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM $profiles_table 
         WHERE profile_computed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    
    // Build the registration date filter using reference start date
    $min_date = wporgcd_get_reference_start_date();
    $date_filter = $wpdb->prepare(" AND e.contributor_created_date >= %s", $min_date);
    
    // Profiles needing update (events newer than profile, filtered by date)
    // Only count users who have at least one event that isn't 'updated_profile'
    $stats['profiles_needing_update'] = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT e.contributor_id) 
         FROM $events_table e
         LEFT JOIN $profiles_table p ON e.contributor_id = p.user_id
         WHERE (p.id IS NULL OR e.event_created_date > p.profile_computed_at)
         AND e.event_type != 'updated_profile'
         $date_filter"
    );
    
    // Include filter info
    $stats['min_registered_date'] = $min_date;
    
    return $stats;
}

/**
 * Delete all profiles (for rebuilding)
 */
function wporgcd_delete_all_profiles() {
    global $wpdb;
    $table_name = wporgcd_get_table('profiles');
    return $wpdb->query("TRUNCATE TABLE $table_name");
}

// ============================================================================
// MANUAL TRIGGERS
// ============================================================================

/**
 * Start building all profiles (async via WP-Cron)
 * 
 * This is the main entry point to trigger profile generation.
 * Call this manually when you want to build/rebuild all profiles.
 * 
 * @return array Status info
 */
function wporgcd_start_profile_generation() {
    global $wpdb;
    $events_table = wporgcd_get_table('events');
    $profiles_table = wporgcd_get_table('profiles');
    
    // Set reference dates from events before generating profiles
    wporgcd_set_reference_date_from_events();
    
    // Use reference start date as the min registered date filter
    $min_date = wporgcd_get_reference_start_date();
    $date_filter = $wpdb->prepare(" AND contributor_created_date >= %s", $min_date);
    $date_filter_where = $wpdb->prepare(" AND e.contributor_created_date >= %s", $min_date);
    
    // Count how many profiles need to be created/updated (filtered by date)
    // Only count users who have at least one event that isn't 'updated_profile'
    $total_contributors = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT contributor_id) FROM $events_table WHERE event_type != 'updated_profile' $date_filter"
    );
    
    $existing_profiles = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM $profiles_table"
    );
    
    $needing_update = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT e.contributor_id) 
         FROM $events_table e
         LEFT JOIN $profiles_table p ON e.contributor_id = p.user_id
         WHERE (p.id IS NULL OR e.event_created_date > p.profile_computed_at)
         AND e.event_type != 'updated_profile'
         $date_filter_where"
    );
    
    // Store generation state
    $state = array(
        'status' => 'processing',
        'started_at' => current_time('mysql'),
        'total_to_process' => $needing_update,
        'processed' => 0,
        'min_registered_date' => $min_date,
    );
    update_option('wporgcd_profile_generation_state', $state);
    
    // Schedule the first batch
    wp_clear_scheduled_hook('wporgcd_cron_process_profiles'); // Clear any existing
    wp_schedule_single_event(time() + 1, 'wporgcd_cron_process_profiles');
    
    return array(
        'success' => true,
        'total_contributors' => $total_contributors,
        'existing_profiles' => $existing_profiles,
        'profiles_needing_update' => $needing_update,
        'min_registered_date' => $min_date ?: null,
    );
}

/**
 * Get profile generation status
 * 
 * @return array Status info about ongoing profile generation
 */
function wporgcd_get_profile_generation_status() {
    $state = get_option('wporgcd_profile_generation_state');
    $next_scheduled = wp_next_scheduled('wporgcd_cron_process_profiles');
    
    $is_running = $state && $state['status'] === 'processing';
    
    // Calculate progress
    $progress = 0;
    $remaining = 0;
    if ($is_running && $state['total_to_process'] > 0) {
        $progress = round(($state['processed'] / $state['total_to_process']) * 100, 1);
        $remaining = $state['total_to_process'] - $state['processed'];
    }
    
    return array(
        'available' => true,
        'is_running' => $is_running,
        'status' => $state['status'] ?? 'idle',
        'total_to_process' => $state['total_to_process'] ?? 0,
        'processed' => $state['processed'] ?? 0,
        'remaining' => $remaining,
        'progress' => $progress,
        'started_at' => $state['started_at'] ?? null,
        'completed_at' => $state['completed_at'] ?? null,
        'next_batch_scheduled' => $next_scheduled ? true : false,
    );
}

/**
 * Stop any ongoing profile generation
 */
function wporgcd_stop_profile_generation() {
    // Clear the cron
    wp_clear_scheduled_hook('wporgcd_cron_process_profiles');
    
    // Update state
    $state = get_option('wporgcd_profile_generation_state');
    if ($state) {
        $state['status'] = 'cancelled';
        $state['cancelled_at'] = current_time('mysql');
        update_option('wporgcd_profile_generation_state', $state);
    }
    
    return true;
}

/**
 * Reset profile generation state (for cleanup)
 */
function wporgcd_reset_profile_generation() {
    wp_clear_scheduled_hook('wporgcd_cron_process_profiles');
    delete_option('wporgcd_profile_generation_state');
}
