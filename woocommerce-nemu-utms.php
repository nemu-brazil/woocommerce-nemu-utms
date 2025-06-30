<?php
/**
 * Plugin Name: Salvar UTMs no Pedido WooCommerce (NEMU)
 * Description: Captura parâmetros UTM da URL, salva como cookies com prefixo nemu_ e os adiciona ao pedido e ao webhook do WooCommerce.
 * Version: 1.1
 * Author: Seu Nome
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

function save_utms_in_cookies() {
    $utm_params = [
        'utm_source'   => 'nemu_source',
        'utm_medium'   => 'nemu_medium',
        'utm_campaign' => 'nemu_campaign',
        'utm_term'     => 'nemu_term',
        'utm_content'  => 'nemu_content',
    ];

    foreach ($utm_params as $utm => $nemu) {
        if (isset($_GET[$utm])) {
            setcookie($nemu, sanitize_text_field($_GET[$utm]), time() + (30 * DAY_IN_SECONDS), "/");
        }
    }
}
add_action('init', 'save_utms_in_cookies');

function add_utms_to_order($order_id) {
    $utm_params = [
        'utm_source'   => 'nemu_source',
        'utm_medium'   => 'nemu_medium',
        'utm_campaign' => 'nemu_campaign',
        'utm_term'     => 'nemu_term',
        'utm_content'  => 'nemu_content',
    ];

    foreach ($utm_params as $utm => $nemu) {
        if (isset($_COOKIE[$nemu])) {
            update_post_meta($order_id, $nemu, sanitize_text_field($_COOKIE[$nemu]));
        }
    }
}
add_action('woocommerce_checkout_update_order_meta', 'add_utms_to_order');

function add_utms_to_webhook_payload($order_data, $order) {
    $utm_params = [
        'utm_source'   => 'nemu_source',
        'utm_medium'   => 'nemu_medium',
        'utm_campaign' => 'nemu_campaign',
        'utm_term'     => 'nemu_term',
        'utm_content'  => 'nemu_content',
    ];

    foreach ($utm_params as $utm => $nemu) {
        $meta_value = get_post_meta($order->get_id(), $nemu, true);
        if (!empty($meta_value)) {
            $order_data[$nemu] = $meta_value;
        }
    }

    return $order_data;
}
add_filter('woocommerce_webhook_payload_order', 'add_utms_to_webhook_payload', 10, 2);
