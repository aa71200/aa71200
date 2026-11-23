<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !getenv('CODEXAWP_TEST_WP_LOAD')) {
    exit(1);
}
require getenv('CODEXAWP_TEST_WP_LOAD');
if ('local' !== wp_get_environment_type() || !defined('WP_HTTP_BLOCK_EXTERNAL') || !WP_HTTP_BLOCK_EXTERNAL || !class_exists('WooCommerce')) {
    throw new RuntimeException('Use an offline, disposable WooCommerce fixture.');
}

if ('order' === $argv[1]) {
    $order = wc_get_order((int) $argv[2]);
    if (!$order) {
        throw new RuntimeException('Checkout did not create a persisted order.');
    }
    echo wp_json_encode([
        'status' => $order->get_status(),
        'shipping' => (float) $order->get_shipping_total(),
        'payment' => $order->get_payment_method(),
        'pickup' => $order->get_meta('_codexawp_pickup_point'),
        'reference' => $order->get_meta('_codexawp_po_reference'),
    ]);
    exit;
}

if ('comparison-editor' === $argv[1]) {
    $suffix = strtoupper(wp_generate_password(6, false));
    $second_name = 'Portfolio Wren ' . $suffix;
    $products = [];
    foreach (['Portfolio Sparrow ' . $suffix, $second_name] as $name) {
        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price('12.00');
        $product->set_status('publish');
        $products[] = $product->save();
    }
    $page_id = wp_insert_post([
        'post_title' => 'Comparison fixture',
        'post_content' => '<!-- wp:codexawp/product-comparison ' . wp_json_encode(['productIds' => [$products[0]]]) . ' /-->',
        'post_status' => 'publish',
        'post_type' => 'page',
    ]);
    $password = wp_generate_password(28, false);
    $login = 'portfolio_editor_' . strtolower(wp_generate_password(10, false));
    $editor = wp_insert_user([
        'user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@example.test', 'role' => 'editor',
    ]);
    if (!$page_id || is_wp_error($editor)) {
        throw new RuntimeException('Could not create the comparison editor fixture.');
    }
    $url = admin_url('post.php?post=' . $page_id . '&action=edit');
    echo wp_json_encode(['login' => wp_login_url($url), 'customer' => ['login' => $login, 'password' => $password], 'products' => $products, 'search' => $second_name]);
    exit;
}

[$script, $plugin, $mode] = $argv;
if (!in_array($plugin, ['woo-pickup-point-checkout', 'woo-carton-shipping-demo', 'woo-purchase-order-gateway'], true) ||
    !in_array($mode, ['classic', 'blocks'], true)) {
    throw new InvalidArgumentException('Unexpected checkout fixture.');
}
update_option('woocommerce_default_country', 'US:CA');
update_option('woocommerce_default_customer_address', 'base');
update_option('woocommerce_ship_to_countries', '');
update_option('woocommerce_ship_to_destination', 'billing');
update_option('woocommerce_currency', 'USD');
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_enable_guest_checkout', 'yes');
update_option('woocommerce_enable_checkout_login_reminder', 'no');
update_option('woocommerce_cart_redirect_after_add', 'no');
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_bacs_settings', ['enabled' => 'yes', 'title' => 'Direct bank transfer']);
update_option('woocommerce_codexawp_purchase_order_settings', [
    'enabled' => 'woo-purchase-order-gateway' === $plugin ? 'yes' : 'no',
    'title' => 'Purchase Order',
]);

$blocks = get_option('codexawp_fixture_checkout_blocks');
if (!$blocks) {
    WC_Install::create_pages();
    $page = get_post(wc_get_page_id('checkout'));
    if ($page && has_block('woocommerce/checkout', $page)) {
        $blocks = $page->post_content;
        update_option('codexawp_fixture_checkout_blocks', $blocks, false);
    }
}
if ('blocks' === $mode && !$blocks) {
    throw new RuntimeException('WooCommerce checkout block content is unavailable.');
}
$content = 'classic' === $mode ? '[woocommerce_checkout]' : $blocks;
if ('blocks' === $mode && 'woo-pickup-point-checkout' === $plugin) {
    $content = str_replace(
        '<div class="wp-block-woocommerce-checkout-shipping-methods-block"></div>',
        '<div class="wp-block-woocommerce-checkout-shipping-methods-block"><!-- wp:codexawp/pickup-point /--></div>',
        $content
    );
}
$slug = 'portfolio-checkout-' . $mode;
$page = get_page_by_path($slug);
$page_id = wp_insert_post([
    'ID' => $page ? $page->ID : 0, 'post_title' => 'Portfolio checkout', 'post_name' => $slug,
    'post_content' => $content, 'post_status' => 'publish', 'post_type' => 'page',
], true);
if (is_wp_error($page_id)) {
    throw new RuntimeException($page_id->get_error_message());
}
update_option('woocommerce_checkout_page_id', $page_id);
$wp_rewrite->set_permalink_structure('/%postname%/');
flush_rewrite_rules(false);

foreach (['CA' => 'north', 'NY' => 'south'] as $state => $catalogue) {
    $zone_id = (int) get_option('codexawp_fixture_zone_' . $state);
    $zone = $zone_id ? new WC_Shipping_Zone($zone_id) : new WC_Shipping_Zone();
    $zone->set_zone_name('Portfolio ' . $state);
    $zone->set_zone_order(0);
    $zone->set_locations([['code' => 'US:' . $state, 'type' => 'state']]);
    $zone->save();
    update_option('codexawp_fixture_zone_' . $state, $zone->get_id(), false);
    $methods = [
        'codexawp_pickup' => ['catalogue' => $catalogue],
        'codexawp_carton_demo' => ['capacity' => '12', 'price' => '5.00', 'title' => 'Carton delivery'],
        'flat_rate' => ['title' => 'Standard shipping', 'cost' => '14.00', 'tax_status' => 'none'],
    ];
    $selected = [
        'woo-pickup-point-checkout' => 'codexawp_pickup',
        'woo-carton-shipping-demo' => 'codexawp_carton_demo',
        'woo-purchase-order-gateway' => 'flat_rate',
    ][$plugin];
    $existing = [];
    foreach ($zone->get_shipping_methods() as $method) {
        $existing[$method->id] = $method->get_instance_id();
    }
    foreach ($methods as $id => $settings) {
        if (!isset($existing[$id]) && $id !== $selected && 'flat_rate' !== $id) {
            continue;
        }
        $instance = $existing[$id] ?? $zone->add_shipping_method($id);
        update_option("woocommerce_{$id}_{$instance}_settings", $settings, false);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'woocommerce_shipping_zone_methods', ['is_enabled' => (int) ($id === $selected || ('flat_rate' === $id && 'codexawp_pickup' === $selected))], ['instance_id' => $instance]);
    }
}
WC_Cache_Helper::get_transient_version('shipping', true);

$product_id = (int) get_option('codexawp_fixture_product');
$product = new WC_Product_Simple($product_id);
$product->set_name('Portfolio shipping product');
$product->set_regular_price('4.00');
$product->set_status('publish');
$product->set_virtual(false);
$product_id = $product->save();
update_option('codexawp_fixture_product', $product_id, false);

$customers = [];
if ('woo-purchase-order-gateway' === $plugin) {
    foreach (['approved', 'unapproved'] as $type) {
        $login = 'portfolio_' . $type . '_' . strtolower(wp_generate_password(10, false));
        $password = wp_generate_password(28, false);
        $user_id = wp_insert_user([
            'user_login' => $login, 'user_pass' => $password,
            'user_email' => $login . '@example.test', 'role' => 'customer',
        ]);
        if (is_wp_error($user_id)) {
            throw new RuntimeException($user_id->get_error_message());
        }
        update_user_meta($user_id, '_codexawp_po_approved', 'approved' === $type ? 'yes' : 'no');
        $customers[$type] = ['login' => $login, 'password' => $password];
    }
}

echo wp_json_encode([
    'add' => add_query_arg(['add-to-cart' => $product_id, 'quantity' => 'woo-carton-shipping-demo' === $plugin ? 13 : 1], home_url('/')),
    'checkout' => get_permalink($page_id),
    'login' => wp_login_url(get_permalink($page_id)),
    'customers' => $customers,
]);
