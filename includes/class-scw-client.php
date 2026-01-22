<?php
namespace SCW;

if (!defined('ABSPATH')) exit;

class Client {
    private string $base_url;
    private string $username;
    private string $password;

    public function __construct() {
        $s = Utils::get_settings();
        $this->base_url = Utils::base_url();
        $this->username = (string)($s['username'] ?? '');
        $this->password = (string)($s['password'] ?? '');
    }

    public function can_auth(): bool {
        return $this->base_url !== '' && $this->username !== '' && $this->password !== '';
    }

    public function request(string $method, string $path, ?array $body = null): array {
        $url = $this->base_url . '/' . ltrim($path, '/');

        $args = [
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                // DO NOT log the real Authorization header
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        // --------- LOG: request (safe) ----------
        Logger::log('SC Request', [
            'method' => $args['method'],
            'url' => $url,
            'headers' => [
                'Accept' => $args['headers']['Accept'],
                'Content-Type' => $args['headers']['Content-Type'],
                'Authorization' => 'Basic ***',
            ],
            'body' => $body, // payload as array
        ]);

        $res = wp_remote_request($url, $args);

        if (is_wp_error($res)) {
            $err = $res->get_error_message();
            Logger::log('SC WP_Error', ['url' => $url, 'error' => $err]);

            return [
                'ok' => false,
                'status' => 0,
                'error' => $err,
                'data' => null,
                'raw' => null,
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($res);
        $raw = (string) wp_remote_retrieve_body($res);

        $json = null;
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            $json = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
        }

        $ok = ($status >= 200 && $status < 300);

        // --------- LOG: response ----------
        Logger::log('SC Response', [
            'url' => $url,
            'status' => $status,
            'raw' => $raw,
            'json' => $json,
        ]);

        // Build a useful error message (fix blank errors)
        $error = null;
        if (!$ok) {
            $msg = null;

            if (is_array($json)) {
                $msg = $json['message'] ?? $json['error'] ?? $json['title'] ?? null;
            }

            if (!$msg && is_string($raw) && trim($raw) !== '') {
                $msg = trim($raw);
            }

            if (!$msg) {
                $msg = 'HTTP ' . $status . ' (empty response body)';
            }

            $error = $msg;

            Logger::log('SC Non-2xx', [
                'status' => $status,
                'error' => $error,
            ]);
        }

        return [
            'ok' => $ok,
            'status' => $status,
            'error' => $error,
            'data' => $json,
            'raw' => $raw, // helpful for debugging
        ];
    }
}
