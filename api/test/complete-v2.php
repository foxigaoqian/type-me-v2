<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/personality.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();
$answers = $body['answers'] ?? [];
if (!is_array($answers)) jsonResponse(['code'=>-1,'message'=>'answers must be array'],400);
$tieChoice = isset($body['tie_choice']) ? trim((string)$body['tie_choice']) : null;
try {
    $uid = getCurrentUserId();
    $attemptId = trim((string)($body['attempt_id']??''));
    assertFileAttemptOwner($attemptId,$uid);
    $result = scoreAnswers(array_values($answers), $tieChoice ?: null);
    $row = [
      'event'=>'test_result','result_id'=>'res_'.bin2hex(random_bytes(10)),
      'attempt_id'=>$attemptId,'uid'=>$uid,
      'session_id'=>(string)($body['session_id']??''),'completed_at'=>date('c'),
      'source'=>(string)($body['source']??'direct'),'campaign'=>(string)($body['campaign']??''),
      'school_id'=>(string)($body['school_id']??''),'creator_id'=>(string)($body['creator_id']??''),
      'referrer_id'=>(string)($body['referrer_id']??''),'share_id'=>(string)($body['share_id']??''),
      'primary_personality'=>$result['primary']['key'],'secondary_personality'=>$result['secondary']['key'],
      'answers'=>array_values($answers),'scores'=>$result['scores']
    ];
    appendTestResult($row);
    $result['sample'] = personalitySample($result['primary']['key']);
    jsonResponse(['code'=>0] + $result);
} catch (Throwable $e) {
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],400);
}
