<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !getenv('CODEXAWP_TEST_WP_LOAD')) {
    exit(1);
}
require getenv('CODEXAWP_TEST_WP_LOAD');
[$script, $slug, $mode, $version] = $argv;
$settings = match ($slug) {
    'woo-carton-shipping-demo' => ['codexawp_carton_demo', ['title' => 'Upgrade fixture', 'capacity' => '13', 'price' => '6.25']],
    'woo-pickup-point-checkout' => ['codexawp_pickup', ['catalogue' => 'south']],
    default => throw new InvalidArgumentException('Unexpected shipping plugin.'),
};
if ('local' !== wp_get_environment_type() || !class_exists('WooCommerce')) {
    throw new RuntimeException('Need a disposable WooCommerce site.');
}
[$method, $expected] = $settings;
$header = get_file_data(WP_PLUGIN_DIR . "/$slug/$slug.php", ['version' => 'Version']);
if ($header['version'] !== $version) {
    throw new RuntimeException('The installed header does not match the expected version.');
}
$key = 'woocommerce_' . $method . '_10097_settings';
if ('before' === $mode) {
    update_option($key, $expected);
} elseif ('after' === $mode) {
    WC()->shipping()->load_shipping_methods();
    $native = WC()->shipping()->get_shipping_methods()[$method] ?? null;
    if (!$native || get_option($key) !== $expected) {
        throw new RuntimeException('The upgrade lost the shipping method or its saved settings.');
    }
    echo "PASS: $slug was upgraded from the previous ZIP with its shipping settings intact.\n";
} else {
    throw new InvalidArgumentException('Expected before or after.');
}
