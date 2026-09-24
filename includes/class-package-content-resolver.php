<?php

namespace Ranau\FivePost;

defined('ABSPATH') || exit;

final class PackageContentResolver
{
    public function resolve(array $contents, array $defaults = array()): Package
    {
        $weight = 0.0;
        $length = 0.0;
        $width = 0.0;
        $height = 0.0;

        foreach ($contents as $item) {
            $product = $item['data'] ?? null;
            if (!$product || !is_object($product)) {
                continue;
            }

            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $item_weight = $this->product_weight_kg($product, (float) ($defaults['weight_kg'] ?? 0));
            $item_length = $this->product_dimension_cm($product, 'get_length', (float) ($defaults['length_cm'] ?? 0));
            $item_width = $this->product_dimension_cm($product, 'get_width', (float) ($defaults['width_cm'] ?? 0));
            $item_height = $this->product_dimension_cm($product, 'get_height', (float) ($defaults['height_cm'] ?? 0));

            $weight += $item_weight * $qty;
            $length = max($length, $item_length);
            $width = max($width, $item_width);
            $height += $item_height * $qty;
        }

        return new Package($weight, $length, $width, $height);
    }

    private function product_weight_kg(object $product, float $default): float
    {
        $raw = method_exists($product, 'get_weight') ? (float) $product->get_weight() : 0.0;
        if ($raw <= 0) {
            return max(0.0, $default);
        }

        return function_exists('wc_get_weight') ? (float) wc_get_weight($raw, 'kg') : $raw;
    }

    private function product_dimension_cm(object $product, string $getter, float $default): float
    {
        $raw = method_exists($product, $getter) ? (float) $product->{$getter}() : 0.0;
        if ($raw <= 0) {
            return max(0.0, $default);
        }

        return function_exists('wc_get_dimension') ? (float) wc_get_dimension($raw, 'cm') : $raw;
    }
}
