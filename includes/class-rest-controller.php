<?php

namespace Ranau\FivePost;

use DomainException;

if (!defined('ABSPATH')) {
    exit;
}

final class RestController
{
    private const PICKUP_POINTS_LIMIT = 100;

    private Settings $settings;
    private PickupPointRepository $repository;
    private CartPackageResolver $package_resolver;
    private PickupPointQuery $query;
    private DeliveryEstimateService $estimate_service;
    private SyncService $sync_service;

    public function __construct(Settings $settings, PickupPointRepository $repository, CartPackageResolver $package_resolver, PickupPointQuery $query, DeliveryEstimateService $estimate_service, SyncService $sync_service)
    {
        $this->settings = $settings;
        $this->repository = $repository;
        $this->package_resolver = $package_resolver;
        $this->query = $query;
        $this->estimate_service = $estimate_service;
        $this->sync_service = $sync_service;
    }

    public function init(): void
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes(): void
    {
        register_rest_route('ranau-fivepost/v1', '/pickup-points', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'pickup_points'),
            'permission_callback' => '__return_true',
            'args' => $this->bounds_args(),
        ));

        register_rest_route('ranau-fivepost/v1', '/validate-point', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'validate_point'),
            'permission_callback' => array($this, 'check_nonce'),
        ));

        register_rest_route('ranau-fivepost/v1', '/selected-point', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'selected_point'),
            'permission_callback' => array($this, 'check_nonce'),
        ));

        register_rest_route('ranau-fivepost/v1', '/commit', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'commit_selection'),
            'permission_callback' => array($this, 'check_nonce'),
        ));

        register_rest_route('ranau-fivepost/v1', '/admin/sync', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'admin_sync'),
            'permission_callback' => function (): bool {
                return current_user_can('manage_woocommerce');
            },
        ));

        register_rest_route('ranau-fivepost/v1', '/admin/sync/status', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'admin_sync_status'),
            'permission_callback' => function (): bool {
                return current_user_can('manage_woocommerce');
            },
        ));
    }

    public function check_nonce(\WP_REST_Request $request): bool
    {
        $nonce = (string) $request->get_header('x_wp_nonce');

        return $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest');
    }

    public function pickup_points(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->ensure_cart_loaded();
        $north = (float) $request->get_param('ne_lat');
        $east = (float) $request->get_param('ne_lng');
        $south = (float) $request->get_param('sw_lat');
        $west = (float) $request->get_param('sw_lng');
        $package = $this->package_resolver->resolve($this->package_defaults());
        $estimate_options = $this->estimate_options();
        $candidate_points = $this->repository->find_by_bounds($north, $east, $south, $west, 500);
        $points = $this->query->filter_by_bounds_and_package($candidate_points, $north, $east, $south, $west, $package);
        $points = array_slice($points, 0, self::PICKUP_POINTS_LIMIT);

        $free_shipping = new FreeShippingPolicy('ranau_fivepost_pickup');
        $points = array_map(function (array $point) use ($package, $estimate_options, $free_shipping): array {
            $point['estimate'] = $free_shipping->apply_to_estimate(
                $this->estimate_service->estimate($point, $package, $estimate_options)
            );
            return $this->public_point($point);
        }, $points);

        return rest_ensure_response(array('points' => $points));
    }

    public function validate_point(\WP_REST_Request $request)
    {
        $this->ensure_cart_loaded();
        $point_id = sanitize_text_field((string) $request->get_param('point_id'));
        $point = $this->repository->find($point_id);
        if (!$point) {
            return new \WP_Error('ranau_fivepost_point_not_found', __('Пункт выдачи не найден.', 'ranau-5post-for-woocommerce'), array('status' => 404));
        }

        if (!$this->query->supports_package($point, $this->package_resolver->resolve($this->package_defaults()))) {
            return new \WP_Error('ranau_fivepost_point_incompatible', __('Пункт выдачи не может принять эту посылку.', 'ranau-5post-for-woocommerce'), array('status' => 400));
        }

        return rest_ensure_response(array('point' => $point));
    }

    public function selected_point(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->ensure_cart_loaded();
        $point_id = sanitize_text_field((string) $request->get_param('point_id'));
        $state = new DeliveryState();
        if ($point_id === '') {
            $state->clear();
            return rest_ensure_response(array('point' => array(), 'quote' => array(), 'commit' => array()));
        }

        $point = $this->repository->find($point_id);
        $package = $this->package_resolver->resolve($this->package_defaults());
        if (!$point || !$this->query->supports_package($point, $package)) {
            return new \WP_REST_Response(array(
                'code' => 'ranau_fivepost_point_unavailable',
                'message' => __('Пункт выдачи недоступен для текущей корзины.', 'ranau-5post-for-woocommerce'),
            ), 409);
        }
        $quote = (new FreeShippingPolicy('ranau_fivepost_pickup'))->apply_to_estimate(
            $this->estimate_service->estimate($point, $package, $this->estimate_options())
        );
        $selection = $this->public_point($point);

        try {
            $commit = $state->begin($selection, $quote);
        } catch (DomainException $exception) {
            return new \WP_REST_Response(array(
                'code' => sanitize_key($exception->getMessage()),
                'message' => __('Сначала выберите способ доставки 5Post.', 'ranau-5post-for-woocommerce'),
            ), 409);
        }

        return rest_ensure_response(array('point' => $selection, 'quote' => $quote, 'commit' => $commit));
    }

    public function commit_selection(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->ensure_cart_loaded();
        try {
            $committed = (new DeliveryState())->commit($this->normalize_array($request->get_json_params()));
        } catch (DomainException $exception) {
            return new \WP_REST_Response(array(
                'code' => sanitize_key($exception->getMessage()),
                'message' => __('Выбор 5Post устарел. Выберите пункт выдачи ещё раз.', 'ranau-5post-for-woocommerce'),
            ), 409);
        }

        return rest_ensure_response(array('committed' => $committed));
    }

    public function admin_sync(\WP_REST_Request $request)
    {
        $restart = rest_sanitize_boolean($request->get_param('restart'));
        $page_size = (int) ($request->get_param('page_size') ?: 100);
        return rest_ensure_response($this->sync_service->start_background($page_size, $restart));
    }

    public function admin_sync_status(): \WP_REST_Response
    {
        return rest_ensure_response($this->sync_service->status());
    }

    private function bounds_args(): array
    {
        $arg = array(
            'required' => true,
            'sanitize_callback' => static function ($value): float {
                return (float) $value;
            },
        );

        return array(
            'ne_lat' => $arg,
            'ne_lng' => $arg,
            'sw_lat' => $arg,
            'sw_lng' => $arg,
        );
    }

    private function public_point(array $point): array
    {
        foreach (array_keys($point) as $key) {
            if (strpos((string) $key, '_') === 0) {
                unset($point[$key]);
            }
        }

        return $point;
    }

    private function estimate_options(): array
    {
        $global = $this->settings->all();

        return array(
            'base_rate_kg' => $this->decimal_setting('base_rate_kg', $global),
            'overweight_step_kg' => $this->decimal_setting('overweight_step_kg', $global),
            'handling_days' => (int) ($global['handling_days'] ?? 0),
            'markup' => $this->decimal_setting('markup', $global),
            'markup_type' => (string) ($global['markup_type'] ?? 'fixed'),
            'tariff_type' => (string) ($global['tariff_type'] ?? 'auto'),
        );
    }

    private function package_defaults(): array
    {
        $global = $this->settings->all();

        return array(
            'weight_kg' => $this->decimal_setting('default_item_weight_kg', $global),
            'length_cm' => $this->decimal_setting('default_item_length_cm', $global),
            'width_cm' => $this->decimal_setting('default_item_width_cm', $global),
            'height_cm' => $this->decimal_setting('default_item_height_cm', $global),
        );
    }

    private function decimal_setting(string $key, array $global): float
    {
        return (float) str_replace(',', '.', (string) ($global[$key] ?? '0'));
    }

    /** @param mixed $value
     *  @return array<string, mixed>
     */
    private function normalize_array($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            $decoded = json_decode(wp_json_encode($value), true);
            return is_array($decoded) ? $decoded : array();
        }
        return array();
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

    private function ensure_cart_loaded(): void
    {
        if (!function_exists('WC') || !function_exists('wc_load_cart')) {
            return;
        }

        if (!WC()->session || !WC()->cart) {
            wc_load_cart();
        }
    }
}
