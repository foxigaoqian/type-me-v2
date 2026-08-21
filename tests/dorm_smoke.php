<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/dorm.php';

function expectTrue(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function fakeResult(string $attempt, string $primary, string $secondary): array {
    return ['attempt_id'=>$attempt,'primary_personality'=>$primary,'secondary_personality'=>$secondary];
}

$pdo=dbConnection();
expectTrue($pdo instanceof PDO,'DB connection required');
$pdo->exec('DELETE FROM dorm_members');
$pdo->exec('DELETE FROM dorms');

$dorm=dormDbCreate('u_dorm_1',fakeResult('a1','periodic','awake'),'404 宿舍');
expectTrue(($dorm['dorm']['status']??'')==='OPEN','new dorm must be OPEN');
expectTrue(count($dorm['members']??[])===1,'creator must occupy one slot');
expectTrue(($dorm['report']??null)===null,'report must stay locked at 1/4');
$code=(string)$dorm['dorm']['invite_code'];

$dorm=dormDbJoin($code,'u_dorm_2',fakeResult('a2','night','zuiying'));
expectTrue(count($dorm['members'])===2,'second member join failed');
expectTrue($dorm['report']===null,'report must stay locked at 2/4');

$dorm=dormDbJoin($code,'u_dorm_3',fakeResult('a3','crazy','suiyuan'));
expectTrue(count($dorm['members'])===3,'third member join failed');
expectTrue($dorm['report']===null,'report must stay locked at 3/4');

$dorm=dormDbJoin($code,'u_dorm_4',fakeResult('a4','boundary','rebellious'));
expectTrue(count($dorm['members'])===4,'fourth member join failed');
expectTrue(($dorm['dorm']['status']??'')==='COMPLETE','dorm must complete at 4/4');
expectTrue(is_array($dorm['report']??null),'report missing at 4/4');
expectTrue(($dorm['report']['version']??'')==='dorm-report-v1','unexpected dorm report version');
expectTrue(count($dorm['report']['metrics']??[])===4,'dorm report must contain four metrics');
expectTrue(count($dorm['report']['roles']??[])===4,'dorm report must contain four member roles');

$idempotent=dormDbJoin($code,'u_dorm_4',fakeResult('a4','boundary','rebellious'));
expectTrue(count($idempotent['members'])===4,'repeat join must be idempotent');

$blocked=false;
try { dormDbJoin($code,'u_dorm_5',fakeResult('a5','awake','periodic')); }
catch (Throwable $e) { $blocked=true; }
expectTrue($blocked,'fifth member must be rejected after 4/4');

echo "Dorm MVP smoke test passed: 1/4 -> 4/4 -> report unlock -> fifth member blocked\n";
