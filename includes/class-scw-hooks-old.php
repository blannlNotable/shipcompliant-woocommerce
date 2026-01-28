<?php
namespace SCW;

if (!defined('ABSPATH')) exit;

class Hooks {

    public static function init(): void {
        // Checkout updates (address/cart changes): validate + save + quote
        add_action('woocommerce_checkout_update_order_review', [__CLASS__, 'checkout_update'], 20, 1);

        // Persist SalesOrderKey onto the order when it is created
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'attach_sales_order_key_to_order'], 20, 2);

        // Payment complete: commit order (some gateways use this)
        add_action('woocommerce_payment_complete', [__CLASS__, 'payment_complete'], 20, 1);

        // Commit when order moves to Processing (reliable for manual gateways)
        add_action('woocommerce_order_status_processing', [__CLASS__, 'payment_complete'], 20, 1);

        // Optional: commit if order goes straight to Completed
        add_action('woocommerce_order_status_completed', [__CLASS__, 'payment_complete'], 20, 1);

        // Admin order action to push tracking manually
        add_filter('woocommerce_order_actions', [__CLASS__, 'add_order_action']);
        add_action('woocommerce_order_action_scw_push_tracking', [__CLASS__, 'order_action_push_tracking']);
    }

    /**
     * Get or create a per-checkout token stored in WC session.
     */
    private static function get_checkout_token(): string {
        if (!WC()->session) return wp_generate_password(12, false, false);

        $token = (string) WC()->session->get('scw_checkout_token');
        if ($token === '') {
            $token = wp_generate_password(12, false, false);
            WC()->session->set('scw_checkout_token', $token);
        }
        return $token;
    }

    /**
     * Freeze volatile dates per checkout so payload hash is stable across refreshes.
     */
    private static function get_frozen_dates(): array {
        $purchase = gmdate('c');
        $ship     = gmdate('c', time() + 86400);

        if (WC()->session) {
            $purchase = (string) WC()->session->get('scw_purchase_date');
            $ship     = (string) WC()->session->get('scw_ship_date');

            if ($purchase === '') {
                $purchase = gmdate('c');
                WC()->session->set('scw_purchase_date', $purchase);
            }
            if ($ship === '') {
                $ship = gmdate('c', time() + 86400);
                WC()->session->set('scw_ship_date', $ship);
            }
        }

        return [$purchase, $ship];
    }

    /**
     * Build a SalesOrderKey unique per checkout session.
     */
    private static function build_sales_order_key(array $shipToForHash = []): string {
        $sessionCustomerId = (WC()->session) ? (string) WC()->session->get_customer_id() : '';
        if ($sessionCustomerId === '') {
            $sessionCustomerId = 'guest-' . substr(md5(wp_json_encode($shipToForHash)), 0, 8);
        }

        $token = self::get_checkout_token();

        // Example: wc-quote-5-AbC123xYz9Qw
        return 'wc-quote-' . $sessionCustomerId . '-' . $token;
    }

    /**
     * Attach SalesOrderKey to Woo order meta at creation time so commit uses the SAME key.
     */
    public static function attach_sales_order_key_to_order($order, $data): void {
        if (!$order instanceof \WC_Order) return;

        $key = '';
        if (WC()->session) {
            $key = (string) WC()->session->get('scw_last_quote_salesOrderKey');
        }

        if ($key !== '') {
            $order->update_meta_data('_scw_sales_order_key', $key);
        }
    }

    /**
     * Checkout update hook: runs when checkout fields change (address/qty).
     */
    public static function checkout_update($posted_data): void {
        
        if (is_admin() || !WC()->cart || WC()->cart->is_empty()) return;

        parse_str($posted_data, $data);

        // ---------------------------
        // 1) Build shipping address for /addresses/validate (lowercase keys)
        // ---------------------------
        $ship_validate = [
            'street1' => sanitize_text_field($data['shipping_address_1'] ?? ''),
            'street2' => sanitize_text_field($data['shipping_address_2'] ?? ''),
            'city'    => sanitize_text_field($data['shipping_city'] ?? ''),
            'state'   => sanitize_text_field($data['shipping_state'] ?? ''),
            'zip1'    => sanitize_text_field($data['shipping_postcode'] ?? ''),
            'zip2'    => '',
            'country' => sanitize_text_field($data['shipping_country'] ?? 'US'),
        ];

        // Skip until the user enters enough address
        if (empty($ship_validate['street1']) || empty($ship_validate['zip1']) || empty($ship_validate['country'])) {
            return;
        }

        $client = new Client();
        if (!$client->can_auth()) return;

        $s = Utils::get_settings();

        // ---------------------------
        // 2) Validate Address
        // ---------------------------
        $validatePayload = [
            'address' => $ship_validate,
        ];

        $resValidate = $client->request('POST', $s['endpoint_validate_address'], $validatePayload);

        if (!$resValidate['ok']) {
            wc_add_notice('Address validation failed: ' . ($resValidate['error'] ?? 'Unknown'), 'error');
            return;
        }

        $isValid = true;
        if (is_array($resValidate['data'])) {
            $isValid = $resValidate['data']['isValid']
                ?? $resValidate['data']['IsValid']
                ?? $resValidate['data']['valid']
                ?? true;
        }

        if (!$isValid && !empty($s['block_on_invalid_address'])) {
            wc_add_notice('Shipping address could not be validated. Please check the address.', 'error');
            return;
        }

        // ---------------------------
        // 3) Build BillTo/ShipTo for SalesOrder (PascalCase keys)
        // ---------------------------
        $billing_first = sanitize_text_field($data['billing_first_name'] ?? '');
        $billing_last  = sanitize_text_field($data['billing_last_name'] ?? '');
        $billing_email = sanitize_email($data['billing_email'] ?? '');
        $billing_phone = sanitize_text_field($data['billing_phone'] ?? '');

        $billTo = [
            'FirstName' => $billing_first ?: sanitize_text_field($data['shipping_first_name'] ?? 'Test'),
            'LastName'  => $billing_last  ?: sanitize_text_field($data['shipping_last_name'] ?? 'User'),
            'Company'   => sanitize_text_field($data['billing_company'] ?? ''),
            'Street1'   => sanitize_text_field($data['billing_address_1'] ?? ($data['shipping_address_1'] ?? '')),
            'Street2'   => sanitize_text_field($data['billing_address_2'] ?? ($data['shipping_address_2'] ?? '')),
            'City'      => sanitize_text_field($data['billing_city'] ?? ($data['shipping_city'] ?? '')),
            'State'     => sanitize_text_field($data['billing_state'] ?? ($data['shipping_state'] ?? '')),
            'Zip1'      => sanitize_text_field($data['billing_postcode'] ?? ($data['shipping_postcode'] ?? '')),
            'Zip2'      => '',
            'Country'   => sanitize_text_field($data['billing_country'] ?? ($data['shipping_country'] ?? 'US')),
            'Email'     => $billing_email,
            'Phone'     => $billing_phone,
            'DateOfBirth' => '1970-01-01T00:00:00Z',
        ];

        $shipTo = [
            'FirstName' => sanitize_text_field($data['shipping_first_name'] ?? ($data['billing_first_name'] ?? 'Test')),
            'LastName'  => sanitize_text_field($data['shipping_last_name'] ?? ($data['billing_last_name'] ?? 'User')),
            'Company'   => sanitize_text_field($data['shipping_company'] ?? ''),
            'Street1'   => sanitize_text_field($data['shipping_address_1'] ?? ''),
            'Street2'   => sanitize_text_field($data['shipping_address_2'] ?? ''),
            'City'      => sanitize_text_field($data['shipping_city'] ?? ''),
            'State'     => sanitize_text_field($data['shipping_state'] ?? ''),
            'Zip1'      => sanitize_text_field($data['shipping_postcode'] ?? ''),
            'Zip2'      => '',
            'Country'   => sanitize_text_field($data['shipping_country'] ?? 'US'),
            'Email'     => $billing_email,
            'Phone'     => $billing_phone,
            'DateOfBirth' => '1970-01-01T00:00:00Z',
        ];

        // ---------------------------
        // 4) Build ShipmentItems from Woo cart
        // ---------------------------
        $shipmentItems = [];

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product) continue;

            $sku = (string) $product->get_sku();
            $qty = (int) ($cart_item['quantity'] ?? 1);

            if ($sku === '') {
                wc_add_notice('A product in your cart is missing a SKU (required for compliance).', 'error');
                return;
            }

            $productKey = Mapping::sc_code_for_sku($sku);
            $unitPrice = (float) wc_get_price_excluding_tax($product);

            $shipmentItems[] = [
                'ProductKey'       => $productKey,
                'ProductQuantity'  => $qty,
                'ProductUnitPrice' => $unitPrice,
            ];
        }

        if (empty($shipmentItems)) {
            wc_add_notice('Cart is empty or missing valid products for compliance quote.', 'error');
            return;
        }

        // ---------------------------
        // 5) Build SalesOrderKey + payload
        // ---------------------------
        $customerKey = is_user_logged_in()
            ? ('wc-user-' . get_current_user_id())
            : ('wc-guest-' . md5($billing_email ?: 'guest'));

        $salesOrderKey = self::build_sales_order_key($shipTo);

        // Freeze dates per checkout so refresh hashing works
        [$purchaseDate, $shipDate] = self::get_frozen_dates();

        $salesOrder = [
            'BillTo'       => $billTo,
            'CustomerKey'  => $customerKey,
            'OrderType'    => 'Internet',
            'PurchaseDate' => $purchaseDate,
            'SalesOrderKey'=> $salesOrderKey,
            'SalesTaxCollected' => 0.0,
            'Shipments' => [
                [
                    'ShipmentKey'   => '1',
                    'ShipTo'        => $shipTo,
                    'ShipmentItems' => $shipmentItems,
                    'LicenseRelationship' => 'SupplierToConsumer',
                    'ShipDate'            => $shipDate,
                    'ShipmentStatus'      => 'SentToFulfillment',
                    'ShippingService'     => 'FEX',
                ]
            ],
        ];

        $addressOption = [
            'IgnoreStreetLevelErrors'  => false,
            'RejectIfAddressSuggested' => true
        ];

        // ---------------------------
        // 5a) Reduce API spam: skip if payload has not changed
        // ---------------------------
        $savePayload = [
            'SalesOrder'    => $salesOrder,
            'AddressOption' => $addressOption,
            'PersistOption' => 'Null',
        ];

        $hash = md5(wp_json_encode($savePayload));
        $lastHash = (WC()->session) ? (string) WC()->session->get('scw_last_save_hash') : '';
        if ($hash === $lastHash) {
            return;
        }
        if (WC()->session) {
            WC()->session->set('scw_last_save_hash', $hash);
        }

        // ---------------------------
        // 5b) SAVE SalesOrder (persist) — REQUIRED so commit can find SalesOrderKey later
        // ---------------------------
        $endpoint_sales_order = $s['endpoint_sales_order'] ?? '/api/v1/salesOrders';
        $resSave = $client->request('POST', $endpoint_sales_order, $savePayload);

        if (!$resSave['ok']) {

            $err = (string) ($resSave['error'] ?? '');
            $err_lc = strtolower($err);

            // ShipCompliant sometimes returns "SalesOrder has been committed" (often code 215)
            if (strpos($err_lc, 'has been committed') !== false || strpos($err_lc, '"code":"215"') !== false) {

                // Reset ONLY the checkout session state so a new SalesOrderKey will be generated next refresh
                if (WC()->session) {
                    WC()->session->set('scw_checkout_token', '');
                    WC()->session->__unset('scw_last_quote_salesOrderKey');
                    WC()->session->__unset('scw_last_quote');
                    WC()->session->__unset('scw_last_save_hash');
                    WC()->session->__unset('scw_purchase_date');
                    WC()->session->__unset('scw_ship_date');
                }

                // Don't show customer-facing error here; just stop this refresh.
                return;
            }

            wc_add_notice('ShipCompliant save SalesOrder failed: ' . ($resSave['error'] ?? 'Unknown'), 'error');
            return;
        }

        // ---------------------------
        // 5c) QUOTE SalesOrder
        // ---------------------------
        $quotePayload = [
            'SalesOrder'    => $salesOrder,
            'AddressOption' => $addressOption,
        ];

        $resQuote = $client->request('POST', $s['endpoint_quote'], $quotePayload);

        if (!$resQuote['ok']) {
            wc_add_notice('Compliance/tax quote failed: ' . ($resQuote['error'] ?? 'Unknown'), 'error');
            return;
        }

        

        // Save quote response + key for later commit
        if (WC()->session) {
            WC()->session->set('scw_last_quote', $resQuote['data']);
            WC()->session->set('scw_last_quote_salesOrderKey', $salesOrderKey);
        }
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

        $s = Utils::get_settings();

        // Commit needs the SAME SalesOrderKey used in save/quote
        $salesOrderKey = (string) $order->get_meta('_scw_sales_order_key');

        // Fallback to session (if meta not present for some reason)
        if ($salesOrderKey === '' && WC()->session) {
            $salesOrderKey = (string) WC()->session->get('scw_last_quote_salesOrderKey');
        }

        if ($salesOrderKey === '') {
            $order->add_order_note('ShipCompliant commit FAILED: missing SalesOrderKey (no _scw_sales_order_key).');
            return;
        }

        $commitPayload = [
            'CommitOption'      => 'AllShipments',
            'SalesOrderKey'     => $salesOrderKey,
            'SalesTaxCollected' => (float) $order->get_total_tax(),
        ];

        $res = $client->request('POST', $s['endpoint_commit'], $commitPayload);

        if (!$res['ok']) {
            $order->add_order_note('ShipCompliant commit FAILED: ' . ($res['error'] ?? 'Unknown'));
            return;
        }

        $scOrderId = $res['data']['id'] ?? $res['data']['orderId'] ?? null;
        if ($scOrderId) {
            $order->update_meta_data('_scw_order_id', $scOrderId);
        }

        $order->update_meta_data('_scw_committed', 'yes');
        $order->save();

        $order->add_order_note('ShipCompliant commit OK. SalesOrderKey: ' . $salesOrderKey . ($scOrderId ? (' | SC Order ID: ' . $scOrderId) : ''));

        // IMPORTANT: clear token + quote cache so next checkout generates a fresh SalesOrderKey
        if (WC()->session) {
            WC()->session->set('scw_checkout_token', '');
            WC()->session->__unset('scw_last_quote_salesOrderKey');
            WC()->session->__unset('scw_last_quote');
            WC()->session->__unset('scw_last_save_hash');
            WC()->session->__unset('scw_purchase_date');
            WC()->session->__unset('scw_ship_date');
        }
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

        $s = Utils::get_settings();

        // Preferred: SC order id if your commit returns one (often it doesn't)
        $scOrderId = (string) $order->get_meta('_scw_order_id');

        // Fallback: SalesOrderKey is ALWAYS known and is the safest identifier in your integration
        $salesOrderKey = (string) $order->get_meta('_scw_sales_order_key');

        if ($scOrderId === '' && $salesOrderKey === '') {
            $order->add_order_note('ShipCompliant tracking push failed: missing SC order ID and SalesOrderKey. Commit first.');
            return;
        }

        $trackingNumber = (string) $order->get_meta('_tracking_number');
        $carrier        = (string) $order->get_meta('_tracking_carrier');

        if ($trackingNumber === '' || $carrier === '') {
            $order->add_order_note('ShipCompliant tracking push skipped: tracking number/carrier not found on order meta.');
            return;
        }

        // Send BOTH identifiers. SC may accept orderId OR SalesOrderKey depending on your account/swagger.
        $payload = [
            'orderId'        => ($scOrderId !== '' ? $scOrderId : null),
            'salesOrderKey'  => ($salesOrderKey !== '' ? $salesOrderKey : null),
            'shipmentKey'    => '1',
            'carrier'        => $carrier,
            'trackingNumber' => $trackingNumber,
            'shipDate'       => gmdate('Y-m-d'),
        ];

        // Remove null/empty keys
        foreach ($payload as $k => $v) {
            if ($v === null || $v === '') unset($payload[$k]);
        }

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
