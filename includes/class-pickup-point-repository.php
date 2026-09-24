<?php

namespace Ranau\FivePost;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- This repository uses the WordPress 6.2+ %i identifier placeholder for its dedicated table.

final class PickupPointRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
        $this->table = Installer::pickup_points_table();
    }

    public function replace_all(array $points): int
    {
        $this->wpdb->query('START TRANSACTION');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table identifier is escaped with the %i placeholder.
        $this->wpdb->query($this->wpdb->prepare('TRUNCATE TABLE %i', $this->table));

        $count = 0;
        foreach ($points as $point) {
            if ($this->upsert($point)) {
                $count++;
            }
        }

        $this->wpdb->query('COMMIT');
        return $count;
    }

    public function upsert(array $point, string $sync_token = ''): bool
    {
        $data = $this->normalize_for_db($point, $sync_token);
        $result = $this->wpdb->replace($this->table, $data, array(
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f',
            '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%d', '%d', '%d',
            '%s', '%s', '%s', '%s', '%s',
        ));

        return $result !== false;
    }

    public function delete_not_seen_in_sync(string $sync_token): int
    {
        if ($sync_token === '') {
            return 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values and table identifier use placeholders.
        return (int) $this->wpdb->query(
            $this->wpdb->prepare('DELETE FROM %i WHERE sync_token <> %s', $this->table, $sync_token)
        );
    }

    public function find_by_bounds(float $north, float $east, float $south, float $west, int $limit = 1000): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values and table identifier use placeholders.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM %i
             WHERE latitude <= %f AND latitude >= %f
               AND longitude <= %f AND longitude >= %f
             ORDER BY external_id ASC
             LIMIT %d",
            $this->table,
            max($north, $south),
            min($north, $south),
            max($east, $west),
            min($east, $west),
            $limit
        ), ARRAY_A);

        return array_map(array($this, 'row_to_point'), $rows);
    }

    public function find(string $external_id): ?array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values and table identifier use placeholders.
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare('SELECT * FROM %i WHERE external_id = %s', $this->table, $external_id),
            ARRAY_A
        );

        return $row ? $this->row_to_point($row) : null;
    }

    public function find_for_destination_and_package(array $destination, Package $package, int $limit = 500): array
    {
        $dimensions = $package->dimensions_desc();
        $postcode = trim((string) ($destination['postcode'] ?? ''));
        $city = trim((string) ($destination['city'] ?? ''));
        if ($postcode !== '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values and table identifier use placeholders.
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                'SELECT * FROM %i WHERE (max_weight_kg = 0 OR max_weight_kg >= %f) AND (max_length_cm = 0 OR max_length_cm >= %f) AND (max_width_cm = 0 OR max_width_cm >= %f) AND (max_height_cm = 0 OR max_height_cm >= %f) AND postcode = %s ORDER BY external_id ASC LIMIT %d',
                $this->table,
                $package->weight_kg(),
                $dimensions[0],
                $dimensions[1],
                $dimensions[2],
                $postcode,
                $limit
            ), ARRAY_A);
        } elseif ($city !== '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values and table identifier use placeholders.
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                'SELECT * FROM %i WHERE (max_weight_kg = 0 OR max_weight_kg >= %f) AND (max_length_cm = 0 OR max_length_cm >= %f) AND (max_width_cm = 0 OR max_width_cm >= %f) AND (max_height_cm = 0 OR max_height_cm >= %f) AND city = %s ORDER BY external_id ASC LIMIT %d',
                $this->table,
                $package->weight_kg(),
                $dimensions[0],
                $dimensions[1],
                $dimensions[2],
                $city,
                $limit
            ), ARRAY_A);
        } else {
            return array();
        }

        return array_map(array($this, 'row_to_point'), $rows);
    }

    private function normalize_for_db(array $point, string $sync_token): array
    {
        $raw_address = $point['address'] ?? array();
        $cell_limits = $point['cellLimits'] ?? array();
        $dimensions = array(
            (float) ($cell_limits['maxCellLength'] ?? $point['max_length_cm'] ?? 0),
            (float) ($cell_limits['maxCellWidth'] ?? $point['max_width_cm'] ?? 0),
            (float) ($cell_limits['maxCellHeight'] ?? $point['max_height_cm'] ?? 0),
        );
        rsort($dimensions, SORT_NUMERIC);

        return array(
            'external_id' => sanitize_text_field((string) ($point['id'] ?? $point['external_id'] ?? '')),
            'name' => sanitize_text_field((string) ($point['name'] ?? '')),
            'type' => sanitize_text_field((string) ($point['type'] ?? '')),
            'full_address' => sanitize_textarea_field((string) ($point['fullAddress'] ?? $point['full_address'] ?? '')),
            'country' => sanitize_text_field((string) ($raw_address['country'] ?? $point['country'] ?? '')),
            'region' => sanitize_text_field((string) ($raw_address['region'] ?? $point['region'] ?? '')),
            'city' => sanitize_text_field((string) ($raw_address['city'] ?? $point['city'] ?? '')),
            'postcode' => sanitize_text_field((string) ($raw_address['zipCode'] ?? $point['postcode'] ?? '')),
            'latitude' => (float) ($raw_address['lat'] ?? $point['latitude'] ?? 0),
            'longitude' => (float) ($raw_address['lng'] ?? $point['longitude'] ?? 0),
            'work_hours' => wp_json_encode($point['workHours'] ?? $point['work_hours'] ?? array()),
            'additional' => sanitize_textarea_field((string) ($point['additional'] ?? '')),
            'phone' => sanitize_text_field((string) ($point['phone'] ?? '')),
            'max_weight_kg' => ((float) ($cell_limits['maxWeight'] ?? $point['max_weight_kg'] ?? 0)) / 1000,
            'max_length_cm' => $dimensions[0],
            'max_width_cm' => $dimensions[1],
            'max_height_cm' => $dimensions[2],
            'cash_allowed' => !empty($point['cashAllowed']) || !empty($point['cash_allowed']) ? 1 : 0,
            'card_allowed' => !empty($point['cardAllowed']) || !empty($point['card_allowed']) ? 1 : 0,
            'loyalty_allowed' => !empty($point['loyaltyAllowed']) || !empty($point['loyalty_allowed']) ? 1 : 0,
            'status' => sanitize_text_field((string) ($point['extStatus'] ?? $point['status'] ?? '')),
            'raw_data' => wp_json_encode($point),
            'synced_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'sync_token' => sanitize_text_field($sync_token),
        );
    }

    private function row_to_point(array $row): array
    {
        $raw = json_decode((string) ($row['raw_data'] ?? ''), true);
        $raw = is_array($raw) ? $raw : array();

        return array(
            'id' => $row['external_id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'address' => $row['full_address'],
            'country' => $row['country'] ?? '',
            'region' => $row['region'] ?? '',
            'city' => $row['city'],
            'postcode' => $row['postcode'] ?? '',
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
            'work_hours' => json_decode((string) $row['work_hours'], true) ?: array(),
            'additional' => $row['additional'],
            'phone' => $row['phone'],
            'max_weight_kg' => (float) $row['max_weight_kg'],
            'max_length_cm' => (float) $row['max_length_cm'],
            'max_width_cm' => (float) $row['max_width_cm'],
            'max_height_cm' => (float) $row['max_height_cm'],
            'cash_allowed' => (bool) $row['cash_allowed'],
            'card_allowed' => (bool) $row['card_allowed'],
            'loyalty_allowed' => (bool) $row['loyalty_allowed'],
            'status' => $row['status'],
            '_rates' => !empty($raw['rate']) && is_array($raw['rate']) ? $raw['rate'] : array(),
            '_delivery_days' => $this->delivery_days_from_raw($raw),
        );
    }

    private function delivery_days_from_raw(array $raw): ?int
    {
        $delivery_sl = $raw['deliverySL'][0] ?? array();
        if (!is_array($delivery_sl)) {
            return null;
        }

        $days = $delivery_sl['sl'] ?? $delivery_sl['Sl'] ?? null;
        return $days !== null ? (int) $days : null;
    }
}

// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
