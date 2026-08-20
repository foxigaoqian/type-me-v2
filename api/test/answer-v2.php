<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/analytics.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();
$attemptId = trim((string)($body['attempt_id']??''));
$questionId = trim((string)($body['question_id']??''));
$answerIndex = isset($body['answer_index']) ? (int)$body['answer_index'] : -1;
if ($attemptId==='' || $questionId==='' || $answerIndex<0) jsonResponse(['code'=>-1,'message'=>'参数不完整'],400);
$row = ['attempt_id'=>$attemptId,'uid'=>getCurrentUserId(),'question_id'=>$questionId,'answer_index'=>$answerIndex,'answered_at'=>date('c')];
try {
    dbPersistAnswer($row);
    appendNdjson(analyticsStoragePath('test-answers.ndjson'),$row);
    jsonResponse(['code'=>0]);
} catch (Throwable $e) {
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],500);
}
