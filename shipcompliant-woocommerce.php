<?php
/**
 * Plugin Name: ShipCompliant WooCommerce (Custom)
 * Description: WooCommerce ↔ ShipCompliant Connect v1 REST integration scaffold (validate, quote, commit, tracking).
 * Version: 1.0.0
 * Author: Notable Design Co
 */

if (!defined('ABSPATH')) exit;

define('SCW_VERSION', '1.0.0');
define('SCW_PLUGIN_FILE', __FILE__);
define('SCW_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once SCW_PLUGIN_DIR . 'includes/class-scw-bootstrap.php';

add_action('plugins_loaded', function () {
    \SCW\Bootstrap::init();
});
