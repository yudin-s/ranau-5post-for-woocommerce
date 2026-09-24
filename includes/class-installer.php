<?php

namespace Ranau\FivePost;

if (!defined('ABSPATH')) {
    exit;
}

final class Installer
{
    public static function activate(): void
    {
        global $wpdb;

        $table = self::pickup_points_table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            point_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            external_id varchar(80) NOT NULL,
            name varchar(255) NOT NULL DEFAULT '',
            type varchar(80) NOT NULL DEFAULT '',
            full_address text NOT NULL,
            country varchar(80) NOT NULL DEFAULT '',
            region varchar(120) NOT NULL DEFAULT '',
            city varchar(120) NOT NULL DEFAULT '',
            postcode varchar(20) NOT NULL DEFAULT '',
            latitude decimal(10,7) NOT NULL DEFAULT 0,
            longitude decimal(10,7) NOT NULL DEFAULT 0,
            work_hours longtext NULL,
            additional text NULL,
            phone varchar(80) NOT NULL DEFAULT '',
            max_weight_kg decimal(10,3) NOT NULL DEFAULT 0,
            max_length_cm decimal(10,2) NOT NULL DEFAULT 0,
            max_width_cm decimal(10,2) NOT NULL DEFAULT 0,
            max_height_cm decimal(10,2) NOT NULL DEFAULT 0,
            cash_allowed tinyint(1) NOT NULL DEFAULT 0,
            card_allowed tinyint(1) NOT NULL DEFAULT 0,
            loyalty_allowed tinyint(1) NOT NULL DEFAULT 0,
            status varchar(80) NOT NULL DEFAULT '',
            raw_data longtext NULL,
            synced_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            sync_token varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (point_id),
            UNIQUE KEY external_id (external_id),
            KEY coords (latitude, longitude),
            KEY max_package (max_weight_kg, max_length_cm, max_width_cm, max_height_cm),
            KEY sync_token (sync_token)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        self::maybe_migrate();
    }

    public static function pickup_points_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ranau_fivepost_pickup_points';
    }

    public static function maybe_migrate(): void
    {
        global $wpdb;

        $table = self::pickup_points_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One cheap existence check avoids running dbDelta on every request.
        $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($existing !== $table) {
            self::activate();
        }
    }
}
