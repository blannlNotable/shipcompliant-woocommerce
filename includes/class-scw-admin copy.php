<?php
namespace SCW;

if (!defined('ABSPATH')) exit;

class Admin {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'handle_post']);
    }

    public static function menu(): void {
        add_submenu_page(
            'woocommerce',
            'ShipCompliant Integration',
            'ShipCompliant',
            'manage_woocommerce',
            'scw-settings',
            [__CLASS__, 'render']
        );
    }

    public static function handle_post(): void {
        if (!is_admin()) return;

        // Save settings
        if (isset($_POST['scw_save_settings']) && check_admin_referer('scw_save_settings_nonce')) {
            $s = Utils::get_settings();

            $s['env'] = sanitize_text_field($_POST['env'] ?? 'uat');
            $s['uat_base_url'] = esc_url_raw($_POST['uat_base_url'] ?? '');
            $s['prod_base_url'] = esc_url_raw($_POST['prod_base_url'] ?? '');

            $s['username'] = sanitize_text_field($_POST['username'] ?? '');
            $s['password'] = sanitize_text_field($_POST['password'] ?? '');

            $s['debug'] = !empty($_POST['debug']) ? 1 : 0;
            $s['block_on_invalid_address'] = !empty($_POST['block_on_invalid_address']) ? 1 : 0;

            $s['endpoint_validate_address'] = sanitize_text_field($_POST['endpoint_validate_address'] ?? $s['endpoint_validate_address']);
            $s['endpoint_quote'] = sanitize_text_field($_POST['endpoint_quote'] ?? $s['endpoint_quote']);
            $s['endpoint_commit'] = sanitize_text_field($_POST['endpoint_commit'] ?? $s['endpoint_commit']);
            $s['endpoint_tracking'] = sanitize_text_field($_POST['endpoint_tracking'] ?? $s['endpoint_tracking']);

            Utils::update_settings($s);

            // Save mapping
            $mapText = (string)($_POST['sku_map'] ?? '');
            $map = Utils::parse_sku_map_text($mapText);
            Mapping::save_map($map);

            add_action('admin_notices', function () {
                echo '<div class="notice notice-success"><p>ShipCompliant settings saved.</p></div>';
            });
        }

        // Test connection (calls address validate with a dummy address? safer to just hit base status if you have one)
        if (isset($_POST['scw_test']) && check_admin_referer('scw_test_nonce')) {
            $client = new Client();

            $result = ['ok' => false, 'error' => 'Missing credentials/base URL'];

            if ($client->can_auth()) {
                // Lightweight test: call validate endpoint with a minimal payload (adjust if your schema requires more)
                $s = Utils::get_settings();
                $payload = [
                    'address' => [
                        'street1' => '1 Main St',
                        'City' => 'Napa',
                        'state' => 'CA',
                        'Zip1' => '94558',
                        'country' => 'US'
                    ]
                ];
                $result = $client->request('POST', $s['endpoint_validate_address'], $payload);
            }

            add_action('admin_notices', function () use ($result) {
                if (!empty($result['ok'])) {
                    echo '<div class="notice notice-success"><p><strong>ShipCompliant connection OK.</strong></p></div>';
                } else {
                    echo '<div class="notice notice-error"><p><strong>ShipCompliant connection FAILED:</strong> ' . esc_html($result['error'] ?? 'Unknown') . '</p></div>';
                }
            });
        }
    }

    public static function render(): void {
        $s = Utils::get_settings();
        $mapText = Utils::sku_map_to_text(Mapping::get_map());
        ?>
        <div class="wrap">
            <h1>ShipCompliant WooCommerce Integration</h1>

            <form method="post">
                <?php wp_nonce_field('scw_save_settings_nonce'); ?>

                <h2>Connection</h2>
                <table class="form-table">
                    <tr>
                        <th>Environment</th>
                        <td>
                            <select name="env">
                                <option value="uat" <?php selected($s['env'], 'uat'); ?>>UAT</option>
                                <option value="prod" <?php selected($s['env'], 'prod'); ?>>Production</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>UAT Base URL</th>
                        <td><input class="regular-text" type="text" name="uat_base_url" value="<?php echo esc_attr($s['uat_base_url']); ?>"></td>
                    </tr>
                    <tr>
                        <th>PROD Base URL</th>
                        <td><input class="regular-text" type="text" name="prod_base_url" value="<?php echo esc_attr($s['prod_base_url']); ?>"></td>
                    </tr>
                    <tr>
                        <th>Web Service User (email)</th>
                        <td><input class="regular-text" type="text" name="username" value="<?php echo esc_attr($s['username']); ?>"></td>
                    </tr>
                    <tr>
                        <th>Password</th>
                        <td><input class="regular-text" type="password" name="password" value="<?php echo esc_attr($s['password']); ?>"></td>
                    </tr>
                </table>

                <h2>Endpoints</h2>
                <table class="form-table">
                    <tr><th>Validate Address</th><td><input class="regular-text" type="text" name="endpoint_validate_address" value="<?php echo esc_attr($s['endpoint_validate_address']); ?>"></td></tr>
                    <tr><th>Quote</th><td><input class="regular-text" type="text" name="endpoint_quote" value="<?php echo esc_attr($s['endpoint_quote']); ?>"></td></tr>
                    <tr><th>Commit</th><td><input class="regular-text" type="text" name="endpoint_commit" value="<?php echo esc_attr($s['endpoint_commit']); ?>"></td></tr>
                    <tr><th>Tracking</th><td><input class="regular-text" type="text" name="endpoint_tracking" value="<?php echo esc_attr($s['endpoint_tracking']); ?>"></td></tr>
                </table>

                <h2>Behavior</h2>
                <table class="form-table">
                    <tr>
                        <th>Block checkout on invalid address</th>
                        <td><label><input type="checkbox" name="block_on_invalid_address" value="1" <?php checked($s['block_on_invalid_address'], 1); ?>> Enabled</label></td>
                    </tr>
                    <tr>
                        <th>Debug logging</th>
                        <td><label><input type="checkbox" name="debug" value="1" <?php checked($s['debug'], 1); ?>> Enabled (logs to PHP error_log)</label></td>
                    </tr>
                </table>

                <h2>SKU Mapping (optional)</h2>
                <p>Format: <code>WOO_SKU=SC_PRODUCT_CODE</code> one per line. If empty, SC code defaults to Woo SKU.</p>
                <textarea name="sku_map" rows="10" class="large-text code"><?php echo esc_textarea($mapText); ?></textarea>

                <p>
                    <button type="submit" name="scw_save_settings" class="button button-primary">Save Settings</button>
                </p>
            </form>

            <hr>

            <form method="post">
                <?php wp_nonce_field('scw_test_nonce'); ?>
                <button type="submit" name="scw_test" class="button">Test Connection</button>
                <p><em>Test uses the Validate Address endpoint with a dummy US address.</em></p>
            </form>
        </div>
        <?php
    }
}
