<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function dbPersistEvent(array $row): void
{
    $pdo = dbConnection();
    if (!$pdo) return;
    $known = ['event_id','anonymous_user_id','user_id','session_id','event_name','timestamp','source','campaign','school_id','creator_id','referrer_id','share_id','primary_personality','secondary_personality','product_id','sku_id','order_id'];
    $meta = array_diff_key($row, array_flip($known));
    $stmt = $pdo->prepare('INSERT IGNORE INTO events (event_id,anonymous_user_id,user_id,session_id,event_name,source,campaign,school_id,creator_id,referrer_id,share_id,primary_personality,secondary_personality,product_id,sku_id,order_id,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $row['event_id'],$row['anonymous_user_id'] ?? '',$row['user_id'] ?? '',$row['session_id'] ?? '',$row['event_name'],$row['source'] ?? 'direct',$row['campaign'] ?? '',
        $row['school_id'] ?? '',$row['creator_id'] ?? '',$row['referrer_id'] ?? '',$row['share_id'] ?? '',$row['primary_personality'] ?? '',$row['secondary_personality'] ?? '',
        $row['product_id'] ?? '',$row['sku_id'] ?? '',$row['order_id'] ?? '',$meta ? json_encode($meta,JSON_UNESCAPED_UNICODE) : null
    ]);
}

function dbPersistAttempt(array $row): void
{
    $pdo = dbConnection(); if (!$pdo) return;
    $stmt = $pdo->prepare('INSERT INTO test_attempts (attempt_id,uid,session_id,source,campaign,school_id,creator_id,referrer_id,share_id) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$row['attempt_id'],$row['uid'],$row['session_id'] ?? '',$row['source'] ?? 'direct',$row['campaign'] ?? '',$row['school_id'] ?? '',$row['creator_id'] ?? '',$row['referrer_id'] ?? '',$row['share_id'] ?? '']);
}

function dbPersistAnswer(array $row): void
{
    $pdo = dbConnection(); if (!$pdo) return;
    $stmt = $pdo->prepare('INSERT INTO test_answers (attempt_id,question_id,answer_index) VALUES (?,?,?) ON DUPLICATE KEY UPDATE answer_index=VALUES(answer_index),answered_at=CURRENT_TIMESTAMP(3)');
    $stmt->execute([$row['attempt_id'],$row['question_id'],(int)$row['answer_index']]);
}

function dbPersistResult(array $row): void
{
    $pdo = dbConnection(); if (!$pdo) return;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE test_attempts SET completed_at=CURRENT_TIMESTAMP(3) WHERE attempt_id=? AND uid=?')->execute([$row['attempt_id'],$row['uid']]);
        $stmt = $pdo->prepare('INSERT INTO test_results (result_id,attempt_id,uid,primary_personality,secondary_personality,answers_json,scores_json) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE primary_personality=VALUES(primary_personality),secondary_personality=VALUES(secondary_personality),answers_json=VALUES(answers_json),scores_json=VALUES(scores_json),completed_at=CURRENT_TIMESTAMP(3)');
        $stmt->execute([$row['result_id'],$row['attempt_id'],$row['uid'],$row['primary_personality'],$row['secondary_personality'],json_encode($row['answers'] ?? [],JSON_UNESCAPED_UNICODE),json_encode($row['scores'] ?? [],JSON_UNESCAPED_UNICODE)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function dbPersistShare(array $row): void
{
    $pdo = dbConnection(); if (!$pdo) return;
    $stmt = $pdo->prepare('INSERT IGNORE INTO shares (share_id,referrer_id,session_id,primary_personality,secondary_personality,share_url,source) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$row['share_id'],$row['referrer_id'],$row['session_id'] ?? '',$row['primary_personality'] ?? '',$row['secondary_personality'] ?? '',$row['share_url'],$row['source'] ?? 'result']);
}

function dbPersonalitySample(string $key): ?array
{
    $pdo = dbConnection(); if (!$pdo) return null;
    $total = (int)$pdo->query('SELECT COUNT(*) FROM test_results')->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM test_results WHERE primary_personality=?');
    $stmt->execute([$key]);
    $count = (int)$stmt->fetchColumn();
    return ['total'=>$total,'personality_count'=>$count,'percent'=>$total>=100?round(($count/max(1,$total))*100,1):null];
}

function dbFindOwnedResult(string $attemptId, string $uid): ?array
{
    $pdo = dbConnection(); if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT attempt_id,uid,answers_json,scores_json,primary_personality,secondary_personality FROM test_results WHERE attempt_id=? AND uid=? LIMIT 1');
    $stmt->execute([$attemptId,$uid]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $answers = json_decode((string)$row['answers_json'],true);
    $scores = json_decode((string)$row['scores_json'],true);
    return ['event'=>'test_result','attempt_id'=>$row['attempt_id'],'uid'=>$row['uid'],'answers'=>is_array($answers)?$answers:[],'scores'=>is_array($scores)?$scores:[],'primary_personality'=>$row['primary_personality'],'secondary_personality'=>$row['secondary_personality']];
}
