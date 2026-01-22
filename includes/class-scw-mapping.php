<?php
namespace SCW;

if (!defined('ABSPATH')) exit;

class Mapping {

    /**
     * Default: SC productCode = Woo SKU
     * If you need overrides, add them in the mapping UI (WOO_SKU=SC_CODE).
     */
    public static function sc_code_for_sku(string $sku): string {
        $sku = trim((string)$sku);
        if ($sku === '') return '';

        $map = get_option('scw_sku_map', []);
        if (is_array($map) && isset($map[$sku]) && $map[$sku] !== '') {
            return (string)$map[$sku];
        }

        return $sku;
    }

    public static function get_map(): array {
        $map = get_option('scw_sku_map', []);
        return is_array($map) ? $map : [];
    }

    public static function save_map(array $map): void {
        $clean = [];
        foreach ($map as $woo => $sc) {
            $woo = trim(sanitize_text_field($woo));
            $sc  = trim(sanitize_text_field($sc));
            if ($woo !== '' && $sc !== '') $clean[$woo] = $sc;
        }
        update_option('scw_sku_map', $clean, false);
    }
}
