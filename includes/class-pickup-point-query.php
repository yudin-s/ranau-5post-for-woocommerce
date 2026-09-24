<?php

namespace Ranau\FivePost;

defined('ABSPATH') || exit;

final class PickupPointQuery
{
    public function filter_by_bounds_and_package(array $points, float $ne_lat, float $ne_lng, float $sw_lat, float $sw_lng, Package $package): array
    {
        $north = max($ne_lat, $sw_lat);
        $south = min($ne_lat, $sw_lat);
        $east = max($ne_lng, $sw_lng);
        $west = min($ne_lng, $sw_lng);

        $result = array_values(array_filter($points, function (array $point) use ($north, $south, $east, $west, $package): bool {
            return $this->is_inside_bounds($point, $north, $east, $south, $west)
                && $this->supports_package($point, $package);
        }));

        usort($result, static function (array $a, array $b): int {
            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        return $result;
    }

    public function is_inside_bounds(array $point, float $north, float $east, float $south, float $west): bool
    {
        $lat = isset($point['latitude']) ? (float) $point['latitude'] : 0.0;
        $lng = isset($point['longitude']) ? (float) $point['longitude'] : 0.0;

        return $lat <= $north && $lat >= $south && $lng <= $east && $lng >= $west;
    }

    public function supports_package(array $point, Package $package): bool
    {
        $max_weight = (float) ($point['max_weight_kg'] ?? 0);
        if ($max_weight > 0 && $package->weight_kg() > $max_weight) {
            return false;
        }

        $limits = array(
            (float) ($point['max_length_cm'] ?? 0),
            (float) ($point['max_width_cm'] ?? 0),
            (float) ($point['max_height_cm'] ?? 0),
        );

        if (max($limits) <= 0) {
            return true;
        }

        rsort($limits, SORT_NUMERIC);
        $dimensions = $package->dimensions_desc();

        return $dimensions[0] <= $limits[0]
            && $dimensions[1] <= $limits[1]
            && $dimensions[2] <= $limits[2];
    }
}
