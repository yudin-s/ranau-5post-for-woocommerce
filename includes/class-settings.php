<?php

namespace Ranau\FivePost;

if (!defined('ABSPATH')) {
    exit;
}

final class Settings
{
    public const OPTION = 'ranau_fivepost_pickup_settings';

    public function init(): void
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Ranau 5Post', 'ranau-5post-for-woocommerce'),
            __('Ranau 5Post', 'ranau-5post-for-woocommerce'),
            'manage_woocommerce',
            'ranau-5post-for-woocommerce',
            array($this, 'render_page')
        );
    }

    public function register_settings(): void
    {
        register_setting(self::OPTION, self::OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize'),
            'default' => array(),
        ));
    }

    public function sanitize($value): array
    {
        $value = is_array($value) ? $value : array();
        return array(
            'fivepost_api_key' => sanitize_text_field($value['fivepost_api_key'] ?? ''),
            'environment' => in_array(($value['environment'] ?? 'test'), array('test', 'production'), true) ? $value['environment'] : 'test',
            'yandex_js_api_key' => sanitize_text_field($value['yandex_js_api_key'] ?? ''),
            'yandex_suggest_api_key' => sanitize_text_field($value['yandex_suggest_api_key'] ?? ''),
            'yandex_maps_key' => sanitize_text_field($value['yandex_maps_key'] ?? ''),
            'base_rate_kg' => $this->sanitize_decimal($value['base_rate_kg'] ?? ''),
            'overweight_step_kg' => $this->sanitize_decimal($value['overweight_step_kg'] ?? ''),
            'handling_days' => (string) max(0, (int) ($value['handling_days'] ?? 0)),
            'markup' => $this->sanitize_decimal($value['markup'] ?? '0'),
            'markup_type' => in_array(($value['markup_type'] ?? 'fixed'), array('fixed', 'percent'), true) ? $value['markup_type'] : 'fixed',
            'tariff_type' => sanitize_text_field($value['tariff_type'] ?? 'auto'),
            'default_item_weight_kg' => $this->sanitize_decimal($value['default_item_weight_kg'] ?? '1'),
            'default_item_length_cm' => $this->sanitize_decimal($value['default_item_length_cm'] ?? '20'),
            'default_item_width_cm' => $this->sanitize_decimal($value['default_item_width_cm'] ?? '20'),
            'default_item_height_cm' => $this->sanitize_decimal($value['default_item_height_cm'] ?? '10'),
        );
    }

    public function get(string $key, string $default = ''): string
    {
        $options = get_option(self::OPTION, array());
        return isset($options[$key]) ? (string) $options[$key] : $default;
    }

    public function all(): array
    {
        return array(
            'fivepost_api_key' => $this->get('fivepost_api_key'),
            'environment' => $this->get('environment', 'test'),
            'yandex_js_api_key' => $this->get('yandex_js_api_key'),
            'yandex_suggest_api_key' => $this->get('yandex_suggest_api_key'),
            'yandex_maps_key' => $this->get('yandex_maps_key'),
            'base_rate_kg' => $this->get('base_rate_kg'),
            'overweight_step_kg' => $this->get('overweight_step_kg'),
            'handling_days' => $this->get('handling_days', '0'),
            'markup' => $this->get('markup', '0'),
            'markup_type' => $this->get('markup_type', 'fixed'),
            'tariff_type' => $this->get('tariff_type', 'auto'),
            'default_item_weight_kg' => $this->get('default_item_weight_kg', '1'),
            'default_item_length_cm' => $this->get('default_item_length_cm', '20'),
            'default_item_width_cm' => $this->get('default_item_width_cm', '20'),
            'default_item_height_cm' => $this->get('default_item_height_cm', '10'),
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Недостаточно прав.', 'ranau-5post-for-woocommerce'));
        }

        $options = $this->all();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Ranau 5Post: пункты выдачи', 'ranau-5post-for-woocommerce'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ranau-fivepost-api-key"><?php echo esc_html__('Ключ API 5Post', 'ranau-5post-for-woocommerce'); ?></label></th>
                        <td><input id="ranau-fivepost-api-key" class="regular-text" type="password" name="<?php echo esc_attr(self::OPTION); ?>[fivepost_api_key]" value="<?php echo esc_attr($options['fivepost_api_key']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ranau-fivepost-env"><?php echo esc_html__('Окружение', 'ranau-5post-for-woocommerce'); ?></label></th>
                        <td>
                            <select id="ranau-fivepost-env" name="<?php echo esc_attr(self::OPTION); ?>[environment]">
                                <option value="test" <?php selected($options['environment'], 'test'); ?>><?php echo esc_html__('Тестовое', 'ranau-5post-for-woocommerce'); ?></option>
                                <option value="production" <?php selected($options['environment'], 'production'); ?>><?php echo esc_html__('Боевое', 'ranau-5post-for-woocommerce'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ranau-yandex-js-api-key"><?php echo esc_html__('Ключ JavaScript API Яндекс Карт', 'ranau-5post-for-woocommerce'); ?></label></th>
                        <td>
                            <input id="ranau-yandex-js-api-key" class="regular-text" type="password" name="<?php echo esc_attr(self::OPTION); ?>[yandex_js_api_key]" value="<?php echo esc_attr($options['yandex_js_api_key']); ?>">
                            <p class="description"><?php echo esc_html__('Используется для загрузки api-maps.yandex.ru/v3 и встроенных контролов карты. Нужен отдельный активный ключ продукта JavaScript API с HTTP Referer для домена сайта.', 'ranau-5post-for-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ranau-yandex-key"><?php echo esc_html__('Ключ HTTP Геокодера Яндекс', 'ranau-5post-for-woocommerce'); ?></label></th>
                        <td>
                            <input id="ranau-yandex-key" class="regular-text" type="password" name="<?php echo esc_attr(self::OPTION); ?>[yandex_maps_key]" value="<?php echo esc_attr($options['yandex_maps_key']); ?>">
                            <p class="description"><?php echo esc_html__('Отдельный ключ API Геокодера. Используется для поиска координат по выбранному адресу; не заменяет ключ JavaScript API.', 'ranau-5post-for-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ranau-yandex-suggest-api-key"><?php echo esc_html__('Ключ API Геосаджеста Яндекс', 'ranau-5post-for-woocommerce'); ?></label></th>
                        <td>
                            <input id="ranau-yandex-suggest-api-key" class="regular-text" type="password" name="<?php echo esc_attr(self::OPTION); ?>[yandex_suggest_api_key]" value="<?php echo esc_attr($options['yandex_suggest_api_key']); ?>">
                            <p class="description"><?php echo esc_html__('Отдельный ключ API Геосаджеста. Используется для подсказок в поиске v3; ключ Геокодера сюда не подставляется.', 'ranau-5post-for-woocommerce'); ?></p>
                        </td>
                    </tr>
	                </table>
	                <h2><?php echo esc_html__('Расчет стоимости доставки', 'ranau-5post-for-woocommerce'); ?></h2>
	                <p><?php echo esc_html__('Эти параметры используются для расчета ставки 5Post по тарифам, загруженным вместе со списком ПВЗ.', 'ranau-5post-for-woocommerce'); ?></p>
	                <table class="form-table" role="presentation">
	                    <tr>
	                        <th scope="row"><label for="ranau-base-rate-kg"><?php echo esc_html__('Базовый вес, кг', 'ranau-5post-for-woocommerce'); ?></label></th>
	                        <td><input id="ranau-base-rate-kg" class="small-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[base_rate_kg]" value="<?php echo esc_attr($options['base_rate_kg']); ?>"></td>
	                    </tr>
	                    <tr>
	                        <th scope="row"><label for="ranau-overweight-step-kg"><?php echo esc_html__('Шаг перевеса, кг', 'ranau-5post-for-woocommerce'); ?></label></th>
	                        <td><input id="ranau-overweight-step-kg" class="small-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[overweight_step_kg]" value="<?php echo esc_attr($options['overweight_step_kg']); ?>"></td>
	                    </tr>
	                    <tr>
	                        <th scope="row"><label for="ranau-handling-days"><?php echo esc_html__('Дополнительные дни', 'ranau-5post-for-woocommerce'); ?></label></th>
	                        <td><input id="ranau-handling-days" class="small-text" type="number" min="0" name="<?php echo esc_attr(self::OPTION); ?>[handling_days]" value="<?php echo esc_attr($options['handling_days']); ?>"></td>
	                    </tr>
	                    <tr>
	                        <th scope="row"><label for="ranau-markup"><?php echo esc_html__('Наценка', 'ranau-5post-for-woocommerce'); ?></label></th>
	                        <td>
	                            <input id="ranau-markup" class="small-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[markup]" value="<?php echo esc_attr($options['markup']); ?>">
	                            <select name="<?php echo esc_attr(self::OPTION); ?>[markup_type]">
	                                <option value="fixed" <?php selected($options['markup_type'], 'fixed'); ?>><?php echo esc_html__('Фиксированная', 'ranau-5post-for-woocommerce'); ?></option>
	                                <option value="percent" <?php selected($options['markup_type'], 'percent'); ?>><?php echo esc_html__('Процент', 'ranau-5post-for-woocommerce'); ?></option>
	                            </select>
	                        </td>
	                    </tr>
	                    <tr>
	                        <th scope="row"><label for="ranau-tariff-type"><?php echo esc_html__('Тип тарифа', 'ranau-5post-for-woocommerce'); ?></label></th>
	                        <td><input id="ranau-tariff-type" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[tariff_type]" value="<?php echo esc_attr($options['tariff_type']); ?>"><p class="description"><?php echo esc_html__('Оставьте auto для выбора минимальной ставки или укажите точный rateType.', 'ranau-5post-for-woocommerce'); ?></p></td>
	                    </tr>
	                    <tr>
	                        <th scope="row"><label for="ranau-default-weight"><?php echo esc_html__('Вес товара по умолчанию, кг', 'ranau-5post-for-woocommerce'); ?></label></th>
	                        <td><input id="ranau-default-weight" class="small-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[default_item_weight_kg]" value="<?php echo esc_attr($options['default_item_weight_kg']); ?>"></td>
	                    </tr>
	                    <tr>
	                        <th scope="row"><label for="ranau-default-length"><?php echo esc_html__('Длина товара по умолчанию, см', 'ranau-5post-for-woocommerce'); ?></label></th>
	                        <td><input id="ranau-default-length" class="small-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[default_item_length_cm]" value="<?php echo esc_attr($options['default_item_length_cm']); ?>"></td>
	                    </tr>
	                    <tr>
	                        <th scope="row"><label for="ranau-default-width"><?php echo esc_html__('Ширина товара по умолчанию, см', 'ranau-5post-for-woocommerce'); ?></label></th>
	                        <td><input id="ranau-default-width" class="small-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[default_item_width_cm]" value="<?php echo esc_attr($options['default_item_width_cm']); ?>"></td>
	                    </tr>
	                    <tr>
	                        <th scope="row"><label for="ranau-default-height"><?php echo esc_html__('Высота товара по умолчанию, см', 'ranau-5post-for-woocommerce'); ?></label></th>
	                        <td><input id="ranau-default-height" class="small-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[default_item_height_cm]" value="<?php echo esc_attr($options['default_item_height_cm']); ?>"></td>
	                    </tr>
	                </table>
	                <?php submit_button(); ?>
	            </form>
            <hr>
            <h2><?php echo esc_html__('Синхронизация пунктов выдачи', 'ranau-5post-for-woocommerce'); ?></h2>
            <p><?php echo esc_html__('Синхронизация идет небольшими фоновыми пачками, чтобы не упираться в лимиты 5Post.', 'ranau-5post-for-woocommerce'); ?></p>
            <p>
                <label for="ranau-fivepost-sync-page-size"><?php echo esc_html__('Размер пачки', 'ranau-5post-for-woocommerce'); ?></label>
                <input id="ranau-fivepost-sync-page-size" type="number" min="1" max="250" value="100" style="width: 80px;">
                <button type="button" class="button button-primary" id="ranau-fivepost-sync"><?php echo esc_html__('Запустить / продолжить', 'ranau-5post-for-woocommerce'); ?></button>
                <button type="button" class="button" id="ranau-fivepost-sync-restart"><?php echo esc_html__('Начать заново с 1 страницы', 'ranau-5post-for-woocommerce'); ?></button>
            </p>
            <div id="ranau-fivepost-sync-progress" style="background:#dcdcde;height:18px;max-width:520px;position:relative;">
                <div id="ranau-fivepost-sync-progress-bar" style="background:#2271b1;height:18px;width:0%;"></div>
            </div>
            <p id="ranau-fivepost-sync-status"></p>
            <script>
                (function () {
                    var button = document.getElementById('ranau-fivepost-sync');
                    var restartButton = document.getElementById('ranau-fivepost-sync-restart');
                    var pageSize = document.getElementById('ranau-fivepost-sync-page-size');
                    var status = document.getElementById('ranau-fivepost-sync-status');
                    var bar = document.getElementById('ranau-fivepost-sync-progress-bar');
                    var pollTimer = null;
                    if (!button || !restartButton || !status || !bar || !pageSize) return;

                    function request(url, options) {
                        options = options || {};
                        options.credentials = 'same-origin';
                        options.headers = Object.assign({'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'}, options.headers || {});
                        return fetch(url, options).then(function (response) {
                            return response.json().then(function (body) {
                                if (!response.ok) throw body;
                                return body;
                            });
                        });
                    }

                    function renderState(body) {
                        var percent = Number(body.progress_percent || 0);
                        bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
                        var totalPages = body.total_pages || '?';
                        var totalElements = body.total_elements || '?';
                        var deleted = body.deleted_count || 0;
                        status.textContent = [
                            '<?php echo esc_js(__('Статус:', 'ranau-5post-for-woocommerce')); ?> ' + (body.status || 'unknown'),
                            '<?php echo esc_js(__('Страница:', 'ranau-5post-for-woocommerce')); ?> ' + (body.current_page || 0) + ' / ' + totalPages,
                            '<?php echo esc_js(__('Сохранено:', 'ranau-5post-for-woocommerce')); ?> ' + (body.synced_count || 0) + ' / ' + totalElements,
                            '<?php echo esc_js(__('Удалено устаревших:', 'ranau-5post-for-woocommerce')); ?> ' + deleted,
                            '<?php echo esc_js(__('Прогресс:', 'ranau-5post-for-woocommerce')); ?> ' + percent + '%'
                        ].join(' · ');
                        if (body.error_message) {
                            status.textContent += ' · ' + body.error_message;
                        }
                    }

                    function poll() {
                        request('<?php echo esc_url_raw(rest_url('ranau-fivepost/v1/admin/sync/status')); ?>')
                            .then(function (body) {
                                renderState(body);
                                if (['queued', 'running', 'rate_limited'].indexOf(body.status) !== -1 || body.scheduled) {
                                    pollTimer = window.setTimeout(poll, body.status === 'rate_limited' ? 60000 : 5000);
                                }
                            })
                            .catch(function (error) {
                                status.textContent = error && error.message ? error.message : '<?php echo esc_js(__('Не удалось прочитать статус синхронизации.', 'ranau-5post-for-woocommerce')); ?>';
                            });
                    }

                    function start(restart) {
                        button.disabled = true;
                        restartButton.disabled = true;
                        window.clearTimeout(pollTimer);
                        var url = new URL('<?php echo esc_url_raw(rest_url('ranau-fivepost/v1/admin/sync')); ?>');
                        url.searchParams.set('page_size', pageSize.value || '100');
                        if (restart) url.searchParams.set('restart', '1');
                        status.textContent = '<?php echo esc_js(__('Ставим фоновую синхронизацию в очередь...', 'ranau-5post-for-woocommerce')); ?>';
                        request(url.toString(), {
                            method: 'POST',
                        }).then(function (body) {
                            renderState(body);
                            pollTimer = window.setTimeout(poll, 3000);
                        }).then(function (body) {
                            return body;
                        }).catch(function (error) {
                            status.textContent = error && error.message ? error.message : '<?php echo esc_js(__('Синхронизация не запустилась.', 'ranau-5post-for-woocommerce')); ?>';
                        }).finally(function () {
                            button.disabled = false;
                            restartButton.disabled = false;
                        });
                    }

                    button.addEventListener('click', function () {
                        start(false);
                    });
                    restartButton.addEventListener('click', function () {
                        start(true);
                    });
                    poll();
                }());
            </script>
        </div>
	        <?php
	    }

    private function sanitize_decimal($value): string
    {
        $value = str_replace(',', '.', (string) $value);
        return is_numeric($value) ? (string) max(0, (float) $value) : '';
    }
}
