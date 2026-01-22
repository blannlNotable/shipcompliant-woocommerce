<?php
namespace SCW;

if (!defined('ABSPATH')) exit;

class Hooks {

    public static function init(): void {
        // Checkout updates (address/cart changes): validate + quote
        add_action('woocommerce_checkout_update_order_review', [__CLASS__, 'checkout_update'], 20, 1);

        // Payment complete: commit order
        // add_action('woocommerce_payment_complete', [__CLASS__, 'payment_complete'], 20, 1);
        // Commit when order moves to Processing (reliable for manual gateways)
        add_action('woocommerce_order_status_processing', [__CLASS__, 'payment_complete'], 20, 1);

        // Optional: commit if order goes straight to Completed
        add_action('woocommerce_order_status_completed', [__CLASS__, 'payment_complete'], 20, 1);

        // Admin order action to push tracking manually
        add_filter('woocommerce_order_actions', [__CLASS__, 'add_order_action']);
        add_action('woocommerce_order_action_scw_push_tracking', [__CLASS__, 'order_action_push_tracking']);
    }

    /**
     * Checkout update hook: runs when checkout fields change (address/qty).
     * - Validate address (/addresses/validate) expects lowercase keys: street1/zip1/city/state/country
     * - Quote (/salesOrders/quote) expects Swagger SalesOrder structure with PascalCase keys.
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

        // Skip until the user actually enters enough address
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

        // Optional: if response includes a validity flag, enforce it here (depends on SC response)
        // If you don't know the response fields yet, leave this permissive.
        $isValid = true;
        if (is_array($resValidate['data'])) {
            // common patterns (you may adjust based on actual response)
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
        // 3) Build BillTo/ShipTo for Quote (PascalCase keys per Swagger)
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
            // Optional if required by your account:
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
            // Optional if required by your account:
            'DateOfBirth' => '1970-01-01T00:00:00Z',
        ];

        // ---------------------------
        // 4) Build ShipmentItems from Woo cart (ProductKey/ProductQuantity/ProductUnitPrice)
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

            // Unit price excl tax (adjust if SC expects a different basis)
            $unitPrice = (float) wc_get_price_excluding_tax($product);

            $shipmentItems[] = [
                'ProductKey'       => $productKey,
                'ProductQuantity'  => $qty,
                'ProductUnitPrice' => $unitPrice,
                // Optional:
                // 'BrandKey' => 'Brand123',
                // 'CITB' => 'CITB',
            ];
        }

        if (empty($shipmentItems)) {
            wc_add_notice('Cart is empty or missing valid products for compliance quote.', 'error');
            return;
        }

        // ---------------------------
        // 5) Quote payload (matches your Swagger schema)
        // ---------------------------
        $customerKey = is_user_logged_in()
            ? ('wc-user-' . get_current_user_id())
            : ('wc-guest-' . md5($billing_email ?: 'guest'));

        $salesOrderKey = 'wc-quote-' . (WC()->session ? WC()->session->get_customer_id() : md5(wp_json_encode($shipTo)));

        $quotePayload = [
            'SalesOrder' => [
                'BillTo'       => $billTo,
                'CustomerKey'  => $customerKey,
                'OrderType'    => 'Internet',
                'PurchaseDate' => gmdate('c'),
                'SalesOrderKey'=> $salesOrderKey,

                'Shipments' => [
                    [
                        'ShipmentKey'   => '1',
                        'ShipTo'        => $shipTo,
                        'ShipmentItems' => $shipmentItems,
                        'LicenseRelationship' => 'SupplierToConsumer',
                        'ShipDate'            => gmdate('c', time() + 86400), // tomorrow
                        'ShipmentStatus'      => 'SentToFulfillment',
                        'ShippingService'     => 'FEX',
                        // Optional fields (only add if your SC setup requires them):
                        // 'FulfillmentHouse' => 'WineShipping',
                        // 'LicenseRelationship' => 'SupplierToConsumer',
                        // 'ShippingService' => 'FEX',
                    ]
                ],
            ],
            'AddressOption' => [
                'IgnoreStreetLevelErrors'  => false,
                'RejectIfAddressSuggested' => true
            ],
        ];

        $resQuote = $client->request('POST', $s['endpoint_quote'], $quotePayload);

        if (!$resQuote['ok']) {
            // Your screenshot showed ShipCompliant returning 500 for malformed payload; with this structure,
            // errors should become either 200 OK or explicit validation messages.
            wc_add_notice('Compliance/tax quote failed: ' . ($resQuote['error'] ?? 'Unknown'), 'error');
            return;
        }

        // Save quote response for later (commit)
        if (WC()->session) {
            WC()->session->set('scw_last_quote', $resQuote['data']);
            WC()->session->set('scw_last_quote_salesOrderKey', $salesOrderKey);
        }
    }

    /**
     * Payment complete -> commit.
     * NOTE: Your Swagger likely expects SalesOrder structure for commit too.
     * This is still the "simple" commit payload and will probably need updating next.
     */
    // public static function payment_complete($order_id): void {
    //     $order = wc_get_order($order_id);
    //     if (!$order) return;

    //     // Prevent double commits
    //     if ($order->get_meta('_scw_committed') === 'yes') return;

    //     $client = new Client();
    //     if (!$client->can_auth()) {
    //         $order->add_order_note('ShipCompliant commit skipped: missing credentials.');
    //         return;
    //     }

    //     $items = [];
    //     foreach ($order->get_items() as $item) {
    //         $product = $item->get_product();
    //         if (!$product) continue;

    //         $sku = (string)$product->get_sku();
    //         if ($sku === '') {
    //             $order->add_order_note('ShipCompliant commit blocked: item missing SKU.');
    //             return;
    //         }

    //         $items[] = [
    //             'productCode' => Mapping::sc_code_for_sku($sku),
    //             'quantity'    => (int)$item->get_quantity(),
    //         ];
    //     }

    //     $shipTo = [
    //         'name'       => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
    //         'address1'   => $order->get_shipping_address_1(),
    //         'address2'   => $order->get_shipping_address_2(),
    //         'city'       => $order->get_shipping_city(),
    //         'state'      => $order->get_shipping_state(),
    //         'postalCode' => $order->get_shipping_postcode(),
    //         'country'    => $order->get_shipping_country(),
    //         'email'      => $order->get_billing_email(),
    //         'phone'      => $order->get_billing_phone(),
    //     ];

    //     $quote = (WC()->session) ? WC()->session->get('scw_last_quote') : null;

    //     $payload = [
    //         'externalOrderId' => (string)$order->get_id(),
    //         'shipTo'          => $shipTo,
    //         'items'           => $items,
    //         'totals'          => [
    //             'orderTotal'    => (float)$order->get_total(),
    //             'shippingTotal' => (float)$order->get_shipping_total(),
    //             'taxTotal'      => (float)$order->get_total_tax(),
    //         ],
    //         'quote' => $quote,
    //     ];

    //     $s = Utils::get_settings();
    //     $res = $client->request('POST', $s['endpoint_commit'], $payload);

    //     if (!$res['ok']) {
    //         $order->add_order_note('ShipCompliant commit FAILED: ' . ($res['error'] ?? 'Unknown'));
    //         return;
    //     }

    //     $scOrderId = $res['data']['id'] ?? $res['data']['orderId'] ?? null;
    //     if ($scOrderId) {
    //         $order->update_meta_data('_scw_order_id', $scOrderId);
    //     }

    //     $order->update_meta_data('_scw_committed', 'yes');
    //     $order->save();

    //     $order->add_order_note('ShipCompliant commit OK. SC Order ID: ' . ($scOrderId ?: '(not returned)'));
    // }

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

        // IMPORTANT: Commit needs the SAME SalesOrderKey used in Quote.
        // We store it on the order meta when the order is created (you’ll add this in checkout_update / order creation step).
        $salesOrderKey = (string) $order->get_meta('_scw_sales_order_key');
        // $salesOrderKey = (string) WC()->session->get('scw_last_quote_salesOrderKey')?? '';

        // Fallback (not ideal): derive a stable key from order id if you decide to always use this for quote too.
        if ($salesOrderKey === '') {
            // $salesOrderKey = 'wc-order-' . $order->get_id();
            $salesOrderKey = (string) WC()->session->get('scw_last_quote_salesOrderKey')?? 'wc-order-' . $order->get_id();
            // NOTE: This will only work if your quote also used SalesOrderKey = wc-order-{id}.
            // Otherwise ShipCompliant won't find the quoted SalesOrder to commit.
        }

        $s = Utils::get_settings();

        $commitPayload = [
            'CommitOption'      => 'AllShipments',
            'SalesOrderKey'     => $salesOrderKey,
            'SalesTaxCollected' => (float) $order->get_total_tax(),
            // Optional: include payments only if required by your account
            // 'Payments' => [
            //     [
            //         'Amount'        => (float) $order->get_total(),
            //         'Type'          => 'CreditCard',
            //         'SubType'       => 'VISA',
            //         'TransactionID' => (string) $order->get_transaction_id(),
            //     ]
            // ],
        ];

        $res = $client->request('POST', $s['endpoint_commit'], $commitPayload);

        if (!$res['ok']) {
            $order->add_order_note('ShipCompliant commit FAILED: ' . ($res['error'] ?? 'Unknown'));
            return;
        }

        // Some setups return an id; others may not. Keep your existing handling.
        $scOrderId = $res['data']['id'] ?? $res['data']['orderId'] ?? null;
        if ($scOrderId) {
            $order->update_meta_data('_scw_order_id', $scOrderId);
        }

        $order->update_meta_data('_scw_committed', 'yes');
        $order->save();

        $order->add_order_note('ShipCompliant commit OK. SalesOrderKey: ' . $salesOrderKey . ($scOrderId ? (' | SC Order ID: ' . $scOrderId) : ''));
    }


    public static function add_order_action($actions): array {
        $actions['scw_push_tracking'] = 'Push tracking to ShipCompliant';
        return $actions;
    }

    /**
     * Manual tracking push (admin order action).
     * NOTE: Your Swagger for tracking may expect SalesOrder/Shipment structure too.
     * This is still the simple payload.
     */
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

        $trackingNumber = (string)$order->get_meta('_tracking_number');
        $carrier        = (string)$order->get_meta('_tracking_carrier');

        if ($trackingNumber === '' || $carrier === '') {
            $order->add_order_note('ShipCompliant tracking push skipped: tracking number/carrier not found on order meta.');
            return;
        }

        $payload = [
            'orderId'         => $scOrderId,
            'carrier'         => $carrier,
            'trackingNumber'  => $trackingNumber,
            'shipDate'        => gmdate('Y-m-d'),
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
