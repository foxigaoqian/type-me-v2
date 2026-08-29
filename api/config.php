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
        $runtime = getenv($key);
        if ($runtime !== false) return (string)$runtime;
        if (isset($envVars[$key])) return (string)$envVars[$key];
        return '';
    };

    $dbDsn = trim($get('DB_DSN'));
    $isVercel = $get('VERCEL') === '1';
    $vercelEnv = trim($get('VERCEL_ENV'));
    $explicitPreview = $get('TYPE_ME_PREVIEW_MODE') === '1';
    $statelessPreview = $explicitPreview || ($isVercel && $vercelEnv === 'preview' && $dbDsn === '');
    $cardInlineResponse = $get('CARD_INLINE_RESPONSE') === '1' || $isVercel;

    $reservationTtl = (int)($get('ORDER_RESERVATION_TTL_MINUTES') ?: '30');
    if ($reservationTtl < 5 || $reservationTtl > 120) $reservationTtl = 30;

    $privateStorage = trim($get('PRIVATE_STORAGE_PATH'));
    if ($privateStorage === '') {
        $privateStorage = $isVercel
            ? '/tmp/type-me-v2-data'
            : dirname($root) . DIRECTORY_SEPARATOR . 'type-me-v2-private-storage';
    }

    $storageCards = $isVercel
        ? '/tmp/type-me-v2-cards'
        : $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cards';

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
        'wechat_pay_public_key_id' => $get('WECHAT_PAY_PUBLIC_KEY_ID'),
        'wechat_pay_public_key_path' => $get('WECHAT_PAY_PUBLIC_KEY_PATH'),
        'card_font_path' => $get('CARD_FONT_PATH'),
        'qr_api_url' => $get('QR_API_URL') ?: 'https://api.qrserver.com/v1/create-qr-code/',
        'app_base_url' => rtrim(trim($get('APP_BASE_URL')), '/'),
        'db_dsn' => $dbDsn,
        'db_user' => $get('DB_USER'),
        'db_pass' => $get('DB_PASS'),
        'order_reservation_ttl_minutes' => $reservationTtl,
        'private_storage_path' => $privateStorage,
        'private_key_path' => $root . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'apiclient_key.pem',
        'storage_orders' => $privateStorage . DIRECTORY_SEPARATOR . 'orders.json',
        'storage_share' => $privateStorage . DIRECTORY_SEPARATOR . 'share.json',
        'storage_cards' => $storageCards,
        'is_vercel' => $isVercel,
        'vercel_env' => $vercelEnv,
        'stateless_preview' => $statelessPreview,
        'card_inline_response' => $cardInlineResponse,
    ];
    return $config;
}

function publicBaseUrl(): string
{
    $configured = (string)(getConfig()['app_base_url'] ?? '');
    if ($configured !== '') {
        $parts = parse_url($configured);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('APP_BASE_URL 配置无效');
        }
        return rtrim($configured, '/');
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $host)) {
        throw new RuntimeException('无效站点域名');
    }

    $forwardedProto = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
    if (in_array($forwardedProto, ['http', 'https'], true)) {
        $scheme = $forwardedProto;
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } else {
        $scheme = !empty(getConfig()['is_vercel']) ? 'https' : 'http';
    }

    return $scheme . '://' . $host;
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
