<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/identity_card.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();
$attemptId = trim((string)($body['attempt_id'] ?? ''));
$sessionId = trim((string)($body['session_id'] ?? ''));
if ($attemptId === '') jsonResponse(['code'=>-1,'message'=>'attempt_id required'],400);

try {
    $uid = getCurrentUserId();
    $row = findOwnedTestResult($attemptId, $uid);
    if (!$row) jsonResponse(['code'=>-1,'message'=>'未找到当前用户的测试结果'],404);

    $answers = $row['answers'] ?? [];
    if (!is_array($answers)) throw new RuntimeException('测试答案损坏');
    $result = scoreAnswers(array_values($answers));
    $sample = personalitySample((string)$result['primary']['key']);

    $shareId = 'shr_' . bin2hex(random_bytes(10));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') throw new RuntimeException('无法确定站点域名');
    $params = http_build_query(['source'=>'share','referrer_id'=>$uid,'share_id'=>$shareId]);
    $shareUrl = $scheme . '://' . $host . '/?' . $params;

    appendNdjson(analyticsStoragePath('shares-v2.ndjson'),[
        'share_id'=>$shareId,
        'referrer_id'=>$uid,
        'session_id'=>$sessionId,
        'primary_personality'=>$result['primary']['key'],
        'secondary_personality'=>$result['secondary']['key'],
        'created_at'=>date('c'),
        'share_url'=>$shareUrl,
        'source'=>'identity_card',
    ]);

    $card = renderIdentityCard($result['primary'],$result['secondary'],$sample,$shareUrl);
    trackEvent('identity_card_generate',[
        'session_id'=>$sessionId,
        'source'=>(string)($row['source'] ?? 'direct'),
        'campaign'=>(string)($row['campaign'] ?? ''),
        'school_id'=>(string)($row['school_id'] ?? ''),
        'creator_id'=>(string)($row['creator_id'] ?? ''),
        'referrer_id'=>(string)($row['referrer_id'] ?? ''),
        'share_id'=>$shareId,
        'primary_personality'=>$result['primary']['key'],
        'secondary_personality'=>$result['secondary']['key'],
        'attempt_id'=>$attemptId,
    ]);

    jsonResponse([
        'code'=>0,
        'card_url'=>$card['url'],
        'width'=>$card['width'],
        'height'=>$card['height'],
        'share_id'=>$shareId,
        'referrer_id'=>$uid,
        'share_url'=>$shareUrl,
    ]);
} catch (Throwable $e) {
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],500);
}
