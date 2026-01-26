<?php
/**
 * Profile Generation Admin Page
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'wporgcd_add_profiles_menu', 15);

add_action('admin_init', 'wporgcd_handle_profile_reset');

function wporgcd_handle_profile_reset() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'contributor-profiles') {
        return;
    }
    
    if (isset($_POST['wporgcd_reset_state']) && check_admin_referer('wporgcd_profiles_nonce')) {
        wporgcd_reset_profile_generation();
        wp_redirect(admin_url('admin.php?page=contributor-profiles'));
        exit;
    }
}

function wporgcd_add_profiles_menu() {
    add_submenu_page(
        'contributor-dashboard',
        'Generate Profiles',
        'Profiles',
        'manage_options',
        'contributor-profiles',
        'wporgcd_render_profiles_page'
    );
}

function wporgcd_render_profiles_page() {
    $message = '';
    
    if (isset($_POST['wporgcd_start_profiles']) && check_admin_referer('wporgcd_profiles_nonce')) {
        // Delete all existing profiles first to ensure a clean regeneration
        wporgcd_delete_all_profiles();
        
        $result = wporgcd_start_profile_generation();
        if ($result['success']) {
            $message = '<div class="notice notice-success"><p>Profile generation started! Processing ' . number_format($result['profiles_needing_update']) . ' profiles.</p></div>';
        }
    }
    
    if (isset($_POST['wporgcd_stop_profiles']) && check_admin_referer('wporgcd_profiles_nonce')) {
        wporgcd_stop_profile_generation();
        $message = '<div class="notice notice-warning"><p>Profile generation stopped.</p></div>';
    }
    
    $profile_stats = wporgcd_get_profile_stats();
    $generation_status = wporgcd_get_profile_generation_status();
    
    ?>
    <div class="wrap">
        <h1>Generate Profiles</h1>
        
        <?php echo $message; ?>
        
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
            <div style="background: #fff; border: 1px solid #ddd; padding: 20px;">
                
                <?php if ($generation_status['is_running']): ?>
                    <h2 style="margin-top: 0;">⏳ Generation in Progress</h2>
                    
                    <div style="background: #ddd; border-radius: 4px; height: 24px; overflow: hidden; margin: 15px 0;">
                        <div style="background: #0073aa; height: 100%; width: <?php echo $generation_status['progress']; ?>%;"></div>
                    </div>
                    
                    <p>
                        <strong><?php echo $generation_status['progress']; ?>%</strong> complete
                        (<?php echo number_format($generation_status['processed']); ?> / <?php echo number_format($generation_status['total_to_process']); ?>)
                    </p>
                    
                    <p style="margin-top: 20px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=contributor-profiles')); ?>" class="button">Refresh</a>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('wporgcd_profiles_nonce'); ?>
                            <button type="submit" name="wporgcd_stop_profiles" class="button" style="color: #a00;" onclick="return confirm('Stop?')">Stop</button>
                        </form>
                    </p>
                    
                <?php elseif ($generation_status['status'] === 'completed'): ?>
                    <h2 style="margin-top: 0; color: #46b450;">Complete</h2>
                    
                    <p>Processed <strong><?php echo number_format($generation_status['processed']); ?></strong> profiles.</p>
                    
                    <form method="post">
                        <?php wp_nonce_field('wporgcd_profiles_nonce'); ?>
                        <button type="submit" name="wporgcd_reset_state" class="button button-primary">Generate Again</button>
                    </form>
                    
                <?php else: ?>
                    <h2 style="margin-top: 0;">Generate Profiles</h2>
                    
                    <p class="description">Deletes all existing profiles and regenerates them from events. Status (active/warning/inactive) is calculated based on last activity date.</p>
                    
                    <?php if ($profile_stats['profiles_needing_update'] > 0): ?>
                        <p style="background: #fff8e5; padding: 10px; border-left: 4px solid #ffb900; margin: 15px 0;">
                            <strong><?php echo number_format($profile_stats['profiles_needing_update']); ?></strong> profiles need processing.
                        </p>
                    <?php endif; ?>
                    
                    <form method="post" style="margin-top: 20px;" onsubmit="return confirm('This will delete all existing profiles and regenerate them. Continue?');">
                        <?php wp_nonce_field('wporgcd_profiles_nonce'); ?>
                        <button type="submit" name="wporgcd_start_profiles" class="button button-primary">Start Generation</button>
                    </form>
                <?php endif; ?>
                
            </div>
            
            <div>
                <div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0;">Stats</h3>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div style="background: #f0f0f1; padding: 12px; border-radius: 4px; text-align: center;">
                            <div style="font-size: 20px; font-weight: bold;"><?php echo number_format($profile_stats['total_profiles']); ?></div>
                            <div style="color: #646970; font-size: 11px;">Total</div>
                        </div>
                        <div style="background: #d4edda; padding: 12px; border-radius: 4px; text-align: center;">
                            <div style="font-size: 20px; font-weight: bold; color: #155724;"><?php echo number_format($profile_stats['by_status']['active'] ?? 0); ?></div>
                            <div style="color: #155724; font-size: 11px;">Active</div>
                        </div>
                        <div style="background: #fff3cd; padding: 12px; border-radius: 4px; text-align: center;">
                            <div style="font-size: 20px; font-weight: bold; color: #856404;"><?php echo number_format($profile_stats['by_status']['warning'] ?? 0); ?></div>
                            <div style="color: #856404; font-size: 11px;">Warning</div>
                        </div>
                        <div style="background: #f8d7da; padding: 12px; border-radius: 4px; text-align: center;">
                            <div style="font-size: 20px; font-weight: bold; color: #721c24;"><?php echo number_format($profile_stats['by_status']['inactive'] ?? 0); ?></div>
                            <div style="color: #721c24; font-size: 11px;">Inactive</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
