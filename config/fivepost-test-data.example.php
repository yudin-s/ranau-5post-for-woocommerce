<?php
/**
 * Local 5Post test credentials and scenarios.
 *
 * Copy this file to config/fivepost-test-data.php and fill in real values.
 * The copied file is ignored by git.
 */

return array(
    /*
     * 5Post API access.
     *
     * Required for real pickup point synchronization tests.
     */
    'fivepost' => array(
        'api_key' => '',
        'environment' => 'test', // test|production
    ),

    /*
     * Yandex Maps key.
     *
     * Required for checkout map rendering and address search tests.
     * Enable JavaScript API and Geocoder/Geosuggest as needed.
     */
    'yandex_maps' => array(
        'api_key' => '',
        'allowed_domains' => array(
            'localhost',
            '127.0.0.1',
        ),
    ),

    /*
     * Sender warehouse/address used for future delivery estimate work.
     *
     * MVP can leave delivery estimate as TODO, but these fields let us test
     * the data flow without changing the test file later.
     */
    'sender' => array(
        'city' => '',
        'address' => '',
        'postal_code' => '',
        'latitude' => null,
        'longitude' => null,
    ),

    /*
     * Checkout map test areas.
     *
     * Use these for bbox tests. Coordinates are decimal degrees.
     */
    'map_areas' => array(
        array(
            'name' => 'Moscow center',
            'north_east' => array('lat' => 55.81, 'lng' => 37.70),
            'south_west' => array('lat' => 55.70, 'lng' => 37.50),
        ),
    ),

    /*
     * Product/package scenarios for weight and dimensions filtering.
     *
     * Dimensions are centimeters, weight is kilograms.
     */
    'packages' => array(
        array(
            'name' => 'Small cosmetics order',
            'weight_kg' => 0.5,
            'length_cm' => 15,
            'width_cm' => 10,
            'height_cm' => 5,
        ),
        array(
            'name' => 'Large parcel edge case',
            'weight_kg' => 8,
            'length_cm' => 45,
            'width_cm' => 35,
            'height_cm' => 25,
        ),
    ),

    /*
     * Customer address/search scenarios.
     *
     * These values are safe examples; replace or add real test addresses.
     */
    'customer_locations' => array(
        array(
            'name' => 'Address search test',
            'query' => 'Москва, Тверская улица, 1',
            'latitude' => null,
            'longitude' => null,
        ),
    ),
);
