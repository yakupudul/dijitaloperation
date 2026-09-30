<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.5.1: a small atomic lock in the options table (INSERT IGNORE on the unique option_name).
 * Works without an object cache; a lock older than its TTL is taken over.
 */
final class MoxDOP_Connector_Lock
{
    public static function acquire($name, $ttl)
    {
        global $wpdb;
        $now = time();
        $held = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($held !== null && (int) $held < $now - (int) $ttl) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $held));
        }
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now));

        return (int) $inserted === 1;
    }

    public static function release($name)
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $name));
        wp_cache_delete($name, 'options');
    }
}
