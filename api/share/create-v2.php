<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/analytics.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);

$body = readJsonBody();
$uid = getCurrentUserId();
$shareId = 'shr_' . bin2hex(random_bytes(10));
$seedId = analyticsDimension($body['seed_id'] ?? '', 64);
$campaign = analyticsDimension($body['campaign'] ?? '', 128);
$schoolId = analyticsDimension($body['school_id'] ?? '', 128);
$source = analyticsDimension($body['source'] ?? 'result', 64) ?: 'result';
$generation = max(0, min(20, (int)($body['seed_generation'] ?? 0))) + 1;
$params = array_filter([
  'source'=>'share','campaign'=>$campaign,'school_id'=>$schoolId,'seed_id'=>$seedId,
  'seed_generation'=>$seedId !== '' ? $generation : null,'referrer_id'=>$uid,'share_id'=>$shareId
], static fn($value): bool => $value !== '' && $value !== null);
$params = http_build_query($params);
$url = publicBaseUrl() . '/?' . $params;
$row = [
  'share_id'=>$shareId,'referrer_id'=>$uid,'session_id'=>(string)($body['session_id']??''),
  'primary_personality'=>(string)($body['primary_personality']??''),
  'secondary_personality'=>(string)($body['secondary_personality']??''),
  'created_at'=>date('c'),'share_url'=>$url,'source'=>$source,'campaign'=>$campaign,'school_id'=>$schoolId,
  'seed_id'=>$seedId,'seed_generation'=>$seedId !== '' ? max(0,$generation-1) : 0
];

try {
  if (!empty(getConfig()['stateless_preview'])) {
    try { appendNdjson(analyticsStoragePath('shares-v2.ndjson'),$row); }
    catch(Throwable $e) { error_log('[TYPE-ME preview mirror][share_create] '.$e->getMessage()); }
  } else {
    appendNdjson(analyticsStoragePath('shares-v2.ndjson'),$row);
  }
  bestEffortDb(static fn() => dbPersistShare($row), 'share_create');
  jsonResponse(['code'=>0,'share_id'=>$shareId,'referrer_id'=>$uid,'share_url'=>$url,'preview_mode'=>(bool)getConfig()['stateless_preview']]);
} catch(Throwable $e){
  jsonResponse(['code'=>-1,'message'=>$e->getMessage()],500);
}
