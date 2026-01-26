<?php
/**
 * Core Import Functions
 * 
 * Reusable import logic for processing contributor events from any source.
 * Used by: CSV import (admin), future REST API, WP-CLI, etc.
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// SINGLE EVENT FUNCTIONS
// ============================================================================

/**
 * Validate a single event
 * 
 * @param array $event Event data with keys: event_id, contributor_id, event_type, etc.
 * @return true|WP_Error True if valid, WP_Error with details if not
 */
function wporgcd_validate_event($event) {
    $missing = array();
    
    if (empty($event['event_id'])) {
        $missing[] = 'event_id';
    }
    if (empty($event['contributor_id'])) {
        $missing[] = 'contributor_id';
    }
    if (empty($event['event_type'])) {
        $missing[] = 'event_type';
    }
    
    if (!empty($missing)) {
        return new WP_Error(
            'missing_fields',
            'Missing required fields: ' . implode(', ', $missing),
            array('missing' => $missing)
        );
    }
    
    return true;
}

/**
 * Check if an event already exists (by event_id)
 * 
 * @param string $event_id The event ID to check
 * @return bool True if exists, false otherwise
 */
function wporgcd_event_exists($event_id) {
    global $wpdb;
    $table_name = wporgcd_get_table('events');
    
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT internal_id FROM $table_name WHERE event_id = %s",
        $event_id
    ));
    
    return (bool) $exists;
}

/**
 * Insert a single event into the database
 * 
 * @param array $event Event data
 * @return string|WP_Error 'inserted', 'exists' (skipped), or WP_Error on failure
 */
function wporgcd_insert_event($event) {
    global $wpdb;
    $table_name = wporgcd_get_table('events');
    
    // Sanitize
    $event_id = sanitize_text_field($event['event_id']);
    $contributor_id = sanitize_text_field($event['contributor_id']);
    $event_type = sanitize_key($event['event_type']);
    
    // Check for duplicate
    if (wporgcd_event_exists($event_id)) {
        return 'exists';
    }
    
    // Build insert data
    $insert_data = array(
        'event_id' => $event_id,
        'contributor_id' => $contributor_id,
        'event_type' => $event_type,
    );
    $insert_formats = array('%s', '%s', '%s');
    
    // Optional: contributor_created_date
    if (!empty($event['contributor_created_date'])) {
        $insert_data['contributor_created_date'] = sanitize_text_field($event['contributor_created_date']);
        $insert_formats[] = '%s';
    }
    
    // Optional: event_created_date
    if (!empty($event['event_created_date'])) {
        $insert_data['event_created_date'] = sanitize_text_field($event['event_created_date']);
        $insert_formats[] = '%s';
    }
    
    // Optional: event_data (JSON)
    if (!empty($event['event_data'])) {
        $insert_data['event_data'] = is_string($event['event_data']) 
            ? $event['event_data'] 
            : wp_json_encode($event['event_data']);
        $insert_formats[] = '%s';
    }
    
    $result = $wpdb->insert($table_name, $insert_data, $insert_formats);
    
    if ($result === false) {
        return new WP_Error(
            'db_error',
            'Database error: ' . $wpdb->last_error
        );
    }
    
    return 'inserted';
}

// ============================================================================
// BATCH IMPORT FUNCTIONS
// ============================================================================

/**
 * Import multiple events at once
 * 
 * @param array $events Array of event arrays
 * @param array $options Optional settings
 * @return int Number of events successfully imported
 */
function wporgcd_import_events($events, $options = array()) {
    $defaults = array(
        'auto_create_event_types' => true,
    );
    $options = wp_parse_args($options, $defaults);
    
    $imported = 0;
    
    $event_types = wporgcd_get_event_types();
    $new_event_types = array();
    
    foreach ($events as $event) {
        // Validate
        $valid = wporgcd_validate_event($event);
        if (is_wp_error($valid)) {
            continue;
        }
        
        // Insert
        $result = wporgcd_insert_event($event);
        
        if ($result === 'inserted') {
            $imported++;
            
            // Track new event types
            $event_type = sanitize_key($event['event_type']);
            if ($options['auto_create_event_types'] && !isset($event_types[$event_type]) && !isset($new_event_types[$event_type])) {
                $new_event_types[$event_type] = array(
                    'title' => ucwords(str_replace('_', ' ', $event_type))
                );
            }
        }
    }
    
    // Save new event types
    if (!empty($new_event_types)) {
        $event_types = array_merge($event_types, $new_event_types);
        update_option('wporgcd_event_types', $event_types);
    }
    
    return $imported;
}

// ============================================================================
// CSV PARSING HELPERS
// ============================================================================

/**
 * Parse a CSV line into an event array
 * 
 * Expected format: ID,user_id,user_registered,event_type,date_recorded
 * 
 * @param string $line CSV line
 * @return array|WP_Error Event array or error
 */
function wporgcd_parse_csv_line($line) {
    $line = trim($line);
    if (empty($line)) {
        return new WP_Error('empty_line', 'Empty line');
    }
    
    $parts = str_getcsv($line);
    
    if (count($parts) < 5) {
        return new WP_Error(
            'invalid_format',
            'Not enough columns (expected 5, got ' . count($parts) . ')'
        );
    }
    
    return array(
        'event_id' => trim($parts[0]),
        'contributor_id' => trim($parts[1]),
        'contributor_created_date' => trim($parts[2]),
        'event_type' => trim($parts[3]),
        'event_created_date' => trim($parts[4]),
    );
}

/**
 * Check if a CSV line looks like a header
 * 
 * @param string $line First line of CSV
 * @return bool True if it's a header
 */
function wporgcd_is_csv_header($line) {
    return (stripos($line, 'id,') === 0 || stripos($line, 'user_id') !== false);
}

// ============================================================================
// BATCH STATE MANAGEMENT (for background processing)
// ============================================================================

/**
 * Get the state for a batch import
 * 
 * @param string $import_id Import identifier
 * @return array|null State array or null if not found
 */
function wporgcd_get_import_state($import_id) {
    return get_option('wporgcd_import_state_' . $import_id);
}

/**
 * Update the state for a batch import
 * 
 * @param string $import_id Import identifier
 * @param array $state State data
 */
function wporgcd_update_import_state($import_id, $state) {
    update_option('wporgcd_import_state_' . $import_id, $state);
}

/**
 * Get the current active import ID
 * 
 * @return string|null Import ID or null
 */
function wporgcd_get_current_import() {
    return get_option('wporgcd_current_import');
}

/**
 * Set the current active import ID
 * 
 * @param string|null $import_id Import ID or null to clear
 */
function wporgcd_set_current_import($import_id) {
    if ($import_id === null) {
        delete_option('wporgcd_current_import');
    } else {
        update_option('wporgcd_current_import', $import_id);
    }
}

/**
 * Create initial state for a new batch import
 * 
 * @param string $import_id Import identifier
 * @param int $total_rows Total rows to process
 * @param array $extra Additional state data
 * @return array The created state
 */
function wporgcd_create_import_state($import_id, $total_rows, $extra = array()) {
    $state = array_merge(array(
        'import_id' => $import_id,
        'total_rows' => $total_rows,
        'processed' => 0,
        'imported' => 0,
        'status' => 'processing',
        'started_at' => current_time('mysql'),
        'current_offset' => 0,
    ), $extra);
    
    wporgcd_update_import_state($import_id, $state);
    wporgcd_set_current_import($import_id);
    
    return $state;
}

