<?php
namespace SCW;

if (!defined('ABSPATH')) exit;

class Utils {
    public static function get_settings(): array {
        $defaults = [
            'env' => 'uat',

            // Base URL should be like https://api.shipcompliant.com (same host used by Swagger)
            'uat_base_url' => 'https://uat-api.shipcompliant.com',
            'prod_base_url' => 'https://api.shipcompliant.com',

            // Basic Auth (Web Service User)
            'username' => '',
            'password' => '',

            // Debug logging
            'debug' => 0,

            // Whether to block checkout on invalid address validation
            'block_on_invalid_address' => 1,

            // Default Connect v1 endpoints (edit in admin if needed)
            'endpoint_validate_address' => '/api/v1/addresses/validate',
            'endpoint_quote'            => '/api/v1/salesOrders/quote',
            'endpoint_commit'           => '/api/v1/salesOrders/commit',
            'endpoint_tracking'         => '/api/v1/salesOrders/tracking',
        ];

        $saved = get_option('scw_settings', []);
        return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
    }

    public static function update_settings(array $settings): void {
        update_option('scw_settings', $settings, false);
    }

    public static function base_url(): string {
        $s = self::get_settings();
        $env = $s['env'] ?? 'uat';
        $base = ($env === 'prod') ? ($s['prod_base_url'] ?? '') : ($s['uat_base_url'] ?? '');
        return rtrim((string)$base, '/');
    }

    /**
     * Parse mapping textarea lines: WOO_SKU=SC_CODE
     */
    public static function parse_sku_map_text(string $raw): array {
        $lines = preg_split('/\R/', (string)$raw);
        $map = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) continue;

            $woo = trim(sanitize_text_field($parts[0]));
            $sc  = trim(sanitize_text_field($parts[1]));

            if ($woo !== '' && $sc !== '') {
                $map[$woo] = $sc;
            }
        }

        return $map;
    }

    public static function sku_map_to_text(array $map): string {
        if (!is_array($map)) return '';
        $lines = [];
        foreach ($map as $woo => $sc) {
            $lines[] = $woo . '=' . $sc;
        }
        return implode("\n", $lines);
    }
}
