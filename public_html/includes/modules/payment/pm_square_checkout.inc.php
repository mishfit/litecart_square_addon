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

      $forbidden_items = $this-> settings['forbidden_items'];
      if (!empty($forbidden_items)) {
        foreach ($items as $item) {

          $product_id = $item['product_id'];

          $result = array_search(strval($product_id), $forbidden_items);

          if (!is_bool($result)) {
            $log_message = '['. date('Y-m-d H:i:s e').'] product is **forbidden**: ' . json_encode($item['name']) . PHP_EOL . PHP_EOL;
            file_put_contents(FS_DIR_STORAGE . 'logs/debug.log', $log_message, FILE_APPEND);
            return;
          } else {

          }
        }
      }


      $forbidden_options = $this-> settings['forbidden_options'];
      if (!empty($forbidden_options)) {
        $forbidden_option_names = array();

        foreach ($forbidden_options as $forbidden_option) {
          $forbidden_attribute_group = new ent_attribute_group($forbidden_option);

          $selected_language_code = language::$selected['code'];
          $forbidden_option_name = $forbidden_attribute_group->data['name'][$selected_language_code];
          array_push($forbidden_option_names, $forbidden_option_name);
        }

        foreach ($items as $item) {
            $options = $item['options'];
            if (!empty($options)) {
              $item_option_names = array_keys($options);
              if (!empty($item_option_names)) {
                foreach ($item_option_names as $item_option_name) {
                  $result = array_search($item_option_name, $forbidden_option_names);

                  if (!is_bool($result)) {
                    $log_message = '['. date('Y-m-d H:i:s e').'] option is **forbidden**: ' . json_encode($item['name']) . PHP_EOL . PHP_EOL;
                    file_put_contents(FS_DIR_STORAGE . 'logs/debug.log', $log_message, FILE_APPEND);
                    return;
                  } else { }
                }
              }
            }
          }
      }
      $options = [];

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
            'reference_id' => strval($order->data['id']),
            'name' => settings::get('store_name'),
            'line_items' => [],
          ],
        ];

        foreach ($order->data['order_total'] as $row) {
            if (empty($row['calculate'])) continue;

            $request['order']['service_charges'][] = [
              'name' => $row['title'],
              'amount_money' => [
                  'amount' => $this->_amount($row['value'], $order->data['currency_code'], $order->data['currency_value']),
                  'currency' => $order->data['currency_code'],
              ],
              "calculation_phase" => "SUBTOTAL_PHASE",
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

        if (empty($result)) {
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
      $production_url = 'https://connect.squareup.com/v2';
      $sandbox_url = 'https://connect.squareupsandbox.com/v2';
      $base_url = '';
      if (empty($this->settings['is_production'])) {
         $base_url = $sandbox_url;
      } else {
        $base_url = $production_url;
      }
      $client = new wrap_http();

      $headers = [
        'Square-Version' => '2023-07-20',
        'Authorization' => 'Bearer '. $this->settings['access_token'],
        'Content-Type' => 'application/json',
      ];

      $url = $base_url.$endpoint;
      $log_message = '['. date('Y-m-d H:i:s e').'] calling square url: ' . $url . PHP_EOL . PHP_EOL;
      file_put_contents(FS_DIR_STORAGE . 'logs/debug.log', $log_message, FILE_APPEND);
      $log_message = 'with data: ' . json_encode($request) . PHP_EOL . PHP_EOL;
      file_put_contents(FS_DIR_STORAGE . 'logs/debug.log', $log_message, FILE_APPEND);
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
          'function' => 'int()',
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
