<?php
/**
 * Settings Admin Pages
 * 
 * Event Types and Ladders configuration pages.
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// EXPORT HANDLERS
// ============================================================================

add_action('admin_init', 'wporgcd_handle_export');

function wporgcd_handle_export() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Export Event Types
    if (isset($_GET['wporgcd_export_event_types'])) {
        check_admin_referer('wporgcd_export_event_types');
        header( 'Content-Type: application/json' );
        header( 'Content-Disposition: attachment; filename="wporgcd-event-types-' . gmdate( 'Y-m-d' ) . '.json"' );
        echo wp_json_encode( wporgcd_get_event_types(), JSON_PRETTY_PRINT );
        exit;
    }
    
    // Export Ladders
    if ( isset( $_GET['wporgcd_export_ladders'] ) ) {
        check_admin_referer( 'wporgcd_export_ladders' );
        header( 'Content-Type: application/json' );
        header( 'Content-Disposition: attachment; filename="wporgcd-ladders-' . gmdate( 'Y-m-d' ) . '.json"' );
        echo wp_json_encode( wporgcd_get_ladders(), JSON_PRETTY_PRINT );
        exit;
    }
}

// ============================================================================
// EVENT TYPES PAGE
// ============================================================================

function wporgcd_render_event_types_page() {
    $event_types = wporgcd_get_event_types();
    $message = '';
    
    // Handle import
    if ( isset( $_POST['wporgcd_import_event_types'] ) && check_admin_referer( 'wporgcd_event_types_nonce' ) ) {
        if ( ! empty( $_FILES['import_file']['tmp_name'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading JSON file content
            $json = file_get_contents( $_FILES['import_file']['tmp_name'] );
            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $message = '<div class="notice notice-error"><p>Invalid JSON format.</p></div>';
            } elseif (!is_array($data)) {
                $message = '<div class="notice notice-error"><p>JSON must be an object/array of event types.</p></div>';
            } else {
                update_option('wporgcd_event_types', $data);
                $event_types = $data;
                $message = '<div class="notice notice-success"><p>Imported ' . count($data) . ' event types.</p></div>';
            }
        } else {
            $message = '<div class="notice notice-error"><p>Please select a file.</p></div>';
        }
    }
    
    // Handle form submissions
    if ( isset( $_POST['wporgcd_add_event_type'] ) && check_admin_referer( 'wporgcd_event_types_nonce' ) ) {
        $new_id = isset( $_POST['new_event_id'] ) ? sanitize_key( wp_unslash( $_POST['new_event_id'] ) ) : '';
        $new_title = isset( $_POST['new_event_title'] ) ? sanitize_text_field( wp_unslash( $_POST['new_event_title'] ) ) : '';
        
        if (empty($new_id) || empty($new_title)) {
            $message = '<div class="notice notice-error"><p>Both ID and Title are required.</p></div>';
        } elseif (isset($event_types[$new_id])) {
            $message = '<div class="notice notice-error"><p>Event type ID already exists.</p></div>';
        } else {
            $event_types[$new_id] = array('title' => $new_title);
            update_option('wporgcd_event_types', $event_types);
            $message = '<div class="notice notice-success"><p>Event type added.</p></div>';
        }
    }
    
    if ( isset( $_POST['wporgcd_update_titles'] ) && check_admin_referer( 'wporgcd_event_types_nonce' ) ) {
        if ( ! empty( $_POST['event_titles'] ) && is_array( $_POST['event_titles'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized inside loop
            foreach ( $_POST['event_titles'] as $id => $title ) {
                if ( isset( $event_types[ $id ] ) ) {
                    $event_types[ $id ]['title'] = sanitize_text_field( wp_unslash( $title ) );
                }
            }
            update_option('wporgcd_event_types', $event_types);
            $message = '<div class="notice notice-success"><p>Titles updated.</p></div>';
        }
    }
    
    // Refresh after save
    $event_types = wporgcd_get_event_types();
    $export_url = wp_nonce_url(admin_url('admin.php?page=contributor-event-types&wporgcd_export_event_types=1'), 'wporgcd_export_event_types');
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Event Types</h1>
        <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">↓ Export</a>
        <button type="button" class="page-title-action" onclick="document.getElementById('wporgcd-import-file').click()">↑ Import</button>
        <form method="post" enctype="multipart/form-data" id="wporgcd-import-form" style="display:none;">
            <?php wp_nonce_field('wporgcd_event_types_nonce'); ?>
            <input type="file" name="import_file" id="wporgcd-import-file" accept=".json" onchange="if(confirm('This will replace all event types. Continue?')) this.form.submit();">
            <input type="hidden" name="wporgcd_import_event_types" value="1">
        </form>
        <hr class="wp-header-end">
        
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $message contains safe HTML from this function
        echo $message;
        ?>
        
        <!-- Add New Event Type -->
        <div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;">Add New Event Type</h2>
            <form method="post">
                <?php wp_nonce_field('wporgcd_event_types_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="new_event_id">ID (slug)</label></th>
                        <td>
                            <input type="text" id="new_event_id" name="new_event_id" class="regular-text" 
                                   pattern="[a-z0-9_-]+" placeholder="e.g. support_reply">
                            <p class="description">Lowercase letters, numbers, underscores, hyphens only. Cannot be changed later.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="new_event_title">Title</label></th>
                        <td>
                            <input type="text" id="new_event_title" name="new_event_title" class="regular-text" 
                                   placeholder="e.g. Support Reply">
                        </td>
                    </tr>
                </table>
                <button type="submit" name="wporgcd_add_event_type" class="button button-primary">Add Event Type</button>
            </form>
        </div>
        
        <!-- Existing Event Types -->
        <?php if (!empty($event_types)): ?>
            <h2>Existing Event Types</h2>
            <form method="post" id="wporgcd-event-types-form">
                <?php wp_nonce_field('wporgcd_event_types_nonce'); ?>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="30%">ID (immutable)</th>
                            <th width="70%">Title (editable)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($event_types as $id => $data): ?>
                            <tr>
                                <td><code><?php echo esc_html($id); ?></code></td>
                                <td>
                                    <input type="text" name="event_titles[<?php echo esc_attr($id); ?>]" 
                                           value="<?php echo esc_attr($data['title']); ?>" class="regular-text">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top: 15px;">
                    <button type="submit" name="wporgcd_update_titles" class="button button-primary">Save Titles</button>
                </p>
            </form>
        <?php else: ?>
            <p>No event types defined yet. Add your first one above.</p>
        <?php endif; ?>
    </div>
    <?php
}

// ============================================================================
// LADDERS PAGE
// ============================================================================

function wporgcd_render_ladders_page() {
    $ladders = wporgcd_get_ladders();
    $event_types = wporgcd_get_event_types();
    $message = '';
    
    // Handle import
    if ( isset( $_POST['wporgcd_import_ladders'] ) && check_admin_referer( 'wporgcd_ladders_nonce' ) ) {
        if ( ! empty( $_FILES['import_file']['tmp_name'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading JSON file content
            $json = file_get_contents( $_FILES['import_file']['tmp_name'] );
            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $message = '<div class="notice notice-error"><p>Invalid JSON format.</p></div>';
            } elseif (!is_array($data)) {
                $message = '<div class="notice notice-error"><p>JSON must be an object/array of ladders.</p></div>';
            } else {
                update_option('wporgcd_ladders', $data);
                $ladders = $data;
                $message = '<div class="notice notice-success"><p>Imported ' . count($data) . ' ladders. <a href="' . admin_url('admin.php?page=contributor-profiles') . '">Regenerate profiles</a> to update the dashboard.</p></div>';
            }
        } else {
            $message = '<div class="notice notice-error"><p>Please select a file.</p></div>';
        }
    }
    
    // Handle add new ladder
    if ( isset( $_POST['wporgcd_add_ladder'] ) && check_admin_referer( 'wporgcd_ladders_nonce' ) ) {
        $new_id = isset( $_POST['new_ladder_id'] ) ? sanitize_key( wp_unslash( $_POST['new_ladder_id'] ) ) : '';
        $new_title = isset( $_POST['new_ladder_title'] ) ? sanitize_text_field( wp_unslash( $_POST['new_ladder_title'] ) ) : '';
        
        if (empty($new_id) || empty($new_title)) {
            $message = '<div class="notice notice-error"><p>Both ID and Title are required.</p></div>';
        } elseif (isset($ladders[$new_id])) {
            $message = '<div class="notice notice-error"><p>Ladder ID already exists.</p></div>';
        } else {
            $ladders[$new_id] = array('title' => $new_title, 'requirements' => array());
            update_option('wporgcd_ladders', $ladders);
            $message = '<div class="notice notice-success"><p>Ladder added. <a href="' . admin_url('admin.php?page=contributor-profiles') . '">Regenerate profiles</a> to update the dashboard.</p></div>';
        }
    }
    
    // Handle update ladders (respects order and deletions)
    if ( isset( $_POST['wporgcd_update_ladders'] ) && check_admin_referer( 'wporgcd_ladders_nonce' ) ) {
        $new_ladders = array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized inside loop
        $ladder_ids = isset( $_POST['ladder_ids'] ) ? $_POST['ladder_ids'] : array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized inside loop
        $ladder_data = isset( $_POST['ladders'] ) ? $_POST['ladders'] : array();
        
        // Process in order of ladder_ids (preserves drag-drop order)
        foreach ($ladder_ids as $id) {
            $id = sanitize_key($id);
            if (empty($id) || !isset($ladder_data[$id])) continue;
            
            $data = $ladder_data[$id];
            $requirements = array();
            
            if (!empty($data['req_type']) && is_array($data['req_type'])) {
                foreach ($data['req_type'] as $i => $type) {
                    $min = intval($data['req_min'][$i] ?? 0);
                    if (!empty($type) && $min > 0) {
                        $requirements[] = array('event_type' => sanitize_key($type), 'min' => $min);
                    }
                }
            }
            
            $new_ladders[$id] = array(
                'title' => sanitize_text_field($data['title']),
                'requirements' => $requirements,
            );
        }
        
        update_option('wporgcd_ladders', $new_ladders);
        $ladders = $new_ladders;
        $message = '<div class="notice notice-success"><p>Ladders saved. <a href="' . admin_url('admin.php?page=contributor-profiles') . '">Regenerate profiles</a> to update the dashboard.</p></div>';
    }
    
    $ladders = wporgcd_get_ladders();
    $export_url = wp_nonce_url(admin_url('admin.php?page=contributor-ladders&wporgcd_export_ladders=1'), 'wporgcd_export_ladders');
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Ladders</h1>
        <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">↓ Export</a>
        <button type="button" class="page-title-action" onclick="document.getElementById('wporgcd-import-ladders-file').click()">↑ Import</button>
        <form method="post" enctype="multipart/form-data" id="wporgcd-import-ladders-form" style="display:none;">
            <?php wp_nonce_field('wporgcd_ladders_nonce'); ?>
            <input type="file" name="import_file" id="wporgcd-import-ladders-file" accept=".json" onchange="if(confirm('This will replace all ladders. Continue?')) this.form.submit();">
            <input type="hidden" name="wporgcd_import_ladders" value="1">
        </form>
        <hr class="wp-header-end">
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $message contains safe HTML from this function
        echo $message;
        ?>
        
        <!-- Add New Ladder -->
        <div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;">Add New Ladder</h2>
            <form method="post">
                <?php wp_nonce_field('wporgcd_ladders_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="new_ladder_id">ID</label></th>
                        <td>
                            <input type="text" id="new_ladder_id" name="new_ladder_id" class="regular-text" 
                                   pattern="[a-z0-9_-]+" placeholder="e.g. connect">
                            <p class="description">Lowercase, no spaces. Cannot be changed later.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="new_ladder_title">Title</label></th>
                        <td><input type="text" id="new_ladder_title" name="new_ladder_title" class="regular-text" placeholder="e.g. Connect"></td>
                    </tr>
                </table>
                <button type="submit" name="wporgcd_add_ladder" class="button button-primary">Add Ladder</button>
            </form>
        </div>
        
        <!-- Existing Ladders -->
        <?php if (!empty($ladders)): ?>
            <h2>Existing Ladders</h2>
            <p class="description" style="margin-bottom: 15px;">A contributor reaches a ladder by meeting <strong>any one</strong> of its requirements. Drag to reorder.</p>
            <style>
                .wporgcd-ladders-table { border-collapse: collapse; }
                .wporgcd-ladders-table th { text-align: left; padding: 12px; }
                .wporgcd-ladders-table td { padding: 12px; vertical-align: top; }
                .wporgcd-ladders-table code { background: #f0f0f0; padding: 4px 8px; border-radius: 3px; }
                .wporgcd-req-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
                .wporgcd-req-row select { min-width: 180px; }
                .wporgcd-req-row input[type="number"] { width: 70px; text-align: center; }
                .wporgcd-req-row .button { padding: 0 8px; min-height: 30px; line-height: 28px; }
                .wporgcd-requirements { margin-bottom: 8px; }
                .wporgcd-add-req { font-size: 12px; }
                .wporgcd-drag-handle { cursor: grab; color: #999; padding: 0 5px; }
                .wporgcd-drag-handle:active { cursor: grabbing; }
                .wporgcd-ladder-row.dragging { opacity: 0.5; background: #f0f0f0; }
                .wporgcd-ladder-row.drag-over { border-top: 2px solid #2271b1; }
                .wporgcd-delete-ladder { color: #b32d2e; text-decoration: none; }
                .wporgcd-delete-ladder:hover { color: #a00; text-decoration: underline; }
            </style>
            <form method="post">
                <?php wp_nonce_field('wporgcd_ladders_nonce'); ?>
                <table class="wp-list-table widefat fixed striped wporgcd-ladders-table">
                    <thead>
                        <tr>
                            <th width="3%"></th>
                            <th width="12%">ID</th>
                            <th width="20%">Title</th>
                            <th>Requirements (meet ANY)</th>
                            <th width="5%"></th>
                        </tr>
                    </thead>
                    <tbody id="wporgcd-ladders-tbody">
                        <?php foreach ($ladders as $id => $ladder): ?>
                            <tr class="wporgcd-ladder-row" data-id="<?php echo esc_attr($id); ?>">
                                <td class="wporgcd-drag-handle" title="Drag to reorder">::</td>
                                <td>
                                    <code><?php echo esc_html($id); ?></code>
                                    <input type="hidden" name="ladder_ids[]" value="<?php echo esc_attr($id); ?>">
                                    <input type="hidden" name="ladders[<?php echo esc_attr($id); ?>][title]" 
                                           value="<?php echo esc_attr($ladder['title']); ?>" class="wporgcd-title-hidden">
                                </td>
                                <td>
                                    <input type="text" class="wporgcd-title-input" 
                                           value="<?php echo esc_attr($ladder['title']); ?>" style="width: 100%;">
                                </td>
                                <td>
                                    <div class="wporgcd-requirements" data-ladder="<?php echo esc_attr($id); ?>">
                                        <?php foreach (($ladder['requirements'] ?? array()) as $req): ?>
                                            <div class="wporgcd-req-row">
                                                <select name="ladders[<?php echo esc_attr($id); ?>][req_type][]">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($event_types as $et_id => $et): ?>
                                                        <option value="<?php echo esc_attr($et_id); ?>" <?php selected($req['event_type'], $et_id); ?>>
                                                            <?php echo esc_html($et['title']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span>≥</span>
                                                <input type="number" min="1" name="ladders[<?php echo esc_attr($id); ?>][req_min][]" 
                                                       value="<?php echo esc_attr($req['min']); ?>">
                                                <button type="button" class="button wporgcd-remove-req" title="Remove">x</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="button wporgcd-add-req" data-ladder="<?php echo esc_attr($id); ?>">+ Add</button>
                                </td>
                                <td>
                                    <a href="#" class="wporgcd-delete-ladder">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top: 15px;">
                    <button type="submit" name="wporgcd_update_ladders" class="button button-primary">Save Changes</button>
                </p>
            </form>
            
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const eventTypes = <?php echo json_encode(array_map(fn($et) => $et['title'], $event_types)); ?>;
                const tbody = document.getElementById('wporgcd-ladders-tbody');
                let draggedRow = null;
                
                // Sync title input to hidden field
                document.querySelectorAll('.wporgcd-title-input').forEach(input => {
                    input.addEventListener('input', function() {
                        this.closest('tr').querySelector('.wporgcd-title-hidden').value = this.value;
                    });
                });
                
                // Delete ladder
                document.querySelectorAll('.wporgcd-delete-ladder').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (confirm('Delete this ladder?')) {
                            this.closest('tr').remove();
                        }
                    });
                });
                
                // Drag and drop
                tbody.querySelectorAll('.wporgcd-ladder-row').forEach(row => {
                    row.draggable = true;
                    
                    row.addEventListener('dragstart', function(e) {
                        draggedRow = this;
                        this.classList.add('dragging');
                    });
                    
                    row.addEventListener('dragend', function() {
                        this.classList.remove('dragging');
                        tbody.querySelectorAll('.wporgcd-ladder-row').forEach(r => r.classList.remove('drag-over'));
                        draggedRow = null;
                    });
                    
                    row.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        if (this !== draggedRow) {
                            this.classList.add('drag-over');
                        }
                    });
                    
                    row.addEventListener('dragleave', function() {
                        this.classList.remove('drag-over');
                    });
                    
                    row.addEventListener('drop', function(e) {
                        e.preventDefault();
                        if (this !== draggedRow) {
                            const rows = [...tbody.querySelectorAll('.wporgcd-ladder-row')];
                            const draggedIdx = rows.indexOf(draggedRow);
                            const targetIdx = rows.indexOf(this);
                            if (draggedIdx < targetIdx) {
                                this.after(draggedRow);
                            } else {
                                this.before(draggedRow);
                            }
                        }
                        this.classList.remove('drag-over');
                    });
                });
                
                // Add requirement
                document.querySelectorAll('.wporgcd-add-req').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const ladder = this.dataset.ladder;
                        const container = document.querySelector(`.wporgcd-requirements[data-ladder="${ladder}"]`);
                        
                        let options = '<option value="">-- Select --</option>';
                        for (const [id, title] of Object.entries(eventTypes)) {
                            options += `<option value="${id}">${title}</option>`;
                        }
                        
                        const row = document.createElement('div');
                        row.className = 'wporgcd-req-row';
                        row.innerHTML = `
                            <select name="ladders[${ladder}][req_type][]">${options}</select>
                            <span>≥</span>
                            <input type="number" min="1" name="ladders[${ladder}][req_min][]" value="1">
                            <button type="button" class="button wporgcd-remove-req" title="Remove">x</button>
                        `;
                        container.appendChild(row);
                        row.querySelector('.wporgcd-remove-req').addEventListener('click', function() { row.remove(); });
                        row.querySelector('select').focus();
                    });
                });
                
                // Remove requirement
                document.querySelectorAll('.wporgcd-remove-req').forEach(btn => {
                    btn.addEventListener('click', function() { this.closest('.wporgcd-req-row').remove(); });
                });
            });
            </script>
        <?php else: ?>
            <p>No ladders defined yet. Add your first one above.</p>
        <?php endif; ?>
    </div>
    <?php
}
