<?php

declare(strict_types=1);

function loadEnv(string $envPath): array
{
    $vars = [];
    if (!is_file($envPath)) return $vars;
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || substr($line, 0, 1) === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $vars[trim($parts[0])] = trim($parts[1]);
    }
    return $vars;
}

function getConfig(): array
{
    static $config = null;
    if ($config !== null) return $config;
    $root = dirname(__DIR__);
    $envVars = loadEnv($root . DIRECTORY_SEPARATOR . '.env');
    $get = static function (string $key) use ($envVars): string {
        if (isset($_ENV[$key])) return (string)$_ENV[$key];
        if (isset($_SERVER[$key])) return (string)$_SERVER[$key];
        if (isset($envVars[$key])) return (string)$envVars[$key];
        return '';
    };
    $config = [
        'mchid' => $get('MCHID'),
        'appid' => $get('APPID'),
        'app_secret' => $get('APP_SECRET'),
        'api_v3_key' => $get('API_V3_KEY'),
        'serial_no' => $get('SERIAL_NO'),
        'notify_url' => $get('NOTIFY_URL'),
        'refund_notify_url' => $get('REFUND_NOTIFY_URL') ?: $get('NOTIFY_URL'),
        'admin_token' => $get('ADMIN_TOKEN'),
        'platform_cert_path' => $get('WECHAT_PLATFORM_CERT_PATH'),
        'card_font_path' => $get('CARD_FONT_PATH'),
        'qr_api_url' => $get('QR_API_URL') ?: 'https://api.qrserver.com/v1/create-qr-code/',
        'db_dsn' => $get('DB_DSN'),
        'db_user' => $get('DB_USER'),
        'db_pass' => $get('DB_PASS'),
        'private_key_path' => $root . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'apiclient_key.pem',
        'storage_orders' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'orders.json',
        'storage_share' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'share.json',
        'storage_cards' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cards',
    ];
    return $config;
}

function requireAdmin(): void
{
    $expected = getConfig()['admin_token'] ?? '';
    $provided = trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code'=>-1,'message'=>'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
