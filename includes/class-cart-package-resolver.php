<?php

namespace Ranau\FivePost;

if (!defined('ABSPATH')) {
    exit;
}

final class CartPackageResolver
{
    public function resolve(array $defaults = array()): Package
    {
        if (!function_exists('WC') || !WC()->cart) {
            return new Package(0, 0, 0, 0);
        }

        return (new PackageContentResolver())->resolve(WC()->cart->get_cart(), $defaults);
    }
}
