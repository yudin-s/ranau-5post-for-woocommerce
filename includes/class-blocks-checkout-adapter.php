<?php

namespace Ranau\FivePost;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use DomainException;

if (!defined('ABSPATH')) {
    exit;
}

final class BlocksCheckoutAdapter
{
    private Settings $settings;
    private Assets $assets;
    private bool $store_api_registered = false;
    private bool $store_api_update_registered = false;

    public function __construct(Settings $settings, Assets $assets)
    {
        $this->settings = $settings;
        $this->assets = $assets;
    }

    public function init(): void
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_for_blocks_checkout'));
        add_action('woocommerce_blocks_loaded', array($this, 'register_store_api_extension'));
        add_action('woocommerce_blocks_loaded', array($this, 'register_store_api_update_callback'));
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'validate_blocks_checkout'), 5, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'save_blocks_order_meta'), 10, 2);

        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            $this->register_store_api_extension();
        }
        if (function_exists('woocommerce_store_api_register_update_callback')) {
            $this->register_store_api_update_callback();
        }
    }

    public function register_store_api_extension(): void
    {
        if ($this->store_api_registered || !function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data(array(
            'endpoint' => 'checkout',
            'namespace' => 'ranau-fivepost',
            'schema_callback' => function (): array {
                $field = array(
                    'description' => __('Данные выбранного пункта выдачи 5Post.', 'ranau-5post-for-woocommerce'),
                    'type' => 'string',
                    'context' => array('view', 'edit'),
                    'required' => false,
                );

                return array(
                    'point_id' => $field,
                    'point_name' => $field,
                    'point_address' => $field,
                    'point_region' => $field,
                    'point_city' => $field,
                    'point_postcode' => $field,
                    'point_country' => $field,
                    'point_latitude' => $field,
                    'point_longitude' => $field,
                );
            },
        ));

        $this->store_api_registered = true;
    }

    public function register_store_api_update_callback(): void
    {
        if ($this->store_api_update_registered || !function_exists('woocommerce_store_api_register_update_callback')) {
            return;
        }

        woocommerce_store_api_register_update_callback(array(
            'namespace' => 'ranau-fivepost',
            'callback' => array($this, 'update_cart_from_extension'),
        ));

        $this->store_api_update_registered = true;
    }

    public function update_cart_from_extension($data): void
    {
        $data = $this->normalize_request_data($data);
        $state = new DeliveryState();
        if (empty($data['commit_token'])) {
            $state->clear();
            return;
        }
        try {
            $state->commit($data);
        } catch (DomainException $exception) {
            $this->throw_store_api_error(
                sanitize_key($exception->getMessage()),
                __('Выбор 5Post устарел. Выберите пункт выдачи ещё раз.', 'ranau-5post-for-woocommerce')
            );
        }
    }

    public function enqueue_for_blocks_checkout(): void
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $this->assets->enqueue();
    }

    public function validate_blocks_checkout(\WC_Order $order, \WP_REST_Request $request): void
    {
        if ($this->is_store_api_totals_calculation($request)) {
            return;
        }

        if (!$this->is_fivepost_shipping_selected()) {
            return;
        }

        if (!(new DeliveryState())->committed()) {
            $this->throw_store_api_error(
                'ranau_fivepost_point_required',
                __('Пожалуйста, выберите пункт выдачи 5Post.', 'ranau-5post-for-woocommerce')
            );
        }
    }

    public function save_blocks_order_meta(\WC_Order $order, \WP_REST_Request $request): void
    {
        $committed = (new DeliveryState())->committed();
        if (!$committed) {
            return;
        }
        $data = (array) ($committed['selection'] ?? array());
        $quote = (array) ($committed['quote'] ?? array());
        $point_id = sanitize_text_field((string) ($data['id'] ?? ''));

        $order->update_meta_data('_ranau_fivepost_point_id', $point_id);
        foreach (array('name', 'address', 'region', 'city', 'postcode', 'country') as $key) {
            $order->update_meta_data('_ranau_fivepost_point_' . $key, sanitize_text_field((string) ($data[$key] ?? '')));
        }
        $order->update_meta_data('_ranau_fivepost_customer_price', (float) ($quote['price'] ?? 0));
        $order->update_meta_data('_ranau_delivery_provider', 'fivepost');
        $order->update_meta_data('_ranau_delivery_fulfillment_mode', 'external');

        $order->set_shipping_address_1(sanitize_text_field((string) ($data['address'] ?? '')));
        $order->set_shipping_city(sanitize_text_field((string) ($data['city'] ?? '')));
        $order->set_shipping_state(sanitize_text_field((string) ($data['region'] ?? '')));
        $order->set_shipping_postcode(sanitize_text_field((string) ($data['postcode'] ?? '')));
        if (!empty($data['country'])) {
            $order->set_shipping_country(sanitize_text_field((string) $data['country']));
        }
    }

    private function normalize_request_data($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(wp_json_encode($value), true) ?: array();
        }

        return array();
    }

    private function is_store_api_totals_calculation(\WP_REST_Request $request): bool
    {
        $value = $request->get_param('__experimental_calc_totals');
        return in_array($value, array(true, 'true', 1, '1'), true);
    }

    private function is_fivepost_shipping_selected(): bool
    {
        if (!function_exists('WC') || !WC()->session) {
            return false;
        }

        foreach ((array) WC()->session->get('chosen_shipping_methods', array()) as $method_id) {
            if (strpos((string) $method_id, 'ranau_fivepost_pickup') === 0) {
                return true;
            }
        }

        return false;
    }

    private function throw_store_api_error(string $code, string $message): void
    {
        if (class_exists(RouteException::class)) {
            throw new RouteException(esc_attr($code), esc_html($message), 400);
        }

        throw new \RuntimeException(esc_html($message));
    }

    private function clear_shipping_rate_cache(): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        $packages = WC()->cart ? WC()->cart->get_shipping_packages() : array();
        foreach (array_keys((array) $packages) as $index) {
            WC()->session->__unset('shipping_for_package_' . $index);
        }
    }
}
