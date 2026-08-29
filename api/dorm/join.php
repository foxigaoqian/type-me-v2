<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/dorm.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();

try {
    $uid = getCurrentUserId();
    $code = dormValidateCode((string)($body['invite_code'] ?? ''));
    $result = dormOwnedResult(
        trim((string)($body['attempt_id'] ?? '')),
        $uid,
        trim((string)($body['primary_personality'] ?? '')),
        trim((string)($body['secondary_personality'] ?? ''))
    );
    $before = dormStatus($code);
    $beforeCount = count($before['members'] ?? []);
    $snapshot = dormJoin($code,$uid,$result);
    $payload = dormPublicPayload($snapshot,$uid);
    $afterCount = (int)$payload['member_count'];
    try {
        // 只有真正新增槽位时才记录 join，刷新/重复提交保持业务和统计双重幂等。
        if ($afterCount > $beforeCount) {
            trackEvent('dorm_join',['dorm_id'=>$payload['dorm_id'],'dorm_code'=>$payload['invite_code'],'dorm_member_count'=>$afterCount]);
        }
        if ($beforeCount < 4 && $afterCount === 4 && $payload['status'] === 'COMPLETE') {
            trackEvent('dorm_complete',['dorm_id'=>$payload['dorm_id'],'dorm_code'=>$payload['invite_code'],'dorm_member_count'=>4]);
        }
    } catch (Throwable $e) {}
    jsonResponse(['code'=>0,'dorm'=>$payload]);
} catch (Throwable $e) {
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],400);
}
