<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/analytics.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();
$attemptId = 'att_' . bin2hex(random_bytes(10));
$row = [
    'attempt_id'=>$attemptId,
    'uid'=>getCurrentUserId(),
    'session_id'=>(string)($body['session_id']??''),
    'started_at'=>date('c'),
    'source'=>(string)($body['source']??'direct'),
    'campaign'=>(string)($body['campaign']??''),
    'school_id'=>(string)($body['school_id']??''),
    'creator_id'=>(string)($body['creator_id']??''),
    'referrer_id'=>(string)($body['referrer_id']??''),
    'share_id'=>(string)($body['share_id']??'')
];
try {
    appendNdjson(analyticsStoragePath('test-attempts.ndjson'), $row);
    bestEffortDb(static fn() => dbPersistAttempt($row), 'test_start');
    jsonResponse(['code'=>0,'attempt_id'=>$attemptId]);
} catch (Throwable $e) {
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],500);
}
