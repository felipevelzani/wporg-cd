<?php
/**
 * CSV Import Tool using WP-Cron
 * 
 * Admin UI for importing contributor events from CSV files.
 * Uses core import functions from includes/import.php
 * 
 * Expected CSV format: ID,user_id,user_registered,event_type,date_recorded
 */

if (!defined('ABSPATH')) exit;

// Batch size for processing (2000 rows per batch)
define('WPORGCD_IMPORT_BATCH_SIZE', 2000);

// Add submenu page
add_action('admin_menu', 'wporgcd_add_import_menu', 20);

function wporgcd_add_import_menu() {
    add_submenu_page(
        'contributor-dashboard',
        'Import Events',
        'Import',
        'manage_options',
        'contributor-import',
        'wporgcd_render_import_page'
    );
}

// Register WP-Cron hook
add_action('wporgcd_cron_process_import', 'wporgcd_process_csv_import_batch');

/**
 * Process a batch of CSV rows via WP-Cron
 */
function wporgcd_process_csv_import_batch() {
    $import_id = wporgcd_get_current_import();
    if (!$import_id) return;
    
    $state = wporgcd_get_import_state($import_id);
    if (!$state || $state['status'] !== 'processing') return;
    
    $file_path = $state['file_path'];
    
    if (!file_exists($file_path)) {
        return;
    }
    
    $handle = fopen($file_path, 'r');
    if (!$handle) {
        return;
    }
    
    // Skip to current offset
    $start_offset = $state['current_offset'];
    
    // Skip header if present
    if ($state['has_header'] && $start_offset === 0) {
        fgets($handle);
    }
    
    // Skip already processed lines
    $current_line = 0;
    while ($current_line < $start_offset && fgets($handle) !== false) {
        $current_line++;
    }
    
    // Collect events for this batch
    $events = array();
    $processed_in_batch = 0;
    
    while ($processed_in_batch < WPORGCD_IMPORT_BATCH_SIZE && ($line = fgets($handle)) !== false) {
        $event = wporgcd_parse_csv_line($line);
        
        if (!is_wp_error($event) && $event) {
            $events[] = $event;
        }
        
        $processed_in_batch++;
    }
    
    fclose($handle);
    
    // Import the batch using core function
    if (!empty($events)) {
        $imported = wporgcd_import_events($events);
        $state['imported'] += $imported;
    }
    
    // Update state
    $state['current_offset'] += $processed_in_batch;
    $state['processed'] += $processed_in_batch;
    
    // Check if done
    if ($state['processed'] >= $state['total_rows']) {
        $state['status'] = 'completed';
        $state['completed_at'] = current_time('mysql');
        
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        
        // Update reference date from new events
        wporgcd_set_reference_date_from_events();
        
        wporgcd_set_current_import(null);
        wp_clear_scheduled_hook('wporgcd_cron_process_import');
    } else {
        // Schedule next batch in 1 second
        if (!wp_next_scheduled('wporgcd_cron_process_import')) {
            wp_schedule_single_event(time() + 1, 'wporgcd_cron_process_import');
        }
    }
    
    wporgcd_update_import_state($import_id, $state);
}

/**
 * Render the import page
 */
function wporgcd_render_import_page() {
    global $wpdb;
    $table_name = wporgcd_get_table('events');
    $message = '';
    
    // Handle form submissions
    if (isset($_POST['wporgcd_start_import']) && check_admin_referer('wporgcd_import_nonce')) {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $message = '<div class="notice notice-error"><p>Please select a CSV file to upload.</p></div>';
        } else {
            $result = wporgcd_start_csv_import($_FILES['csv_file']);
            if (is_wp_error($result)) {
                $message = '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $message = '<div class="notice notice-success"><p>Import started! Processing ' . number_format($result) . ' rows in the background.</p></div>';
            }
        }
    }
    
    if (isset($_POST['wporgcd_cancel_import']) && check_admin_referer('wporgcd_import_nonce')) {
        wporgcd_cancel_csv_import();
        $message = '<div class="notice notice-warning"><p>Import cancelled.</p></div>';
    }
    
    if (isset($_POST['wporgcd_clear_all']) && check_admin_referer('wporgcd_import_nonce')) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        $message = '<div class="notice notice-success"><p>All events have been deleted.</p></div>';
    }
    
    // Check for current import
    $current_import_id = wporgcd_get_current_import();
    $current_import = $current_import_id ? wporgcd_get_import_state($current_import_id) : null;
    ?>
    <div class="wrap">
        <h1>Import Events</h1>
        
        <?php echo $message; ?>
        
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
            <div style="background: #fff; border: 1px solid #ddd; padding: 20px;">
                
                <?php if ($current_import && $current_import['status'] === 'processing'): ?>
                    <h2 style="margin-top: 0;">Import in Progress</h2>
                    
                    <?php 
                    $progress = $current_import['total_rows'] > 0 
                        ? round(($current_import['processed'] / $current_import['total_rows']) * 100, 1) 
                        : 0;
                    ?>
                    
                    <div style="background: #ddd; border-radius: 4px; height: 24px; overflow: hidden; margin: 15px 0;">
                        <div style="background: #0073aa; height: 100%; width: <?php echo $progress; ?>%;"></div>
                    </div>
                    
                    <p style="font-size: 16px;">
                        <strong><?php echo $progress; ?>%</strong> complete
                        (<?php echo number_format($current_import['processed']); ?> / <?php echo number_format($current_import['total_rows']); ?> rows)
                    </p>
                    
                    <p>
                        Imported: <strong style="color: #46b450;"><?php echo number_format($current_import['imported']); ?></strong>
                    </p>
                    
                    <p style="margin-top: 20px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=contributor-import')); ?>" class="button">Refresh</a>
                        
                        <form method="post" style="display: inline;">
                    <?php wp_nonce_field('wporgcd_import_nonce'); ?>
                            <button type="submit" name="wporgcd_cancel_import" class="button" style="color: #a00;" 
                                    onclick="return confirm('Cancel the import?')">
                                Cancel
                            </button>
                        </form>
                    </p>
                    
                <?php elseif ($current_import && $current_import['status'] === 'completed'): ?>
                    <h2 style="margin-top: 0; color: #46b450;">Import Complete</h2>
                    
                    <p>
                        Imported: <strong><?php echo number_format($current_import['imported']); ?></strong>
                    </p>
                    
                    <p><a href="<?php echo admin_url('admin.php?page=contributor-import'); ?>" class="button button-primary">Start New Import</a></p>
                    
                <?php else: ?>
                    <h2 style="margin-top: 0;">Upload CSV File</h2>
                    <p class="description">
                        Format: <code>ID,user_id,user_registered,event_type,date_recorded</code>
                    </p>
                    
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('wporgcd_import_nonce'); ?>
                        
                        <p style="margin: 20px 0;">
                            <input type="file" name="csv_file" accept=".csv">
                        </p>
                        
                        <button type="submit" name="wporgcd_start_import" class="button button-primary">
                            Start Import
                        </button>
                </form>
                <?php endif; ?>
                
            </div>
            
            <div>
                <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 20px;">
                    <h3 style="margin-top: 0; color: #856404;">Danger Zone</h3>
                    <form method="post" onsubmit="return confirm('Delete ALL events?');">
                        <?php wp_nonce_field('wporgcd_import_nonce'); ?>
                        <button type="submit" name="wporgcd_clear_all" class="button" style="color: #a00;">
                            Delete All Events
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Start a CSV file import
 * 
 * @param array $file $_FILES array element
 * @return int|WP_Error Number of rows to process or error
 */
function wporgcd_start_csv_import($file) {
    $file_type = wp_check_filetype($file['name'], array('csv' => 'text/csv'));
    if ($file_type['ext'] !== 'csv') {
        return new WP_Error('invalid_file', 'Please upload a CSV file.');
    }
    
    // Save file
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/wpcd-imports';
    
    if (!file_exists($import_dir)) {
        wp_mkdir_p($import_dir);
        file_put_contents($import_dir . '/.htaccess', 'deny from all');
    }
    
    $import_id = 'import_' . time();
    $file_path = $import_dir . '/' . $import_id . '.csv';
    
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        return new WP_Error('upload_failed', 'Failed to save file.');
    }
    
    // Count lines and detect header
    $total_lines = 0;
    $is_header = false;
    $handle = fopen($file_path, 'r');
    
    if ($handle) {
        $first_line = fgets($handle);
        $is_header = wporgcd_is_csv_header($first_line);
        
        while (fgets($handle) !== false) {
            $total_lines++;
        }
        
        if (!$is_header) {
            $total_lines++;
        }
        
        fclose($handle);
    }
    
    if ($total_lines === 0) {
        unlink($file_path);
        return new WP_Error('empty_file', 'CSV file is empty.');
    }
    
    // Create import state using core function
    wporgcd_create_import_state($import_id, $total_lines, array(
        'file_path' => $file_path,
        'has_header' => $is_header,
    ));
    
    // Schedule first batch
    wp_schedule_single_event(time() + 1, 'wporgcd_cron_process_import');
    
    return $total_lines;
}

/**
 * Cancel the current CSV import
 */
function wporgcd_cancel_csv_import() {
    $import_id = wporgcd_get_current_import();
    if (!$import_id) return;
    
    $state = wporgcd_get_import_state($import_id);
    
    // Clear cron
    wp_clear_scheduled_hook('wporgcd_cron_process_import');
    
    // Update state
    if ($state) {
        $state['status'] = 'cancelled';
        wporgcd_update_import_state($import_id, $state);
        
        if (isset($state['file_path']) && file_exists($state['file_path'])) {
            unlink($state['file_path']);
        }
    }
    
    wporgcd_set_current_import(null);
}

/**
 * Cleanup all CSV import states
 */
function wporgcd_cleanup_csv_imports() {
    global $wpdb;
    
    wp_clear_scheduled_hook('wporgcd_cron_process_import');
    
    $import_states = $wpdb->get_results(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wporgcd_import_state_%'"
    );
    
    foreach ($import_states as $row) {
        $state = get_option($row->option_name);
        if ($state && isset($state['file_path']) && file_exists($state['file_path'])) {
            @unlink($state['file_path']);
        }
        delete_option($row->option_name);
    }
    
    wporgcd_set_current_import(null);
}
