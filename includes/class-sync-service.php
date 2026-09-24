<?php

namespace Ranau\FivePost;

if (!defined('ABSPATH')) {
    exit;
}

final class SyncService
{
    private const STATE_OPTION = 'ranau_fivepost_pickup_sync_state';
    private const ACTION_HOOK = 'ranau_fivepost_pickup_sync_batch';
    private const ACTION_GROUP = 'ranau-5post-for-woocommerce';
    private const DEFAULT_PAGE_SIZE = 100;
    private const DEFAULT_DELAY_SECONDS = 10;

    private FivePostClient $client;
    private PickupPointRepository $repository;

    public function __construct(FivePostClient $client, PickupPointRepository $repository)
    {
        $this->client = $client;
        $this->repository = $repository;
    }

    public function sync()
    {
        $points = $this->client->fetch_pickup_points();
        if (is_wp_error($points)) {
            return $points;
        }

        $sync_token = $this->new_sync_token();
        $count = 0;
        foreach ($points as $point) {
            if (is_array($point) && $this->repository->upsert($point, $sync_token)) {
                $count++;
            }
        }
        $deleted_count = $this->repository->delete_not_seen_in_sync($sync_token);
        update_option('ranau_fivepost_pickup_last_sync', array(
            'count' => $count,
            'deleted_count' => $deleted_count,
            'time' => current_time('mysql'),
        ), false);

        return $count;
    }

    public function sync_batch(int $page_size = 100, bool $restart = false)
    {
        $page_size = max(1, min(250, $page_size));
        $state = $restart ? $this->initial_state($page_size) : $this->get_state($page_size);

        if ($restart) {
            $state['status'] = 'running';
            $state['current_page'] = 0;
            $state['synced_count'] = 0;
            $state['deleted_count'] = 0;
            $state['sync_token'] = $this->new_sync_token();
            $state['started_at'] = current_time('mysql');
            $this->save_state($state);
        }

        if ($state['status'] === 'complete') {
            return $state;
        }

        $page = (int) $state['current_page'];
        $result = $this->client->fetch_pickup_points_page($page, $page_size);
        if (is_wp_error($result)) {
            $state['status'] = $result->get_error_code() === 'ranau_fivepost_rate_limited' ? 'rate_limited' : 'error';
            $state['error_code'] = $result->get_error_code();
            $state['error_message'] = $result->get_error_message();
            $state['updated_at'] = current_time('mysql');
            $this->save_state($state);
            return $result;
        }

        foreach ($result['points'] as $point) {
            if (is_array($point)) {
                $this->repository->upsert($point, (string) $state['sync_token']);
                $state['synced_count']++;
            }
        }

        $state['status'] = $result['is_last'] ? 'complete' : 'running';
        $state['current_page'] = $page + 1;
        $state['page_size'] = $page_size;
        $state['total_pages'] = $result['total_pages'];
        $state['total_elements'] = $result['total_elements'];
        $state['updated_at'] = current_time('mysql');
        unset($state['error_code'], $state['error_message']);

        if ($state['status'] === 'complete') {
            $state['deleted_count'] = $this->repository->delete_not_seen_in_sync((string) $state['sync_token']);
            update_option('ranau_fivepost_pickup_last_sync', array(
                'count' => $state['synced_count'],
                'deleted_count' => $state['deleted_count'],
                'time' => current_time('mysql'),
            ), false);
        }

        $this->save_state($state);
        return $state;
    }

    public function start_background(int $page_size = self::DEFAULT_PAGE_SIZE, bool $restart = false): array
    {
        $page_size = max(1, min(250, $page_size));
        $state = $restart ? $this->initial_state($page_size) : $this->get_state($page_size);
        $state['status'] = 'queued';
        $state['page_size'] = $page_size;
        if ($restart) {
            $state['current_page'] = 0;
            $state['synced_count'] = 0;
            $state['deleted_count'] = 0;
            $state['sync_token'] = $this->new_sync_token();
            $state['total_pages'] = null;
            $state['total_elements'] = null;
            $state['started_at'] = current_time('mysql');
            unset($state['error_code'], $state['error_message']);
        }
        $state['updated_at'] = current_time('mysql');
        $this->save_state($state);
        $this->schedule_next_batch(1);

        return $state;
    }

    public function process_background_batch(): void
    {
        $state = $this->get_state(self::DEFAULT_PAGE_SIZE);
        if (in_array($state['status'], array('complete'), true)) {
            return;
        }

        $result = $this->sync_batch((int) $state['page_size'], false);
        if (is_wp_error($result)) {
            $current = $this->get_state((int) $state['page_size']);
            if (($current['status'] ?? '') === 'rate_limited') {
                $this->schedule_next_batch(HOUR_IN_SECONDS, true);
            }
            return;
        }

        if (($result['status'] ?? '') === 'running') {
            $this->schedule_next_batch(self::DEFAULT_DELAY_SECONDS, true);
        }
    }

    public function status(): array
    {
        $state = $this->get_state(self::DEFAULT_PAGE_SIZE);
        $total_pages = (int) ($state['total_pages'] ?? 0);
        $current_page = (int) ($state['current_page'] ?? 0);
        $state['progress_percent'] = $total_pages > 0 ? min(100, round(($current_page / $total_pages) * 100, 2)) : 0;
        $state['scheduled'] = $this->has_scheduled_batch();

        return $state;
    }

    private function get_state(int $page_size): array
    {
        $state = get_option(self::STATE_OPTION, array());
        if (!is_array($state) || empty($state)) {
            return $this->initial_state($page_size);
        }

        return array_merge($this->initial_state($page_size), $state);
    }

    private function initial_state(int $page_size): array
    {
        return array(
            'status' => 'running',
            'current_page' => 0,
            'page_size' => $page_size,
            'total_pages' => null,
            'total_elements' => null,
            'synced_count' => 0,
            'deleted_count' => 0,
            'sync_token' => $this->new_sync_token(),
            'started_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );
    }

    private function save_state(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    private function new_sync_token(): string
    {
        return gmdate('YmdHis') . '-' . wp_generate_uuid4();
    }

    private function schedule_next_batch(int $delay_seconds, bool $force = false): void
    {
        if (!$force && $this->has_scheduled_batch()) {
            return;
        }

        $timestamp = time() + max(1, $delay_seconds);
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, self::ACTION_HOOK, array(), self::ACTION_GROUP);
            return;
        }

        wp_schedule_single_event($timestamp, self::ACTION_HOOK);
    }

    private function has_scheduled_batch(): bool
    {
        if (function_exists('as_next_scheduled_action')) {
            return (bool) as_next_scheduled_action(self::ACTION_HOOK, array(), self::ACTION_GROUP);
        }

        return (bool) wp_next_scheduled(self::ACTION_HOOK);
    }
}
