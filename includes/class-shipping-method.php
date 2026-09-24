<?php

namespace Ranau\FivePost;

if (!defined('ABSPATH')) {
    exit;
}

final class ShippingMethod extends \WC_Shipping_Method
{
    public function __construct($instance_id = 0)
    {
        $this->id = 'ranau_fivepost_pickup';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Пункт выдачи 5Post', 'ranau-5post-for-woocommerce');
        $this->method_description = __('Доставка в выбранный пункт выдачи 5Post.', 'ranau-5post-for-woocommerce');
        $this->supports = array('shipping-zones', 'instance-settings');

        $this->init();
    }

    public function init(): void
    {
        $this->instance_form_fields = array(
            'title' => array(
                'title' => __('Название', 'ranau-5post-for-woocommerce'),
                'type' => 'text',
                'default' => __('Пункт выдачи 5Post', 'ranau-5post-for-woocommerce'),
            ),
            'cost' => array(
                'title' => __('Стоимость по умолчанию', 'ranau-5post-for-woocommerce'),
                'type' => 'price',
                'default' => '0',
                'description' => __('Используется, если тарифы 5Post для выбранного ПВЗ недоступны.', 'ranau-5post-for-woocommerce'),
            ),
            'free_shipping_threshold' => array(
                'title' => __('Бесплатная доставка от', 'ranau-5post-for-woocommerce'),
                'type' => 'price',
                'default' => (string) FreeShippingPolicy::DEFAULT_THRESHOLD,
                'description' => __('Порог считается по сумме товаров до применения купонов и других скидок. Укажите 0, чтобы отключить.', 'ranau-5post-for-woocommerce'),
                'desc_tip' => true,
            ),
            'base_rate_kg' => array(
                'title' => __('Базовый вес, кг', 'ranau-5post-for-woocommerce'),
                'type' => 'text',
                'default' => '',
                'description' => __('Максимальный вес заказа, для которого применяется базовая ставка из договора с 5Post.', 'ranau-5post-for-woocommerce'),
            ),
            'overweight_step_kg' => array(
                'title' => __('Шаг перевеса, кг', 'ranau-5post-for-woocommerce'),
                'type' => 'text',
                'default' => '',
                'description' => __('Шаг веса, за который добавляется ставка перевеса из договора с 5Post.', 'ranau-5post-for-woocommerce'),
            ),
            'handling_days' => array(
                'title' => __('Дополнительные дни', 'ranau-5post-for-woocommerce'),
                'type' => 'number',
                'default' => '0',
                'description' => __('Добавляются к сроку доставки 5Post для учета сборки и передачи заказа.', 'ranau-5post-for-woocommerce'),
            ),
            'markup' => array(
                'title' => __('Наценка', 'ranau-5post-for-woocommerce'),
                'type' => 'text',
                'default' => '0',
                'description' => __('Фиксированная сумма или процент к рассчитанной стоимости доставки.', 'ranau-5post-for-woocommerce'),
            ),
            'markup_type' => array(
                'title' => __('Тип наценки', 'ranau-5post-for-woocommerce'),
                'type' => 'select',
                'default' => 'fixed',
                'options' => array(
                    'fixed' => __('Фиксированная', 'ranau-5post-for-woocommerce'),
                    'percent' => __('Процент', 'ranau-5post-for-woocommerce'),
                ),
            ),
            'tariff_type' => array(
                'title' => __('Тип тарифа', 'ranau-5post-for-woocommerce'),
                'type' => 'text',
                'default' => 'auto',
                'description' => __('Оставьте auto для выбора минимальной ставки. Для ручного выбора укажите точное значение rateType из тарифов 5Post.', 'ranau-5post-for-woocommerce'),
            ),
            'default_item_weight_kg' => array(
                'title' => __('Вес товара по умолчанию, кг', 'ranau-5post-for-woocommerce'),
                'type' => 'text',
                'default' => '1',
                'description' => __('Используется для товаров без заполненного веса.', 'ranau-5post-for-woocommerce'),
            ),
            'default_item_length_cm' => array(
                'title' => __('Длина товара по умолчанию, см', 'ranau-5post-for-woocommerce'),
                'type' => 'text',
                'default' => '20',
                'description' => __('Используется для товаров без заполненной длины.', 'ranau-5post-for-woocommerce'),
            ),
            'default_item_width_cm' => array(
                'title' => __('Ширина товара по умолчанию, см', 'ranau-5post-for-woocommerce'),
                'type' => 'text',
                'default' => '20',
                'description' => __('Используется для товаров без заполненной ширины.', 'ranau-5post-for-woocommerce'),
            ),
            'default_item_height_cm' => array(
                'title' => __('Высота товара по умолчанию, см', 'ranau-5post-for-woocommerce'),
                'type' => 'text',
                'default' => '10',
                'description' => __('Используется для товаров без заполненной высоты.', 'ranau-5post-for-woocommerce'),
            ),
        );
        $this->title = $this->get_option('title', __('Пункт выдачи 5Post', 'ranau-5post-for-woocommerce'));

        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function calculate_shipping($package = array()): void
    {
        $cart_package = $this->resolve_package($package);
        $repository = $this->repository();
        $estimate_service = new DeliveryEstimateService();
        $options = $this->estimate_options();
        $selected_point_id = $this->selected_point_id();
        $estimate = null;

        if ($selected_point_id !== '') {
            $point = $repository->find($selected_point_id);
            if ($point) {
                $estimate = $estimate_service->estimate($point, $cart_package, $options);
            }
        }

        if (!$estimate || $estimate['price'] === null) {
            $points = $repository->find_for_destination_and_package((array) ($package['destination'] ?? array()), $cart_package, 500);
            $estimate = $estimate_service->lowest_estimate($points, $cart_package, $options);
        }

        $policy = new FreeShippingPolicy($this->id);
        $threshold = $policy->threshold($this->instance_id);
        $estimate = $policy->apply_to_estimate((array) $estimate, (array) $package, $threshold);
        $cost = $estimate['price'] ?? null;
        if ($cost === null) {
            $cost = (float) $this->get_option('cost', '0');
        }

        $label = $this->title;
        if (!empty($estimate['days'])) {
            /* translators: %d: delivery time in days. */
            $label .= sprintf(__(' (%d дн.)', 'ranau-5post-for-woocommerce'), (int) $estimate['days']);
        }

        $this->add_rate(array(
            'id' => $this->id,
            'label' => $label,
            'cost' => (float) $cost,
            'package' => $package,
            'meta_data' => array(
                'ranau_fivepost_estimate' => $estimate,
                'ranau_fivepost_point_id' => $selected_point_id,
                'ranau_fivepost_free_shipping' => !empty($estimate['free_shipping']) ? 'yes' : 'no',
                'ranau_fivepost_free_shipping_threshold' => $threshold,
                'ranau_fivepost_subtotal_before_discounts' => $policy->subtotal_before_discounts((array) $package),
            ),
        ));
    }

    private function repository(): PickupPointRepository
    {
        global $wpdb;
        return new PickupPointRepository($wpdb);
    }

    private function estimate_options(): array
    {
        $global = $this->global_settings();

        return array(
            'base_rate_kg' => $this->decimal_setting('base_rate_kg', $global),
            'overweight_step_kg' => $this->decimal_setting('overweight_step_kg', $global),
            'handling_days' => (int) ($global['handling_days'] ?? $this->get_option('handling_days', '0')),
            'markup' => $this->decimal_setting('markup', $global),
            'markup_type' => (string) ($global['markup_type'] ?? $this->get_option('markup_type', 'fixed')),
            'tariff_type' => (string) ($global['tariff_type'] ?? $this->get_option('tariff_type', 'auto')),
        );
    }

    private function decimal_option(string $key): float
    {
        return (float) str_replace(',', '.', (string) $this->get_option($key, '0'));
    }

    private function decimal_setting(string $key, array $global): float
    {
        $value = (string) ($global[$key] ?? '');
        if ($value === '') {
            return $this->decimal_option($key);
        }

        return (float) str_replace(',', '.', $value);
    }

    private function global_settings(): array
    {
        return (new Settings())->all();
    }

    private function selected_point_id(): string
    {
        if (function_exists('WC') && WC()->session) {
            return sanitize_text_field((string) WC()->session->get('ranau_fivepost_point_id', ''));
        }

        return '';
    }

    private function resolve_package(array $package): Package
    {
        $global = $this->global_settings();

        return (new PackageContentResolver())->resolve((array) ($package['contents'] ?? array()), array(
            'weight_kg' => $this->decimal_setting('default_item_weight_kg', $global),
            'length_cm' => $this->decimal_setting('default_item_length_cm', $global),
            'width_cm' => $this->decimal_setting('default_item_width_cm', $global),
            'height_cm' => $this->decimal_setting('default_item_height_cm', $global),
        ));
    }
}
