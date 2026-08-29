<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/identity_card.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();
$attemptId = trim((string)($body['attempt_id'] ?? ''));
$sessionId = trim((string)($body['session_id'] ?? ''));
$previewAnswers = $body['answers'] ?? [];
if ($attemptId === '') jsonResponse(['code'=>-1,'message'=>'attempt_id required'],400);

try {
    $uid = getCurrentUserId();
    $statelessPreview = !empty(getConfig()['stateless_preview']);
    $row = null;
    $result = null;

    try { $row = dbFindOwnedResult($attemptId,$uid); }
    catch(Throwable $e) { error_log('[TYPE-ME DB fallback][identity_card_result] '.$e->getMessage()); }
    if (!$row) $row = findOwnedTestResult($attemptId,$uid);

    if (!$row && $statelessPreview) {
        if (!is_array($previewAnswers)) throw new RuntimeException('preview answers invalid');
        $result = scoreAnswers(array_values($previewAnswers));
        $row = [
            'attempt_id'=>$attemptId,
            'uid'=>$uid,
            'answers'=>array_values($previewAnswers),
            'source'=>'preview',
            'campaign'=>'',
            'school_id'=>'',
            'creator_id'=>'',
            'referrer_id'=>'',
        ];
    }

    if (!$row) jsonResponse(['code'=>-1,'message'=>'未找到当前用户的测试结果'],404);

    if ($result === null) {
        $answers = $row['answers'] ?? [];
        if (!is_array($answers)) throw new RuntimeException('测试答案损坏');
        $result = scoreAnswers(array_values($answers));
    }
    $sample = personalitySample((string)$result['primary']['key']);

    $shareId = 'shr_' . bin2hex(random_bytes(10));
    $params = http_build_query(['source'=>'share','referrer_id'=>$uid,'share_id'=>$shareId]);
    $shareUrl = publicBaseUrl() . '/?' . $params;

    $shareRow = [
        'share_id'=>$shareId,
        'referrer_id'=>$uid,
        'session_id'=>$sessionId,
        'primary_personality'=>$result['primary']['key'],
        'secondary_personality'=>$result['secondary']['key'],
        'created_at'=>date('c'),
        'share_url'=>$shareUrl,
        'source'=>'identity_card',
    ];
    if ($statelessPreview) {
        try { appendNdjson(analyticsStoragePath('shares-v2.ndjson'),$shareRow); }
        catch(Throwable $e) { error_log('[TYPE-ME preview mirror][identity_card_share] '.$e->getMessage()); }
    } else {
        appendNdjson(analyticsStoragePath('shares-v2.ndjson'),$shareRow);
    }
    bestEffortDb(static fn() => dbPersistShare($shareRow), 'identity_card_share');

    $card = renderIdentityCard($result['primary'],$result['secondary'],$sample,$shareUrl);
    try {
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
    } catch (Throwable $e) {
        if (!$statelessPreview) throw $e;
        error_log('[TYPE-ME preview mirror][identity_card_event] '.$e->getMessage());
    }

    jsonResponse([
        'code'=>0,
        'card_url'=>$card['url'],
        'card_inline'=>(bool)($card['inline'] ?? false),
        'width'=>$card['width'],
        'height'=>$card['height'],
        'share_id'=>$shareId,
        'referrer_id'=>$uid,
        'share_url'=>$shareUrl,
        'preview_mode'=>$statelessPreview,
    ]);
} catch (Throwable $e) {
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],500);
}
