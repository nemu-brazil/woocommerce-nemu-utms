<?php
/**
 * Plugin Name: Salvar UTMs no Pedido WooCommerce (NEMU)
 * Description: Captura parâmetros UTM da URL, salva como cookies com prefixo nemu_ e os adiciona ao pedido e ao webhook do WooCommerce.
 * Version: 1.2
 * Author: Seu Nome
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

function nemu_utms_logger() {
    return wc_get_logger();
}

function nemu_current_referrer() {
    return isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field($_SERVER['HTTP_REFERER']) : '';
}

function nemu_infer_source_from_clickids_or_referrer($get, $ref) {
    if (!empty($get['gclid']))   return 'google';
    if (!empty($get['fbclid']))  return 'facebook';
    if (!empty($get['ttclid']))  return 'tiktok';
    if (!empty($get['msclkid'])) return 'microsoft';

    $ref_lc = strtolower($ref);
    if ($ref_lc) {
        if (strpos($ref_lc, 'google.') !== false)   return 'google';
        if (strpos($ref_lc, 'facebook.') !== false) return 'facebook';
        if (strpos($ref_lc, 'tiktok.') !== false)   return 'tiktok';
        if (strpos($ref_lc, 'bing.') !== false)     return 'bing';
        if (strpos($ref_lc, 'instagram.') !== false) return 'instagram';
        if (strpos($ref_lc, 'youtube.') !== false)   return 'youtube';
    }
    return 'direct';
}

function nemu_set_cookie($name, $value, $ttl_days = 30) {
    if (!headers_sent() && $value !== null && $value !== '') {
        $expires = time() + ($ttl_days * DAY_IN_SECONDS);
        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $value;
    }
}

function nemu_should_overwrite($key, $newVal) {
    $cur = isset($_COOKIE[$key]) ? sanitize_text_field($_COOKIE[$key]) : '';
    if ($cur && strtolower($cur) !== 'direct' && (empty($newVal) || strtolower($newVal) === 'direct')) {
        return false;
    }
    return true;
}

function nemu_capture_utms_into_cookies() {
    $log = nemu_utms_logger();
    $utm_map = [
        'utm_source'   => 'nemu_source',
        'utm_medium'   => 'nemu_medium',
        'utm_campaign' => 'nemu_campaign',
        'utm_term'     => 'nemu_term',
        'utm_content'  => 'nemu_content',
    ];

    $ref = nemu_current_referrer();

    $get = [];
    foreach (array_merge(array_keys($utm_map), ['gclid','fbclid','ttclid','msclkid']) as $k) {
        if (isset($_GET[$k])) $get[$k] = sanitize_text_field($_GET[$k]);
    }

    $source = isset($get['utm_source']) && $get['utm_source'] !== ''
        ? $get['utm_source']
        : nemu_infer_source_from_clickids_or_referrer($get, $ref);

    if (nemu_should_overwrite('nemu_source', $source)) {
        nemu_set_cookie('nemu_source', $source);
    }

    foreach ($utm_map as $utm => $cookieKey) {
        if ($utm === 'utm_source') continue;
        if (isset($get[$utm]) && $get[$utm] !== '') {
            if (nemu_should_overwrite($cookieKey, $get[$utm])) {
                nemu_set_cookie($cookieKey, $get[$utm]);
            }
        }
    }

    if (!isset($_COOKIE['nemu_landing'])) {
        nemu_set_cookie('nemu_landing', (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    }
    if ($ref && !isset($_COOKIE['nemu_referrer'])) {
        nemu_set_cookie('nemu_referrer', $ref);
    }

    $log->info('NEMU UTMs captured', [
        'get'      => $get,
        'ref'      => $ref,
        'cookies'  => array_intersect_key($_COOKIE, array_flip(['nemu_source','nemu_medium','nemu_campaign','nemu_term','nemu_content','nemu_landing','nemu_referrer'])),
    ]);
}
add_action('init', 'nemu_capture_utms_into_cookies', 1);
add_action('template_redirect', 'nemu_capture_utms_into_cookies', 1);

function nemu_add_utms_to_order($order, $data) {
    $log = nemu_utms_logger();
    $keys = ['nemu_source','nemu_medium','nemu_campaign','nemu_term','nemu_content','nemu_landing','nemu_referrer'];

    foreach ($keys as $k) {
        if (isset($_COOKIE[$k]) && $_COOKIE[$k] !== '') {
            $val = sanitize_text_field($_COOKIE[$k]);
            $order->update_meta_data($k, $val);
        }
    }

    $log->info('NEMU UTMs attached to order', [
        'order_id' => $order->get_id(),
        'meta'     => array_map(function($k) use ($order) {
            return $order->get_meta($k, true);
        }, $keys),
    ]);
}
add_action('woocommerce_checkout_create_order', 'nemu_add_utms_to_order', 10, 2);

function nemu_add_utms_to_webhook_payload($order_data, $order) {
    $keys = ['nemu_source','nemu_medium','nemu_campaign','nemu_term','nemu_content','nemu_landing','nemu_referrer'];
    foreach ($keys as $k) {
        $v = $order->get_meta($k, true);
        if ($v !== '') $order_data[$k] = $v;
    }
    return $order_data;
}
add_filter('woocommerce_webhook_payload_order', 'nemu_add_utms_to_webhook_payload', 10, 2);
