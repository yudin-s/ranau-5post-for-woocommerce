<?php

declare(strict_types=1);

namespace Ranau\FivePost;

use DomainException;
use Ranau\FivePost\Internal\DeliveryState\ProviderStateStore;

defined('ABSPATH') || exit;

final class DeliveryState
{
    private const METHOD_ID = 'ranau_fivepost_pickup';
    private const PENDING_KEY = 'ranau_fivepost_pending_selection';

    /** @param array<string, mixed> $selection
     *  @param array<string, mixed> $quote
     *  @return array<string, mixed>
     */
    public function begin(array $selection, array $quote): array
    {
        $rate_id = $this->selected_rate_id();
        $fingerprint = $this->package_fingerprint();
        $this->write_session(self::PENDING_KEY, $selection);

        return $this->store($rate_id)->beginPending(
            $this->context_key($selection, $fingerprint),
            $fingerprint,
            $selection,
            $quote
        );
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    public function commit(array $data): array
    {
        $rate_id = sanitize_text_field((string) ($data['rate_id'] ?? ''));
        $selection = $this->read_session(self::PENDING_KEY);
        $fingerprint = $this->package_fingerprint();
        $committed = $this->store($rate_id)->commit(
            $data,
            $this->selected_rate_id(),
            $this->context_key($selection, $fingerprint),
            $fingerprint
        );
        $this->write_session('ranau_fivepost_point_id', (string) (($committed['selection']['id'] ?? '')));
        $this->clear_shipping_cache();

        return $committed;
    }

    /** @return array<string, mixed>|null */
    public function committed(): ?array
    {
        $rate_id = $this->selected_rate_id();
        if ($rate_id === '') {
            return null;
        }

        return $this->store($rate_id)->committed();
    }

    public function clear(): void
    {
        $rate_id = $this->selected_rate_id();
        if ($rate_id !== '') {
            $this->store($rate_id)->invalidate();
        }
        $this->delete_session(self::PENDING_KEY);
        $this->delete_session('ranau_fivepost_point_id');
        $this->clear_shipping_cache();
    }

    public function fingerprint(): string
    {
        return $this->package_fingerprint();
    }

    private function selected_rate_id(): string
    {
        if (!function_exists('WC') || !WC()->session) {
            return '';
        }
        foreach ((array) WC()->session->get('chosen_shipping_methods', array()) as $rate_id) {
            $rate_id = sanitize_text_field((string) $rate_id);
            if (explode(':', $rate_id)[0] === self::METHOD_ID) {
                return $rate_id;
            }
        }

        return '';
    }

    private function store(string $rate_id): ProviderStateStore
    {
        if ($rate_id === '') {
            throw new DomainException('missing_rate_id');
        }
        $key = 'ranau_fivepost_state_' . md5($rate_id);

        return new ProviderStateStore(
            $rate_id,
            function () use ($key): array {
                return $this->read_session($key);
            },
            function (array $value) use ($key): void {
                $this->write_session($key, $value);
            },
            function () use ($key): void {
                $this->delete_session($key);
            }
        );
    }

    /** @param array<string, mixed> $selection */
    private function context_key(array $selection, string $fingerprint): string
    {
        $parts = array(
            (string) ($selection['country'] ?? 'RU'),
            (string) ($selection['region'] ?? ''),
            (string) ($selection['city'] ?? ''),
            (string) ($selection['postcode'] ?? ''),
            $fingerprint,
        );

        return implode('|', array_map(static function ($value): string {
            return strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
        }, $parts));
    }

    private function package_fingerprint(): string
    {
        $items = array();
        if (function_exists('WC') && WC()->cart) {
            foreach ((array) WC()->cart->get_cart() as $item) {
                $product = is_array($item) ? ($item['data'] ?? null) : null;
                $items[] = array(
                    'product_id' => (int) (is_array($item) ? ($item['product_id'] ?? 0) : 0),
                    'variation_id' => (int) (is_array($item) ? ($item['variation_id'] ?? 0) : 0),
                    'quantity' => (int) (is_array($item) ? ($item['quantity'] ?? 0) : 0),
                    'weight' => is_object($product) && method_exists($product, 'get_weight') ? (string) $product->get_weight() : '',
                    'length' => is_object($product) && method_exists($product, 'get_length') ? (string) $product->get_length() : '',
                    'width' => is_object($product) && method_exists($product, 'get_width') ? (string) $product->get_width() : '',
                    'height' => is_object($product) && method_exists($product, 'get_height') ? (string) $product->get_height() : '',
                );
            }
        }

        return hash('sha256', (string) wp_json_encode($items));
    }

    /** @return array<string, mixed> */
    private function read_session(string $key): array
    {
        if (!function_exists('WC') || !WC()->session) {
            return array();
        }
        $value = WC()->session->get($key, array());

        return is_array($value) ? $value : array();
    }

    /** @param array<string, mixed>|string $value */
    private function write_session(string $key, $value): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set($key, $value);
            if (method_exists(WC()->session, 'save_data')) {
                WC()->session->save_data();
            }
        }
    }

    private function delete_session(string $key): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->__unset($key);
            if (method_exists(WC()->session, 'save_data')) {
                WC()->session->save_data();
            }
        }
    }

    private function clear_shipping_cache(): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        foreach (array_keys((array) WC()->session->get_session_data()) as $key) {
            if (strpos((string) $key, 'shipping_for_package_') === 0) {
                WC()->session->__unset($key);
            }
        }
    }
}
