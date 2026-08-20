<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/analytics.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();
$name = trim((string)($body['event_name'] ?? ''));
try {
    $row = trackEvent($name, $body);
    jsonResponse(['code'=>0,'event_id'=>$row['event_id']]);
} catch (Throwable $e) {
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],400);
}
