<?php

  class pm_square_checkout {
    public $id = __CLASS__;
    public $name = 'Square Checkout';
    public $description = '';
    public $author = 'Mishael Ochu';
    public $version = '1.0';
    public $website = 'https://www.squareup.com/';
    public $priority = 0;

    public function options($items, $subtotal, $tax, $currency_code, $customer) {

      // If not enabled
      if (empty($this->settings['status'])) return;

      // If not in geo zone
      if (!empty($this->settings['geo_zone_id'])) {
        if (!reference::country($customer['country_code'])->in_geo_zone($this->settings['geo_zone_id'], $customer)) return;
      }

      $options = [];

      try {

        // Make sure the items are not forbidden
        if ($forbidden_items = preg_split('#\s,\s*#', $this->settings['forbidden_items'], -1, PREG_SPLIT_NO_EMPTY)) {
          if ($detected_forbidden_items = array_intersect($forbidden_items, array_column($items, 'id'))) {
            throw new Exception('The following products are forbidden with this option: '. implode(', ', $detected_forbidden_items));
          }
        }

        // Make sure options are not forbidden
        if ($forbidden_options = preg_split('#\s,\s*#', $this->settings['forbidden_options'], -1, PREG_SPLIT_NO_EMPTY)) {

          $forbidden_option_group_names = [];

          foreach ($forbidden_options as $attribute_group_id) {
            $forbidden_option_group_names[] = reference::attribute_group($attribute_group_id)->data['name'][language::$selected['code']];
          }

          foreach ($items as $item) {
            if (empty($item['options'])) continue;
            if ($detected_forbidden_option_group_names = array_intersect($forbidden_option_group_names, array_keys($item['options']))) {
              throw new Exception('The following options are forbidden: '. implode(', ', $detected_forbidden_option_group_names));
            }
          }
        }

        $options[] = [
          'id' => 'square',
          'icon' => 'images/payment/square.jpg',
          'name' => 'Square',
          'description' => language::translate(__CLASS__.':description', 'Payments processing with Square'),
          'fields' => '',
          'cost' => 0,
          'tax_class_id' => 0,
          'confirm' => language::translate(__CLASS__.':title_pay_now', 'Pay Now'),
        ];

      } catch (Exception $e) {
        $options[] = [
          'id' => 'square',
          'icon' => 'images/payment/square.jpg',
          'name' => 'Square',
          'description' => language::translate(__CLASS__.':description', 'Payments processing with Square'),
          'fields' => '',
          'cost' => 0,
          'tax_class_id' => 0,
          'error' => $e->getMessage(),
        ];
      }

      return [
        'title' => $this->name,
        'options' => $options,
      ];
    }

    public function transfer($order) {

      try {

        $order->save(); // Create order ID

        $request = [
          'idempotency_key' => uniqid(),
          'checkout_options' => [
            'redirect_url' => document::ilink('order_process')
          ],
          'pre_populated_data' => [
            'buyer_email' => $order->data['customer']['email'],
          ],
          'cancel_url' => document::ilink('checkout'),
          'order' => [
            'location_id' => $this->settings['location_id'],
            'reference_id' => (string)$order->data['id'],
            'name' => settings::get('store_name'),
            'line_items' => [],
          ],
        ];

        foreach ($order->data['items'] as $item) {
          if ($item['price'] <= 0) continue;
          $request['order']['line_items'][] = [
            'name' => $item['name'],
            'quantity' => (string)$item['quantity'],
            'base_price_money' => [
              'amount' => $this->_amount($item['price'] + $item['tax'], $order->data['currency_code'], $order->data['currency_value']),
              'currency' => $order->data['currency_code'],
            ],
          ];
        }

        foreach ($order->data['order_total'] as $row) {
          if (empty($row['calculate']) continue;
          $request['order']['line_items'][] = [
            'name' => $row['title'],
            'quantity' => 1,
            'base_price_money' => [
              'amount' => $this->_amount($row['value'] + $row['tax'], $order->data['currency_code'], $order->data['currency_value']),
              'currency' => $order->data['currency_code'],
            ],
          ];
        }

        $result = $this->_call('POST', '/online-checkout/payment-links', $request);

        session::$data['square']['order_id'] = $result['payment_link']['order_id'];

        return [
          'method' => 'GET',
          'action' => $result['payment_link']['url'],
        ];

      } catch (Exception $e) {
        return ['error' => $e->getMessage()];
      }
    }

    public function verify($order) {

      try {

        if (empty(session::$data['square']['order_id'])) {
          throw new Exception('Missing order id');
        }

        $result = $this->_call('GET', '/orders/'. session::$data['square']['order_id']);

        if (!$result) {
          throw new Exception('Failed to create payment link');
        }

        return [
          'is_payed' => 'true',
          'payment_terms' => 'PWO',
          'order_status_id' => $this->settings['order_status_id'],
          'transaction_id' => $result['order']['id'],
        ];

      } catch (Exception $e) {
        return ['error' => $e->getMessage()];
      }
    }

    private function _amount($amount, $currency_code, $currency_value) {

    // Zero-decimal currencies
      if (in_array($currency_code, explode(',', 'BIF,CLP,DJF,GNF,JPY,KMF,KRW,MGA,PYG,RWF,UGX,VND,VUV,XAF,XOF,XPF'))) {
        return currency::format_raw($amount, $currency_code, $currency_value);
      }

      return currency::format_raw($amount, $currency_code, $currency_value) * 100;
    }

    private function _call($method, $endpoint, $request = null) {

      $client = new wrap_http();

      $headers = [
        'Square-Version' => '2023-07-20',
        'Authorization' => 'Bearer '. $this->settings['access_token'],
        'Content-Type' => 'application/json',
      ];

      if (!$this->settings['is_production']) {
        $url = 'https://connect.squareupsandbox.com/v2'.$endpoint;
      } else {
        $url = 'https://connect.squareup.com/v2'.$endpoint;
      }

      // A log with the latest HTTP request is stored in the folder storage/logs/
      $response = $client->call($method, $url, $request ? json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '', $headers);

      if (!$result = json_decode($response, true)) {
        throw new Exception('Invalid response from remote machine');
      }

      if (!empty($result['error'])) {
        throw new Exception($result['error']['message']);
      }

      return $result;
    }

    function settings() {
      return [
        [
          'key' => 'status',
          'default_value' => '1',
          'title' => language::translate(__CLASS__.':title_status', 'Status'),
          'description' => language::translate(__CLASS__.':description_status', 'Enables or disables the module.'),
          'function' => 'toggle("e/d")',
        ],
        [
          'key' => 'is_production',
          'default_value' => '0',
          'title' => language::translate(__CLASS__.':title_is_production', 'Is Production Mode'),
          'description' => language::translate(__CLASS__.':description_status', 'Enables production mode or uses the sanbox for the module.'),
          'function' => 'toggle("y/n")',
        ],
        [
          'key' => 'icon',
          'default_value' => 'images/payment/square.jpg',
          'title' => language::translate(__CLASS__.':title_icon', 'Icon'),
          'description' => language::translate(__CLASS__.':description_icon', 'Path to an image to be displayed.'),
          'function' => 'text()',
        ],
        [
          'key' => 'application_id',
          'default_value' => '',
          'title' => language::translate(__CLASS__.':title_application_id', 'Application ID'),
          'description' => language::translate(__CLASS__.':description_application_id', 'Your application ID obtained from Square.'),
          'function' => 'text()',
        ],
        [
          'key' => 'access_token',
          'default_value' => '',
          'title' => language::translate(__CLASS__.':title_access_token', 'Access Token'),
          'description' => language::translate(__CLASS__.':description_access_token', 'Your access token obtained from  Square.'),
          'function' => 'text()',
        ],
        [
          'key' => 'location_id',
          'default_value' => '',
          'title' => language::translate(__CLASS__.':title_location_id', 'Location ID'),
          'description' => language::translate(__CLASS__.':description_location_id', 'Your location id obtained from  Square.'),
          'function' => 'text()',
        ],
        [
          'key' => 'order_status_id',
          'default_value' => '0',
          'title' => language::translate(__CLASS__.':title_order_status', 'Order Status'),
          'description' => language::translate(__CLASS__.':description_order_status', 'Give orders made with this payment module the following order status.'),
          'function' => 'order_status()',
        ],
        [
          'key' => 'geo_zone_id',
          'default_value' => '',
          'title' => language::translate(__CLASS__.':title_geo_zone_limitation', 'Geo Zone Limitation'),
          'description' => language::translate(__CLASS__.':description_geo_zone', 'Limit this module to the selected geo zone. Otherwise, leave it blank.'),
          'function' => 'geo_zone()',
        ],
        [
          'key' => 'priority',
          'default_value' => '0',
          'title' => language::translate(__CLASS__.':title_priority', 'Priority'),
          'description' => language::translate(__CLASS__.':description_priority', 'Process this module in the given priority order.'),
          'function' => 'number()',
        ],
        [
          'key' => 'forbidden_items',
          'default_value' => '',
          'title' => language::translate(__CLASS__.':title_forbidden_Items', 'Forbidden Items'),
          'description' => language::translate(__CLASS__.':description_forbidden_items', 'A comma separated list of items (by product ID#) for which this module should be disabled.'),
          'function' => 'products()',
          'multiple' => 'true',
        ],
        [
          'key' => 'forbidden_options',
          'default_value' => '',
          'title' => language::translate(__CLASS__.':title_forbidden_options', 'Forbidden Options'),
          'description' => language::translate(__CLASS__.':description_forbidden_options', 'A comma separated list of payment options for which this module should be disabled.'),
          'function' => 'attribute_groups()',
          'multiple' => 'true',
        ],
      ];
    }

    public function install() {}

    public function uninstall() {}
  }
