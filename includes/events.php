<?php
/**
 * Events Table
 * 
 * Database setup for contributor events.
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// REFERENCE DATES
// ============================================================================

/**
 * Get the reference end date for time-based calculations.
 * This is the date of the newest event, used instead of "today".
 */
function wporgcd_get_reference_end_date() {
    return get_option('wporgcd_reference_end_date', current_time('Y-m-d'));
}

/**
 * Get the reference start date for time-based calculations.
 * This is the date of the oldest event.
 */
function wporgcd_get_reference_start_date() {
    return get_option('wporgcd_reference_start_date', current_time('Y-m-d'));
}

/**
 * Set the reference dates from the events table.
 * Call this at the start of profile generation.
 */
function wporgcd_set_reference_date_from_events() {
    global $wpdb;
    $events_table = wporgcd_get_table('events');
    
    $latest = $wpdb->get_var("SELECT MAX(event_created_date) FROM $events_table");
    $oldest = $wpdb->get_var("SELECT MIN(event_created_date) FROM $events_table");
    
    if ($latest) {
        update_option('wporgcd_reference_end_date', date('Y-m-d', strtotime($latest)));
    }
    if ($oldest) {
        update_option('wporgcd_reference_start_date', date('Y-m-d', strtotime($oldest)));
    }
}

// ============================================================================
// DATABASE SETUP
// ============================================================================

function wporgcd_create_events_table() {
    global $wpdb;
    $table_name = wporgcd_get_table('events');
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        internal_id bigint(20) NOT NULL AUTO_INCREMENT,
        event_id varchar(255) NOT NULL,
        contributor_id varchar(255) NOT NULL,
        contributor_created_date datetime DEFAULT NULL,
        event_type varchar(100) NOT NULL,
        event_data longtext DEFAULT NULL,
        event_created_date datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (internal_id),
        UNIQUE KEY event_id (event_id),
        KEY contributor_id (contributor_id),
        KEY event_type (event_type),
        KEY event_created_date (event_created_date)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
