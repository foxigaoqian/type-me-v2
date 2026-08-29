<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/dorm.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();

try {
    $uid = getCurrentUserId();
    $result = dormOwnedResult(
        trim((string)($body['attempt_id'] ?? '')),
        $uid,
        trim((string)($body['primary_personality'] ?? '')),
        trim((string)($body['secondary_personality'] ?? ''))
    );
    $snapshot = dormCreate($uid,$result,(string)($body['name'] ?? ''));
    $payload = dormPublicPayload($snapshot,$uid);
    try { trackEvent('dorm_create',['dorm_id'=>$payload['dorm_id'],'dorm_code'=>$payload['invite_code'],'dorm_member_count'=>$payload['member_count']]); } catch (Throwable $e) {}
    jsonResponse(['code'=>0,'dorm'=>$payload]);
} catch (Throwable $e) {
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],400);
}
