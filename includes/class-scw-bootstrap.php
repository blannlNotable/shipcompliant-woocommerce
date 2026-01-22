<?php
namespace SCW;

if (!defined('ABSPATH')) exit;

require_once SCW_PLUGIN_DIR . 'includes/class-scw-utils.php';
require_once SCW_PLUGIN_DIR . 'includes/class-scw-logger.php';
require_once SCW_PLUGIN_DIR . 'includes/class-scw-client.php';
require_once SCW_PLUGIN_DIR . 'includes/class-scw-mapping.php';
require_once SCW_PLUGIN_DIR . 'includes/class-scw-admin.php';
require_once SCW_PLUGIN_DIR . 'includes/class-scw-hooks.php';

class Bootstrap {
    public static function init(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>ShipCompliant WooCommerce:</strong> WooCommerce is required.</p></div>';
            });
            return;
        }

        Admin::init();
        Hooks::init();
    }
}
