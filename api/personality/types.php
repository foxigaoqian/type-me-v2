<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/personality.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'], 405);
}

header('Cache-Control: no-store, max-age=0');
$config = loadPersonalityConfig();
jsonResponse([
    'code' => 0,
    'version' => (string)($config['version'] ?? ''),
    'personalities' => $config['personalities'] ?? [],
]);
