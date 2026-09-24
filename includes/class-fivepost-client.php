<?php

namespace Ranau\FivePost;

if (!defined('ABSPATH')) {
    exit;
}

final class FivePostClient
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function fetch_pickup_points()
    {
        $api_key = $this->settings->get('fivepost_api_key');
        if ($api_key === '') {
            return new \WP_Error('ranau_fivepost_missing_api_key', __('Ключ API 5Post не настроен.', 'ranau-5post-for-woocommerce'));
        }

        $jwt = $this->request_jwt($api_key);
        if (is_wp_error($jwt)) {
            return $jwt;
        }

        $points = array();
        $page = 0;
        $total_pages = 1;

        do {
            $response = $this->post_with_retry($this->base_url() . '/api/v1/pickuppoints/query', array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $jwt,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode(array(
                    'pageSize' => 1000,
                    'pageNumber' => $page,
                )),
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if ($code < 200 || $code >= 300 || !is_array($body)) {
                return new \WP_Error('ranau_fivepost_points_failed', __('Не удалось получить пункты выдачи 5Post.', 'ranau-5post-for-woocommerce'));
            }

            $content = $body['content'] ?? $body;
            $fields = $content['fields'] ?? $body['fields'] ?? array();
            if (is_array($content) && isset($content[0]) && is_array($content[0])) {
                $fields = $content;
            }
            if (is_array($fields)) {
                foreach ($fields as $point) {
                    if (is_array($point)) {
                        $points[] = $point;
                    }
                }
            }

            $total_pages = max(1, (int) ($body['totalPages'] ?? $content['totalPages'] ?? 1));
            $page++;
        } while ($page < $total_pages);

        return $points;
    }

    public function fetch_pickup_points_page(int $page_number, int $page_size)
    {
        $api_key = $this->settings->get('fivepost_api_key');
        if ($api_key === '') {
            return new \WP_Error('ranau_fivepost_missing_api_key', __('Ключ API 5Post не настроен.', 'ranau-5post-for-woocommerce'));
        }

        $jwt = $this->request_jwt($api_key);
        if (is_wp_error($jwt)) {
            return $jwt;
        }

        $response = $this->post_with_retry($this->base_url() . '/api/v1/pickuppoints/query', array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'pageSize' => $page_size,
                'pageNumber' => $page_number,
            )),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 429) {
            return new \WP_Error('ranau_fivepost_rate_limited', __('Достигнут лимит запросов 5Post. Продолжим позже.', 'ranau-5post-for-woocommerce'), array('status' => 429));
        }
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            return new \WP_Error('ranau_fivepost_points_failed', __('Не удалось получить пункты выдачи 5Post.', 'ranau-5post-for-woocommerce'), array('status' => $code));
        }

        $content = $body['content'] ?? $body;
        $fields = $content['fields'] ?? $body['fields'] ?? array();
        if (is_array($content) && isset($content[0]) && is_array($content[0])) {
            $fields = $content;
        }

        return array(
            'points' => is_array($fields) ? $fields : array(),
            'page_number' => (int) ($body['number'] ?? $page_number),
            'page_size' => (int) ($body['size'] ?? $page_size),
            'total_pages' => max(1, (int) ($body['totalPages'] ?? 1)),
            'total_elements' => max(0, (int) ($body['totalElements'] ?? 0)),
            'is_last' => !empty($body['last']),
        );
    }

    private function request_jwt(string $api_key)
    {
        $url = add_query_arg('apikey', $api_key, $this->base_url() . '/jwt-generate-claims/rs256/1');
        $response = $this->post_with_retry($url, array(
            'timeout' => 60,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => array(
                'subject' => 'OpenAPI',
                'audience' => 'A122019!',
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['jwt'])) {
            return new \WP_Error('ranau_fivepost_jwt_failed', __('Не удалось получить JWT 5Post.', 'ranau-5post-for-woocommerce'));
        }

        return (string) $body['jwt'];
    }

    private function base_url(): string
    {
        return $this->settings->get('environment', 'test') === 'production'
            ? 'https://api-omni.x5.ru'
            : 'https://api-preprod-omni.x5.ru';
    }

    private function post_with_retry(string $url, array $args, int $attempts = 3)
    {
        $last_response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $last_response = wp_remote_post($url, $args);
            if (!is_wp_error($last_response)) {
                return $last_response;
            }

            if ($attempt < $attempts) {
                sleep($attempt);
            }
        }

        return $last_response;
    }
}
