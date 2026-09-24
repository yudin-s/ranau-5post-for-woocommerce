<?php

namespace Ranau\FivePost;

if (!defined('ABSPATH')) {
    exit;
}

final class Assets
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function init(): void
    {
        add_action('wp_enqueue_scripts', array($this, 'register'));
    }

    public function register(): void
    {
        $yandex_js_api_key = $this->settings->get('yandex_js_api_key');
        if ($yandex_js_api_key === '') {
            $yandex_js_api_key = $this->settings->get('yandex_maps_key');
        }
        $yandex_suggest_api_key = $this->settings->get('yandex_suggest_api_key');
        $yandex_geocoder_api_key = $this->settings->get('yandex_maps_key');

        wp_register_style(
            'ranau-fivepost-frontend',
            RANAU_FIVEPOST_URL . 'assets/css/frontend.css',
            array(),
            $this->asset_version('assets/css/frontend.css')
        );

        wp_register_script(
            'ranau-fivepost-delivery-runtime',
            RANAU_FIVEPOST_URL . 'assets/js/delivery-runtime.js',
            array(),
            $this->asset_version('assets/js/delivery-runtime.js'),
            true
        );
        wp_register_script(
            'ranau-fivepost-delivery-adapter',
            RANAU_FIVEPOST_URL . 'assets/js/delivery-adapter.js',
            array('ranau-fivepost-delivery-runtime'),
            $this->asset_version('assets/js/delivery-adapter.js'),
            true
        );

        wp_register_script(
            'ranau-fivepost-map-widget',
            RANAU_FIVEPOST_URL . 'assets/js/map-widget.js',
            array('ranau-fivepost-delivery-adapter', 'wc-blocks-checkout'),
            $this->asset_version('assets/js/map-widget.js'),
            true
        );

        wp_localize_script('ranau-fivepost-map-widget', 'RanauFivePost', array(
            'restUrl' => esc_url_raw(rest_url('ranau-fivepost/v1')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'yandexMapsKey' => $yandex_js_api_key,
            'yandexSuggestApiKey' => $yandex_suggest_api_key,
            'yandexGeocoderApiKey' => $yandex_geocoder_api_key,
            'defaultCenter' => null,
            'packageFingerprint' => (new DeliveryState())->fingerprint(),
            'i18n' => array(
                'openMap' => __('Выбрать пункт выдачи', 'ranau-5post-for-woocommerce'),
                'modalTitle' => __('Выберите пункт выдачи 5Post', 'ranau-5post-for-woocommerce'),
                'close' => __('Закрыть', 'ranau-5post-for-woocommerce'),
                'loading' => __('Загружаем пункты выдачи...', 'ranau-5post-for-woocommerce'),
                'choose' => __('Выбрать', 'ranau-5post-for-woocommerce'),
                'useLocation' => __('Мое местоположение', 'ranau-5post-for-woocommerce'),
                'searchPlaceholder' => __('Введите адрес', 'ranau-5post-for-woocommerce'),
                'searchButton' => __('Найти', 'ranau-5post-for-woocommerce'),
                'results' => __('Пункты выдачи', 'ranau-5post-for-woocommerce'),
                'empty' => __('В этой области карты нет доступных пунктов выдачи.', 'ranau-5post-for-woocommerce'),
                'pickupPoint' => __('Пункт выдачи', 'ranau-5post-for-woocommerce'),
                'zeroPoints' => __('0 пунктов выдачи', 'ranau-5post-for-woocommerce'),
                'pointsShort' => __('пунктов выдачи', 'ranau-5post-for-woocommerce'),
                'addressNotFound' => __('Адрес не найден.', 'ranau-5post-for-woocommerce'),
                'addressSearchFailed' => __('Не удалось выполнить поиск адреса.', 'ranau-5post-for-woocommerce'),
                'yandexKeyMissing' => __('Не указан ключ JavaScript API Яндекс Карт.', 'ranau-5post-for-woocommerce'),
                'yandexLoadFailed' => __('Не удалось загрузить Яндекс Карты.', 'ranau-5post-for-woocommerce'),
                'pointsLoadFailed' => __('Не удалось загрузить пункты выдачи.', 'ranau-5post-for-woocommerce'),
                'geolocationUnavailable' => __('Геолокация недоступна.', 'ranau-5post-for-woocommerce'),
                'geolocationFailed' => __('Не удалось определить местоположение.', 'ranau-5post-for-woocommerce'),
                'geolocationWaiting' => __('Ожидаем разрешение браузера на определение местоположения...', 'ranau-5post-for-woocommerce'),
                'geolocationDenied' => __('Браузер запретил доступ к местоположению. Разрешите геопозицию для сайта и попробуйте снова.', 'ranau-5post-for-woocommerce'),
                'geolocationTimeout' => __('Браузер не успел определить местоположение за минуту. Попробуйте еще раз.', 'ranau-5post-for-woocommerce'),
                'geolocationPositionUnavailable' => __('Браузер не смог получить координаты устройства.', 'ranau-5post-for-woocommerce'),
            ),
        ));
    }

    public function enqueue(): void
    {
        wp_enqueue_style('ranau-fivepost-frontend');
        wp_enqueue_script('ranau-fivepost-map-widget');
    }

    private function asset_version(string $relative_path): string
    {
        $path = RANAU_FIVEPOST_DIR . ltrim($relative_path, '/');
        $mtime = is_readable($path) ? filemtime($path) : false;

        return $mtime ? (string) $mtime : RANAU_FIVEPOST_VERSION;
    }
}
