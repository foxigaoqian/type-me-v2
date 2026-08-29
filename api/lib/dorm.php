<?php

declare(strict_types=1);

require_once __DIR__ . '/personality.php';

function dormPreviewPath(): string
{
    return rtrim((string)getConfig()['private_storage_path'], '/\\') . DIRECTORY_SEPARATOR . 'dorms-preview.json';
}

function dormCleanName(string $name): string
{
    $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
    if ($name === '') return '我的宿舍';
    if (!extension_loaded('mbstring')) return substr($name, 0, 48);
    return mb_substr($name, 0, 16, 'UTF-8');
}

function dormInviteCode(): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < 8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $code;
}

function dormValidateCode(string $code): string
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{8}$/', $code)) {
        throw new InvalidArgumentException('宿舍邀请码无效');
    }
    return $code;
}

function dormValidatePersonalityKey(string $key): string
{
    $defs = loadPersonalityConfig()['personalities'] ?? [];
    if (!isset($defs[$key])) throw new InvalidArgumentException('人格类型无效');
    return $key;
}

function dormOwnedResult(string $attemptId, string $uid, string $previewPrimary = '', string $previewSecondary = ''): array
{
    $attemptId = trim($attemptId);
    if (dbEnabled()) {
        if ($attemptId === '') throw new InvalidArgumentException('attempt_id required');
        $row = dbFindOwnedResult($attemptId, $uid);
        if (!$row) throw new RuntimeException('未找到当前用户已完成的测试结果');
        return $row;
    }

    if (empty(getConfig()['stateless_preview'])) {
        throw new RuntimeException('宿舍挑战需要持久数据库');
    }

    if ($attemptId !== '') {
        try {
            $row = findOwnedTestResult($attemptId, $uid);
            if ($row) return $row;
        } catch (Throwable $e) {
            // Vercel Preview 可能跨容器实例；仅 Preview 允许使用本次前端已计算结果回退。
        }
    }

    $previewPrimary = dormValidatePersonalityKey(trim($previewPrimary));
    $previewSecondary = dormValidatePersonalityKey(trim($previewSecondary));
    if ($previewPrimary === $previewSecondary) throw new InvalidArgumentException('主人格与隐藏人格不能相同');
    return [
        'attempt_id' => $attemptId !== '' ? $attemptId : 'preview_' . bin2hex(random_bytes(8)),
        'uid' => $uid,
        'primary_personality' => $previewPrimary,
        'secondary_personality' => $previewSecondary,
    ];
}

function dormRoleFor(string $key): string
{
    return [
        'periodic' => '最后一分钟奇迹担当',
        'suiyuan' => '宿舍气氛缓冲器',
        'zuiying' => '嘴硬调度员',
        'night' => '深夜值班员',
        'boundary' => '结界管理员',
        'rebellious' => '反骨发言人',
        'crazy' => '发疯发动机',
        'awake' => '人间清醒观察员',
    ][$key] ?? '神秘室友';
}

function dormBuildReport(array $members): array
{
    usort($members, static fn(array $a, array $b): int => ((int)$a['slot_no']) <=> ((int)$b['slot_no']));
    if (count($members) !== 4) throw new RuntimeException('必须 4 位室友都完成测试后才能生成宿舍报告');

    $factors = [
        'periodic'  => ['night'=>58,'chaos'=>46,'friction'=>44,'stability'=>66],
        'suiyuan'   => ['night'=>42,'chaos'=>64,'friction'=>24,'stability'=>55],
        'zuiying'   => ['night'=>54,'chaos'=>52,'friction'=>64,'stability'=>48],
        'night'     => ['night'=>96,'chaos'=>58,'friction'=>34,'stability'=>46],
        'boundary'  => ['night'=>28,'chaos'=>18,'friction'=>66,'stability'=>82],
        'rebellious'=> ['night'=>62,'chaos'=>82,'friction'=>79,'stability'=>34],
        'crazy'     => ['night'=>78,'chaos'=>97,'friction'=>72,'stability'=>22],
        'awake'     => ['night'=>34,'chaos'=>16,'friction'=>30,'stability'=>91],
    ];

    $totals = ['night'=>0,'chaos'=>0,'friction'=>0,'stability'=>0];
    $counts = [];
    $roles = [];
    foreach ($members as $member) {
        $key = dormValidatePersonalityKey((string)$member['primary_personality']);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        foreach ($totals as $metric => $_) $totals[$metric] += (int)($factors[$key][$metric] ?? 50);
        $roles[] = ['slot_no'=>(int)$member['slot_no'],'personality'=>$key,'role'=>dormRoleFor($key)];
    }
    foreach ($totals as $metric => $value) $totals[$metric] = (int)round($value / 4);

    arsort($counts);
    $topKeys = array_keys($counts);
    $duplicate = max($counts) >= 2;
    if ($totals['chaos'] >= 75) {
        $title = '看起来还能住，实际全靠命硬';
        $core = '本宿舍的稳定主要来自：事情已经发生了，大家只能接受。';
    } elseif ($totals['night'] >= 70) {
        $title = '白天安静，凌晨统一上线';
        $core = '真正的宿舍会议通常发生在应该睡觉以后。';
    } elseif ($totals['friction'] >= 65) {
        $title = '本宿舍边界感比门锁还强';
        $core = '关系可以很好，但谁动了谁的充电线必须说清楚。';
    } elseif ($totals['stability'] >= 72) {
        $title = '少见：这个宿舍居然有一点秩序';
        $core = '有人清醒，有人收场，所以离谱通常还能被控制在宿舍内部。';
    } elseif ($duplicate) {
        $title = '同类浓度过高，请谨慎投喂';
        $core = '当两个以上相似物种住在一起，很多事已经不需要解释。';
    } else {
        $title = '表面稳定，内部各有各的离谱';
        $core = '四种校园物种共享一个空间，能和平生活本身就是一种能力。';
    }

    return [
        'version' => 'dorm-report-v1',
        'title' => $title,
        'core' => $core,
        'metrics' => [
            ['key'=>'night','name'=>'夜行动物浓度','value'=>$totals['night']],
            ['key'=>'chaos','name'=>'发疯预警值','value'=>$totals['chaos']],
            ['key'=>'friction','name'=>'边界冲突风险','value'=>$totals['friction']],
            ['key'=>'stability','name'=>'宿舍续航稳定度','value'=>$totals['stability']],
        ],
        'roles' => $roles,
        'composition' => $counts,
        'dominant' => array_slice($topKeys, 0, 2),
        'notice' => '娱乐化宿舍报告，不是心理测量或医学结论。',
    ];
}

function dormInviteUrl(string $code): string
{
    return publicBaseUrl() . '/?source=dorm&dorm=' . rawurlencode($code);
}

function dormDbSnapshotByCode(string $code): ?array
{
    $pdo = dbConnection();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT * FROM dorms WHERE invite_code=? LIMIT 1');
    $stmt->execute([$code]);
    $dorm = $stmt->fetch();
    if (!$dorm) return null;
    $membersStmt = $pdo->prepare('SELECT uid,slot_no,status,attempt_id,primary_personality,secondary_personality,joined_at,completed_at FROM dorm_members WHERE dorm_id=? ORDER BY slot_no ASC');
    $membersStmt->execute([$dorm['dorm_id']]);
    $members = $membersStmt->fetchAll() ?: [];
    $report = $dorm['report_json'] ? json_decode((string)$dorm['report_json'], true) : null;
    return ['dorm'=>$dorm,'members'=>$members,'report'=>is_array($report)?$report:null,'preview_ephemeral'=>false];
}

function dormDbCreate(string $uid, array $result, string $name): array
{
    $pdo = dbConnection();
    if (!$pdo) throw new RuntimeException('数据库未配置');
    $dormId = 'dorm_' . bin2hex(random_bytes(10));
    $name = dormCleanName($name);

    $pdo->beginTransaction();
    try {
        $code = '';
        for ($try = 0; $try < 8; $try++) {
            $candidate = dormInviteCode();
            $check = $pdo->prepare('SELECT 1 FROM dorms WHERE invite_code=?');
            $check->execute([$candidate]);
            if (!$check->fetchColumn()) { $code = $candidate; break; }
        }
        if ($code === '') throw new RuntimeException('生成宿舍邀请码失败');

        $pdo->prepare('INSERT INTO dorms (dorm_id,invite_code,creator_uid,name,status,member_limit) VALUES (?,?,?,?,"OPEN",4)')
            ->execute([$dormId,$code,$uid,$name]);
        $pdo->prepare('INSERT INTO dorm_members (dorm_id,uid,slot_no,status,attempt_id,primary_personality,secondary_personality) VALUES (?,?,1,"COMPLETE",?,?,?)')
            ->execute([$dormId,$uid,(string)$result['attempt_id'],(string)$result['primary_personality'],(string)$result['secondary_personality']]);
        $pdo->commit();
        return dormDbSnapshotByCode($code) ?? throw new RuntimeException('宿舍创建后读取失败');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function dormDbJoin(string $code, string $uid, array $result): array
{
    $pdo = dbConnection();
    if (!$pdo) throw new RuntimeException('数据库未配置');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM dorms WHERE invite_code=? FOR UPDATE');
        $stmt->execute([$code]);
        $dorm = $stmt->fetch();
        if (!$dorm) throw new RuntimeException('宿舍不存在');

        $existing = $pdo->prepare('SELECT id,slot_no FROM dorm_members WHERE dorm_id=? AND uid=? LIMIT 1');
        $existing->execute([$dorm['dorm_id'],$uid]);
        $member = $existing->fetch();
        if ($member) {
            if ((string)$dorm['status'] !== 'COMPLETE') {
                $pdo->prepare('UPDATE dorm_members SET status="COMPLETE",attempt_id=?,primary_personality=?,secondary_personality=?,completed_at=CURRENT_TIMESTAMP(3) WHERE id=?')
                    ->execute([(string)$result['attempt_id'],(string)$result['primary_personality'],(string)$result['secondary_personality'],$member['id']]);
            }
        } else {
            if ((string)$dorm['status'] === 'COMPLETE') throw new RuntimeException('这个宿舍已经满员并生成报告');
            $slotsStmt = $pdo->prepare('SELECT slot_no FROM dorm_members WHERE dorm_id=? ORDER BY slot_no');
            $slotsStmt->execute([$dorm['dorm_id']]);
            $used = array_map('intval', array_column($slotsStmt->fetchAll() ?: [], 'slot_no'));
            $slot = null;
            for ($i=1;$i<=4;$i++) if (!in_array($i,$used,true)) { $slot=$i; break; }
            if ($slot === null) throw new RuntimeException('宿舍已经满员');
            $pdo->prepare('INSERT INTO dorm_members (dorm_id,uid,slot_no,status,attempt_id,primary_personality,secondary_personality) VALUES (?,?,?,"COMPLETE",?,?,?)')
                ->execute([$dorm['dorm_id'],$uid,$slot,(string)$result['attempt_id'],(string)$result['primary_personality'],(string)$result['secondary_personality']]);
        }

        $membersStmt = $pdo->prepare('SELECT uid,slot_no,status,attempt_id,primary_personality,secondary_personality FROM dorm_members WHERE dorm_id=? ORDER BY slot_no');
        $membersStmt->execute([$dorm['dorm_id']]);
        $members = $membersStmt->fetchAll() ?: [];
        if (count($members) === 4 && (string)$dorm['status'] !== 'COMPLETE') {
            $report = dormBuildReport($members);
            $pdo->prepare('UPDATE dorms SET status="COMPLETE",report_json=?,completed_at=CURRENT_TIMESTAMP(3) WHERE dorm_id=?')
                ->execute([json_encode($report,JSON_UNESCAPED_UNICODE),$dorm['dorm_id']]);
        }
        $pdo->commit();
        return dormDbSnapshotByCode($code) ?? throw new RuntimeException('读取宿舍失败');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function dormPreviewMutate(callable $callback): array
{
    $file = dormPreviewPath();
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('无法创建 Preview 宿舍目录');
    $fh = fopen($file, 'c+');
    if (!$fh) throw new RuntimeException('无法打开 Preview 宿舍存储');
    try {
        if (!flock($fh, LOCK_EX)) throw new RuntimeException('无法锁定 Preview 宿舍存储');
        rewind($fh);
        $raw = stream_get_contents($fh);
        $store = $raw ? json_decode($raw, true) : [];
        if (!is_array($store)) $store = [];
        $result = $callback($store);
        rewind($fh); ftruncate($fh,0);
        fwrite($fh,json_encode($store,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        fflush($fh); flock($fh,LOCK_UN);
        return $result;
    } finally {
        fclose($fh);
    }
}

function dormPreviewCreate(string $uid, array $result, string $name): array
{
    return dormPreviewMutate(static function(array &$store) use ($uid,$result,$name): array {
        do { $code = dormInviteCode(); } while (isset($store[$code]));
        $store[$code] = [
            'dorm'=>['dorm_id'=>'dorm_'.bin2hex(random_bytes(8)),'invite_code'=>$code,'creator_uid'=>$uid,'name'=>dormCleanName($name),'status'=>'OPEN','member_limit'=>4,'created_at'=>date('c'),'completed_at'=>null],
            'members'=>[ ['uid'=>$uid,'slot_no'=>1,'status'=>'COMPLETE','attempt_id'=>$result['attempt_id'],'primary_personality'=>$result['primary_personality'],'secondary_personality'=>$result['secondary_personality'],'joined_at'=>date('c'),'completed_at'=>date('c')] ],
            'report'=>null,
        ];
        return $store[$code] + ['preview_ephemeral'=>true];
    });
}

function dormPreviewJoin(string $code, string $uid, array $result): array
{
    return dormPreviewMutate(static function(array &$store) use ($code,$uid,$result): array {
        if (!isset($store[$code])) throw new RuntimeException('Preview 宿舍不存在或临时数据已失效');
        $entry =& $store[$code];
        foreach ($entry['members'] as &$member) {
            if (($member['uid'] ?? '') === $uid) {
                if (($entry['dorm']['status'] ?? '') !== 'COMPLETE') {
                    $member['attempt_id']=$result['attempt_id'];
                    $member['primary_personality']=$result['primary_personality'];
                    $member['secondary_personality']=$result['secondary_personality'];
                    $member['status']='COMPLETE';
                    $member['completed_at']=date('c');
                }
                unset($member);
                return $entry + ['preview_ephemeral'=>true];
            }
        }
        unset($member);
        if (($entry['dorm']['status'] ?? '') === 'COMPLETE') throw new RuntimeException('这个宿舍已经满员并生成报告');
        $used = array_map('intval', array_column($entry['members'],'slot_no'));
        $slot=null; for($i=1;$i<=4;$i++) if(!in_array($i,$used,true)){ $slot=$i; break; }
        if($slot===null) throw new RuntimeException('宿舍已经满员');
        $entry['members'][]=['uid'=>$uid,'slot_no'=>$slot,'status'=>'COMPLETE','attempt_id'=>$result['attempt_id'],'primary_personality'=>$result['primary_personality'],'secondary_personality'=>$result['secondary_personality'],'joined_at'=>date('c'),'completed_at'=>date('c')];
        if(count($entry['members'])===4){
            $entry['report']=dormBuildReport($entry['members']);
            $entry['dorm']['status']='COMPLETE';
            $entry['dorm']['completed_at']=date('c');
        }
        return $entry + ['preview_ephemeral'=>true];
    });
}

function dormPreviewSnapshot(string $code): ?array
{
    $file = dormPreviewPath();
    if (!is_file($file)) return null;
    $store = json_decode((string)file_get_contents($file), true);
    if (!is_array($store) || !isset($store[$code])) return null;
    return $store[$code] + ['preview_ephemeral'=>true];
}

function dormCreate(string $uid, array $result, string $name): array
{
    if (dbEnabled()) return dormDbCreate($uid,$result,$name);
    if (!empty(getConfig()['stateless_preview'])) return dormPreviewCreate($uid,$result,$name);
    throw new RuntimeException('宿舍挑战需要持久数据库');
}

function dormJoin(string $code, string $uid, array $result): array
{
    $code = dormValidateCode($code);
    if (dbEnabled()) return dormDbJoin($code,$uid,$result);
    if (!empty(getConfig()['stateless_preview'])) return dormPreviewJoin($code,$uid,$result);
    throw new RuntimeException('宿舍挑战需要持久数据库');
}

function dormStatus(string $code): array
{
    $code = dormValidateCode($code);
    $snapshot = dbEnabled() ? dormDbSnapshotByCode($code) : (!empty(getConfig()['stateless_preview']) ? dormPreviewSnapshot($code) : null);
    if (!$snapshot) throw new RuntimeException('宿舍不存在或临时数据已失效');
    return $snapshot;
}

function dormPublicPayload(array $snapshot, string $currentUid = ''): array
{
    $dorm = $snapshot['dorm'];
    $defs = loadPersonalityConfig()['personalities'] ?? [];
    $members = [];
    foreach ($snapshot['members'] as $member) {
        $key = (string)($member['primary_personality'] ?? '');
        $p = $defs[$key] ?? [];
        $members[] = [
            'slot_no'=>(int)$member['slot_no'],
            'status'=>(string)$member['status'],
            'primary_personality'=>$key,
            'secondary_personality'=>(string)($member['secondary_personality'] ?? ''),
            'type'=>(string)($p['type'] ?? ''),
            'name'=>(string)($p['cn'] ?? ''),
            'role'=>dormRoleFor($key),
            'is_you'=>$currentUid !== '' && hash_equals((string)($member['uid'] ?? ''),$currentUid),
        ];
    }
    $count = count($members);
    return [
        'dorm_id'=>(string)$dorm['dorm_id'],
        'invite_code'=>(string)$dorm['invite_code'],
        'invite_url'=>dormInviteUrl((string)$dorm['invite_code']),
        'name'=>(string)$dorm['name'],
        'status'=>(string)$dorm['status'],
        'member_limit'=>(int)($dorm['member_limit'] ?? 4),
        'member_count'=>$count,
        'complete_count'=>count(array_filter($members,static fn($m)=>$m['status']==='COMPLETE')),
        'members'=>$members,
        'report'=>$snapshot['report'] ?? null,
        'preview_ephemeral'=>!empty($snapshot['preview_ephemeral']),
    ];
}
