<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/analytics.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();
$uid = getCurrentUserId();
$shareId = 'shr_' . bin2hex(random_bytes(10));
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
if (!preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/',$host)) jsonResponse(['code'=>-1,'message'=>'无效站点域名'],400);
$params = http_build_query(['source'=>'share','referrer_id'=>$uid,'share_id'=>$shareId]);
$url = $scheme . '://' . $host . '/?' . $params;
$row = [
  'share_id'=>$shareId,'referrer_id'=>$uid,'session_id'=>(string)($body['session_id']??''),
  'primary_personality'=>(string)($body['primary_personality']??''),
  'secondary_personality'=>(string)($body['secondary_personality']??''),
  'created_at'=>date('c'),'share_url'=>$url,'source'=>'result'
];
try {
  appendNdjson(analyticsStoragePath('shares-v2.ndjson'),$row);
  bestEffortDb(static fn() => dbPersistShare($row), 'share_create');
  jsonResponse(['code'=>0,'share_id'=>$shareId,'referrer_id'=>$uid,'share_url'=>$url]);
} catch(Throwable $e){
  jsonResponse(['code'=>-1,'message'=>$e->getMessage()],500);
}
