<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$config = getConfig();
$fontCandidates = array_filter([
    trim((string)($config['card_font_path'] ?? '')),
    '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
    '/usr/share/fonts/opentype/noto/NotoSansCJKsc-Regular.otf',
    '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
]);
$fontReady = false;
foreach ($fontCandidates as $font) {
    if (is_file($font) && is_readable($font)) {
        $fontReady = true;
        break;
    }
}

$checks = [
    'curl' => extension_loaded('curl'),
    'openssl' => extension_loaded('openssl'),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'gd' => extension_loaded('gd'),
    'freetype' => function_exists('imagettftext'),
    'mbstring' => extension_loaded('mbstring'),
    'font' => $fontReady,
];
$runtimeReady = !in_array(false, $checks, true);

http_response_code($runtimeReady ? 200 : 503);
echo json_encode([
    'ok' => $runtimeReady,
    'service' => 'type-me-v2',
    'runtime' => !empty($config['is_vercel']) ? 'vercel-container' : 'php',
    'environment' => (string)($config['vercel_env'] ?? ''),
    'preview_mode' => (bool)($config['stateless_preview'] ?? false),
    'card_inline_response' => (bool)($config['card_inline_response'] ?? false),
    'php_version' => PHP_VERSION,
    'checks' => $checks,
    'database_configured' => trim((string)($config['db_dsn'] ?? '')) !== '',
    'wechat_configured' => trim((string)($config['mchid'] ?? '')) !== '' && trim((string)($config['appid'] ?? '')) !== '',
    'commit' => substr((string)(getenv('VERCEL_GIT_COMMIT_SHA') ?: ''), 0, 12),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
