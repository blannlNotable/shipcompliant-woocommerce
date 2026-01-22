<?php
namespace SCW;

if (!defined('ABSPATH')) exit;

class Hooks {

    public static function init(): void {
        // Checkout updates (address/cart changes): validate + quote
        add_action('woocommerce_checkout_update_order_review', [__CLASS__, 'checkout_update'], 20, 1);

        // Payment complete: commit order
        add_action('woocommerce_payment_complete', [__CLASS__, 'payment_complete'], 20, 1);

        // Admin order action to push tracking manually
        add_filter('woocommerce_order_actions', [__CLASS__, 'add_order_action']);
        add_action('woocommerce_order_action_scw_push_tracking', [__CLASS__, 'order_action_push_tracking']);
    }

    public static function checkout_update($posted_data): void {
        if (is_admin() || !WC()->cart || WC()->cart->is_empty()) return;

        parse_str($posted_data, $data);

        $ship = [
            'street1'    => sanitize_text_field($data['shipping_address_1'] ?? ''),
            'street2'    => sanitize_text_field($data['shipping_address_2'] ?? ''),
            'city'        => sanitize_text_field($data['shipping_city'] ?? ''),
            'state'       => sanitize_text_field($data['shipping_state'] ?? ''),
            'zip1'  => sanitize_text_field($data['shipping_postcode'] ?? ''),
            'country'     => sanitize_text_field($data['shipping_country'] ?? ''),
        ];

        // Skip until the user actually enters an address
        if (empty($ship['street1']) || empty($ship['zip1']) || empty($ship['country'])) return;

        $client = new Client();
        if (!$client->can_auth()) return;

        $s = Utils::get_settings();

        // 1) Validate Address
        $validatePayload = [
            // TODO: match swagger schema if different
            'address' => $ship
        ];

        $resValidate = $client->request('POST', $s['endpoint_validate_address'], $validatePayload);

        if (!$resValidate['ok']) {
            wc_add_notice('Address validation failed: ' . ($resValidate['error'] ?? 'Unknown'), 'error');
            return;
        }

        // If your response includes a boolean flag, check it here (field name may differ)
        $isValid = $resValidate['data']['isValid'] ?? true; // TODO: confirm response field
        if (!$isValid && !empty($s['block_on_invalid_address'])) {
            wc_add_notice('Shipping address could not be validated. Please check the address.', 'error');
            return;
        }

        // 2) Build items from Woo cart
        $items = [];
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product) continue;

            $sku = (string)$product->get_sku();
            $qty = (int)($cart_item['quantity'] ?? 1);

            if ($sku === '') {
                wc_add_notice('A product in your cart is missing a SKU (required for compliance).', 'error');
                return;
            }

            $items[] = [
                // TODO: confirm field names for line items
                'productCode' => Mapping::sc_code_for_sku($sku),
                'quantity' => $qty,
            ];
        }

        // 3) Quote (compliance + taxes)
        $quotePayload = [
            // TODO: match swagger schema
            'shipTo' => $ship,
            'items'  => $items,
        ];

        $resQuote = $client->request('POST', $s['endpoint_quote'], $quotePayload);

        if (!$resQuote['ok']) {
            wc_add_notice('Compliance/tax quote failed: ' . ($resQuote['error'] ?? 'Unknown'), 'error');
            return;
        }

        // Save quote response for later commit
        WC()->session->set('scw_last_quote', $resQuote['data']);
    }

    public static function payment_complete($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Prevent double commits
        if ($order->get_meta('_scw_committed') === 'yes') return;

        $client = new Client();
        if (!$client->can_auth()) {
            $order->add_order_note('ShipCompliant commit skipped: missing credentials.');
            return;
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;

            $sku = (string)$product->get_sku();
            if ($sku === '') {
                $order->add_order_note('ShipCompliant commit blocked: item missing SKU.');
                return;
            }

            $items[] = [
                'productCode' => Mapping::sc_code_for_sku($sku),
                'quantity' => (int)$item->get_quantity(),
            ];
        }

        $shipTo = [
            'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
            'address1' => $order->get_shipping_address_1(),
            'address2' => $order->get_shipping_address_2(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'postalCode' => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        ];

        $quote = (WC()->session) ? WC()->session->get('scw_last_quote') : null;

        $payload = [
            // TODO: match swagger schema
            'externalOrderId' => (string)$order->get_id(),
            'shipTo' => $shipTo,
            'items' => $items,
            'totals' => [
                'orderTotal' => (float)$order->get_total(),
                'shippingTotal' => (float)$order->get_shipping_total(),
                'taxTotal' => (float)$order->get_total_tax(),
            ],
            // optional if SC accepts quote object/reference
            'quote' => $quote,
        ];

        $s = Utils::get_settings();
        $res = $client->request('POST', $s['endpoint_commit'], $payload);

        if (!$res['ok']) {
            $order->add_order_note('ShipCompliant commit FAILED: ' . ($res['error'] ?? 'Unknown'));
            return;
        }

        // TODO: confirm response ID fields
        $scOrderId = $res['data']['id'] ?? $res['data']['orderId'] ?? null;
        if ($scOrderId) {
            $order->update_meta_data('_scw_order_id', $scOrderId);
        }

        $order->update_meta_data('_scw_committed', 'yes');
        $order->save();

        $order->add_order_note('ShipCompliant commit OK. SC Order ID: ' . ($scOrderId ?: '(not returned)'));
    }

    public static function add_order_action($actions): array {
        $actions['scw_push_tracking'] = 'Push tracking to ShipCompliant';
        return $actions;
    }

    public static function order_action_push_tracking($order): void {
        if (!$order instanceof \WC_Order) return;

        $client = new Client();
        if (!$client->can_auth()) {
            $order->add_order_note('ShipCompliant tracking push skipped: missing credentials.');
            return;
        }

        $scOrderId = $order->get_meta('_scw_order_id');
        if (!$scOrderId) {
            $order->add_order_note('ShipCompliant tracking push failed: missing SC order ID. Commit first.');
            return;
        }

        // These meta keys depend on your tracking plugin.
        // Update these to match your store.
        $trackingNumber = (string)$order->get_meta('_tracking_number');
        $carrier        = (string)$order->get_meta('_tracking_carrier');

        if ($trackingNumber === '' || $carrier === '') {
            $order->add_order_note('ShipCompliant tracking push skipped: tracking number/carrier not found on order meta.');
            return;
        }

        $payload = [
            // TODO: match swagger schema
            'orderId' => $scOrderId,
            'carrier' => $carrier,
            'trackingNumber' => $trackingNumber,
            'shipDate' => gmdate('Y-m-d'),
        ];

        $s = Utils::get_settings();
        $res = $client->request('POST', $s['endpoint_tracking'], $payload);

        if (!$res['ok']) {
            $order->add_order_note('ShipCompliant tracking push FAILED: ' . ($res['error'] ?? 'Unknown'));
            return;
        }

        $order->add_order_note('ShipCompliant tracking push OK: ' . $carrier . ' ' . $trackingNumber);
        $order->update_meta_data('_scw_tracking_pushed', 'yes');
        $order->save();
    }
}
