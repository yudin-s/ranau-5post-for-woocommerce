<?php

namespace Ranau\FivePost;

defined('ABSPATH') || exit;

final class DeliveryEstimateService
{
    public function estimate(array $point, Package $package, array $options = array()): array
    {
        $rates = $this->extract_rates($point);
        $selected_rate = $this->select_rate($rates, $package, $options);
        if (!$selected_rate) {
            return array(
                'price' => null,
                'days' => $this->delivery_days($point, $options),
                'label' => __('Стоимость 5Post не рассчитана', 'ranau-5post-for-woocommerce'),
            );
        }

        $days = $this->delivery_days($point, $options);
        /* translators: %s: formatted delivery price. */
        $delivery_label = sprintf(
            /* translators: %s: formatted delivery price. */
            __('Доставка: %s', 'ranau-5post-for-woocommerce'),
            $this->format_price((float) $selected_rate['price'])
        );
        $label_parts = array($delivery_label);

        if ($days !== null) {
            /* translators: %d: delivery time in days. */
            $label_parts[] = sprintf(__('срок: %d дн.', 'ranau-5post-for-woocommerce'), $days);
        }

        return array(
            'price' => (float) $selected_rate['price'],
            'days' => $days,
            'label' => implode(', ', $label_parts),
            'tariff_type' => (string) ($selected_rate['rate']['rateType'] ?? ''),
        );
    }

    public function lowest_estimate(array $points, Package $package, array $options = array()): array
    {
        $best = null;
        foreach ($points as $point) {
            $estimate = $this->estimate($point, $package, $options);
            if ($estimate['price'] === null) {
                continue;
            }

            if ($best === null || (float) $estimate['price'] < (float) $best['price']) {
                $best = $estimate;
            }
        }

        return $best ?: array(
            'price' => null,
            'days' => null,
            'label' => __('Стоимость 5Post не рассчитана', 'ranau-5post-for-woocommerce'),
        );
    }

    private function select_rate(array $rates, Package $package, array $options): ?array
    {
        $tariff_type = trim((string) ($options['tariff_type'] ?? 'auto'));
        $is_auto = $tariff_type === '' || strtolower($tariff_type) === 'auto';
        $best = null;

        foreach ($rates as $rate) {
            if (!is_array($rate)) {
                continue;
            }

            if (!$is_auto && (string) ($rate['rateType'] ?? '') !== $tariff_type) {
                continue;
            }

            $price = $this->calculate_rate_price($rate, $package, $options);
            if ($price === null) {
                continue;
            }

            if ($best === null || $price < (float) $best['price']) {
                $best = array(
                    'price' => $price,
                    'rate' => $rate,
                );
            }
        }

        return $best;
    }

    private function calculate_rate_price(array $rate, Package $package, array $options): ?float
    {
        $rate_value = (float) ($rate['rateValue'] ?? 0);
        $rate_extra_value = (float) ($rate['rateExtraValue'] ?? 0);

        if (
            (float) ($rate['vat'] ?? 0) > 0
            && (float) ($rate['rateValueWithVat'] ?? 0) > 0
            && (float) ($rate['rateExtraValueWithVat'] ?? 0) > 0
        ) {
            $rate_value = (float) $rate['rateValueWithVat'];
            $rate_extra_value = (float) $rate['rateExtraValueWithVat'];
        }

        if (!($rate_value > 0 && $rate_extra_value > 0)) {
            return null;
        }

        $total_weight_kg = (int) ceil($package->weight_kg());
        $base_weight_kg = max(0.0, (float) ($options['base_rate_kg'] ?? 0));
        $overweight_step_kg = max(0.0, (float) ($options['overweight_step_kg'] ?? 0));
        $extra_weight_kg = $total_weight_kg - $base_weight_kg;

        if ($extra_weight_kg > 0 && $overweight_step_kg > 0) {
            $price = $rate_value + ceil($extra_weight_kg / $overweight_step_kg) * $rate_extra_value;
        } else {
            $price = $rate_value;
        }

        $markup = (float) ($options['markup'] ?? 0);
        $markup_type = (string) ($options['markup_type'] ?? 'fixed');
        if ($markup_type === 'percent') {
            $price += $price * $markup / 100;
        } else {
            $price += $markup;
        }

        return max(0.0, (float) $price);
    }

    private function delivery_days(array $point, array $options): ?int
    {
        $days = $point['_delivery_days'] ?? null;
        if ($days === null && isset($point['delivery_days'])) {
            $days = $point['delivery_days'];
        }

        if ($days === null) {
            $days = $this->delivery_days_from_raw($point['raw_data'] ?? null);
        }

        if ($days === null) {
            return null;
        }

        return max(0, (int) $days + max(0, (int) ($options['handling_days'] ?? 0)));
    }

    private function extract_rates(array $point): array
    {
        if (!empty($point['_rates']) && is_array($point['_rates'])) {
            return $point['_rates'];
        }

        if (!empty($point['rates']) && is_array($point['rates'])) {
            return $point['rates'];
        }

        if (!empty($point['rate']) && is_array($point['rate'])) {
            return $point['rate'];
        }

        $raw = $this->decode_raw($point['raw_data'] ?? null);
        return !empty($raw['rate']) && is_array($raw['rate']) ? $raw['rate'] : array();
    }

    private function delivery_days_from_raw($raw_data): ?int
    {
        $raw = $this->decode_raw($raw_data);
        $delivery_sl = $raw['deliverySL'][0] ?? array();
        if (!is_array($delivery_sl)) {
            return null;
        }

        $days = $delivery_sl['sl'] ?? $delivery_sl['Sl'] ?? null;
        return $days !== null ? (int) $days : null;
    }

    private function decode_raw($raw_data): array
    {
        if (is_array($raw_data)) {
            return $raw_data;
        }

        if (!is_string($raw_data) || $raw_data === '') {
            return array();
        }

        $decoded = json_decode($raw_data, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function format_price(float $price): string
    {
        if (function_exists('wc_price')) {
            return trim(str_replace("\xc2\xa0", ' ', html_entity_decode(wp_strip_all_tags(wc_price($price)), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8')));
        }

        return number_format($price, 2, '.', ' ') . ' RUB';
    }
}
