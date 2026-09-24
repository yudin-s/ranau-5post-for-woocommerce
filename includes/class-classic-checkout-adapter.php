<?php

namespace Ranau\FivePost;

if (!defined('ABSPATH')) {
    exit;
}

final class ClassicCheckoutAdapter
{
    private Settings $settings;
    private Assets $assets;

    public function __construct(Settings $settings, Assets $assets)
    {
        $this->settings = $settings;
        $this->assets = $assets;
    }

    public function init(): void
    {
        add_action('woocommerce_after_shipping_rate', array($this, 'render_after_shipping_rate'), 10, 2);
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_checkout'), 10, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_order_meta'), 10, 2);
    }

    public function render_after_shipping_rate($method, $index): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || $method->get_id() !== 'ranau_fivepost_pickup') {
            return;
        }

        $this->assets->enqueue();
        $fields = array(
            'point_id',
            'point_name',
            'point_address',
            'point_region',
            'point_city',
            'point_postcode',
            'point_country',
            'point_latitude',
            'point_longitude',
        );
        echo '<div class="ranau-fivepost-selector" data-ranau-fivepost-classic data-ranau-delivery-method="ranau_fivepost_pickup" data-ranau-delivery-complete="0">';
        echo '<button type="button" class="button ranau-fivepost-open-map">' . esc_html__('Выбрать пункт выдачи', 'ranau-5post-for-woocommerce') . '</button>';
        echo '<div class="ranau-fivepost-selected" aria-live="polite"></div>';
        foreach ($fields as $field) {
            echo '<input type="hidden" name="ranau_fivepost_' . esc_attr($field) . '" id="ranau_fivepost_' . esc_attr($field) . '" value="">';
        }
        echo '</div>';
    }

    public function validate_checkout($data, $errors): void
    {
        $chosen = WC()->session ? (array) WC()->session->get('chosen_shipping_methods', array()) : array();
        $selected = array_filter($chosen, static function ($method): bool {
            return strpos((string) $method, 'ranau_fivepost_pickup') === 0;
        });
        if (!$selected) {
            return;
        }

        if (!(new DeliveryState())->committed()) {
            $errors->add('ranau_fivepost_point_required', __('Пожалуйста, выберите пункт выдачи 5Post.', 'ranau-5post-for-woocommerce'));
        }
    }

    public function save_order_meta(\WC_Order $order, array $data): void
    {
        $committed = (new DeliveryState())->committed();
        if (!$committed) {
            return;
        }
        $point = (array) ($committed['selection'] ?? array());
        $quote = (array) ($committed['quote'] ?? array());
        foreach (array('id', 'name', 'address', 'region', 'city', 'postcode', 'country') as $key) {
            $order->update_meta_data('_ranau_fivepost_point_' . $key, sanitize_text_field((string) ($point[$key] ?? '')));
        }
        $order->update_meta_data('_ranau_fivepost_customer_price', (float) ($quote['price'] ?? 0));
        $order->update_meta_data('_ranau_delivery_provider', 'fivepost');
        $order->update_meta_data('_ranau_delivery_fulfillment_mode', 'external');

        $order->set_shipping_address_1(sanitize_text_field((string) ($point['address'] ?? '')));
        $order->set_shipping_city(sanitize_text_field((string) ($point['city'] ?? '')));
        $order->set_shipping_state(sanitize_text_field((string) ($point['region'] ?? '')));
        $order->set_shipping_postcode(sanitize_text_field((string) ($point['postcode'] ?? '')));
        if (!empty($point['country'])) {
            $order->set_shipping_country(sanitize_text_field((string) $point['country']));
        }
    }
}
