<?php

namespace Ranau\FivePost;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;

    private Settings $settings;
    private PickupPointRepository $repository;
    private CartPackageResolver $package_resolver;
    private DeliveryEstimateService $estimate_service;
    private SyncService $sync_service;
    private RestController $rest_controller;
    private Assets $assets;
    private ClassicCheckoutAdapter $classic_checkout;
    private BlocksCheckoutAdapter $blocks_checkout;

    public static function instance(): Plugin
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;

        $this->settings = new Settings();
        $this->repository = new PickupPointRepository($wpdb);
        $this->package_resolver = new CartPackageResolver();
        $this->estimate_service = new DeliveryEstimateService();
        $this->assets = new Assets($this->settings);
        $query = new PickupPointQuery();
        $this->sync_service = new SyncService(
            new FivePostClient($this->settings),
            $this->repository
        );
        $this->rest_controller = new RestController(
            $this->settings,
            $this->repository,
            $this->package_resolver,
            $query,
            $this->estimate_service,
            $this->sync_service
        );
        $this->classic_checkout = new ClassicCheckoutAdapter($this->settings, $this->assets);
        $this->blocks_checkout = new BlocksCheckoutAdapter($this->settings, $this->assets);
    }

    public function init(): void
    {
        Installer::maybe_migrate();
        $this->settings->init();
        $this->rest_controller->init();
        $this->assets->init();
        add_action('ranau_fivepost_pickup_sync_batch', array($this->sync_service, 'process_background_batch'));
        add_filter('plugin_action_links_ranau-5post-for-woocommerce/ranau-5post-for-woocommerce.php', array($this, 'add_settings_link'));

        add_action('woocommerce_shipping_init', function (): void {
            require_once RANAU_FIVEPOST_DIR . 'includes/class-shipping-method.php';
        });
        add_filter('woocommerce_shipping_methods', function (array $methods): array {
            $methods['ranau_fivepost_pickup'] = ShippingMethod::class;
            return $methods;
        });

        $this->classic_checkout->init();
        $this->blocks_checkout->init();
    }

    public function add_settings_link(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=ranau-5post-for-woocommerce')),
            esc_html__('Настройки', 'ranau-5post-for-woocommerce')
        );
        array_unshift($links, $settings_link);

        return $links;
    }
}
