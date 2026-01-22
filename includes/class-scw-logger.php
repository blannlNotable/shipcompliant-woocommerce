<?php
namespace SCW;

if (!defined('ABSPATH')) exit;

class Logger {
    public static function enabled(): bool {
        $s = Utils::get_settings();
        return !empty($s['debug']);
    }

    public static function log(string $message, array $context = []): void {
        if (!self::enabled()) return;
        $line = '[SCW] ' . $message;
        if (!empty($context)) $line .= ' ' . wp_json_encode($context);
        error_log($line);
    }
}
