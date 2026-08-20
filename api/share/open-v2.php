<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/analytics.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body=readJsonBody();
$shareId=trim((string)($body['share_id']??''));
if($shareId==='') jsonResponse(['code'=>-1,'message'=>'缺少share_id'],400);
appendNdjson(analyticsStoragePath('share-opens-v2.ndjson'),[
  'share_id'=>$shareId,'referrer_id'=>(string)($body['referrer_id']??''),
  'visitor_id'=>getCurrentUserId(),'session_id'=>(string)($body['session_id']??''),
  'source'=>(string)($body['source']??'share'),'opened_at'=>date('c')
]);
jsonResponse(['code'=>0]);
