<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/personality.php';

requireAdmin();
header('Cache-Control: no-store, max-age=0');

$pdo = dbConnection();
if (!$pdo) jsonResponse(['code'=>-1,'message'=>'Database unavailable'], 503);

function personalityAdminRevision(array $row): array
{
    if (!$row) return [];
    return [
        'revision_id' => (int)$row['revision_id'],
        'version' => (int)$row['version'],
        'status' => (string)$row['status'],
        'content' => personalityRevisionContent($row),
        'created_by' => (string)$row['created_by'],
        'updated_by' => (string)$row['updated_by'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
        'published_at' => $row['published_at'] === null ? null : (string)$row['published_at'],
    ];
}

function personalityAdminSnapshot(PDO $pdo): array
{
    $types = $pdo->query(
        'SELECT type_id,personality_key,name,active,published_revision_id,created_at,updated_at
         FROM personality_types ORDER BY type_id'
    )->fetchAll() ?: [];
    $revisions = $pdo->query(
        'SELECT revision_id,type_id,version,status,name,main_meme,identity_card_meme,
                friend_meme,tshirt_copy,share_copy,content_json,created_by,updated_by,
                created_at,updated_at,published_at
         FROM personality_content_revisions ORDER BY type_id,version DESC'
    )->fetchAll() ?: [];
    $byType = [];
    foreach ($revisions as $revision) $byType[(string)$revision['type_id']][] = $revision;
    $definitions = loadV2Config()['personality_config']['personalities'] ?? [];
    foreach ($types as &$type) {
        $rows = $byType[(string)$type['type_id']] ?? [];
        $published = [];
        $draft = [];
        $history = [];
        foreach ($rows as $row) {
            if ((int)$row['revision_id'] === (int)$type['published_revision_id']) $published = personalityAdminRevision($row);
            if (!$draft && (string)$row['status'] === 'DRAFT') $draft = personalityAdminRevision($row);
            if (count($history) < 10) {
                $history[] = [
                    'revision_id'=>(int)$row['revision_id'],
                    'version'=>(int)$row['version'],
                    'status'=>(string)$row['status'],
                    'updated_at'=>(string)$row['updated_at'],
                    'published_at'=>$row['published_at'] === null ? null : (string)$row['published_at'],
                ];
            }
        }
        $key = (string)$type['personality_key'];
        $defaultContent = personalityDefaultContent((array)($definitions[$key] ?? []));
        $type['active'] = (int)$type['active'];
        $type['published_revision_id'] = $type['published_revision_id'] === null ? null : (int)$type['published_revision_id'];
        $type['published'] = $published;
        $type['draft'] = $draft;
        $type['default_content'] = $defaultContent;
        $type['history'] = $history;
    }
    unset($type);
    return $types;
}

function personalityAdminTypeId(array $body): string
{
    $typeId = strtoupper(trim((string)($body['type_id'] ?? '')));
    if (!preg_match('/^TYPE0[1-8]$/', $typeId)) throw new InvalidArgumentException('人格编号无效');
    return $typeId;
}

function personalityAdminJson(array $content): string
{
    $json = json_encode([
        'en'=>$content['en'],
        'description'=>$content['description'],
        'metrics'=>$content['metrics'],
        'metric_bias'=>$content['metric_bias'],
        'skill'=>$content['skill'],
        'weakness'=>$content['weakness'],
        'accent'=>$content['accent'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('人格内容编码失败');
    return $json;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse(['code'=>0,'types'=>personalityAdminSnapshot($pdo)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'], 405);
}

$body = readJsonBody();
$action = trim((string)($body['action'] ?? ''));

try {
    $typeId = personalityAdminTypeId($body);
    $pdo->beginTransaction();
    $typeStatement = $pdo->prepare('SELECT * FROM personality_types WHERE type_id=? FOR UPDATE');
    $typeStatement->execute([$typeId]);
    $type = $typeStatement->fetch();
    if (!$type) throw new InvalidArgumentException('人格不存在');

    if ($action === 'save_draft') {
        $content = validatePersonalityContent((array)($body['content'] ?? []));
        $draftStatement = $pdo->prepare(
            'SELECT revision_id,version FROM personality_content_revisions
             WHERE type_id=? AND status="DRAFT" ORDER BY version DESC LIMIT 1 FOR UPDATE'
        );
        $draftStatement->execute([$typeId]);
        $draft = $draftStatement->fetch();
        $values = [
            $content['name'],$content['main_meme'],$content['identity_card_meme'],$content['friend_meme'],
            $content['tshirt_copy'],$content['share_copy'],personalityAdminJson($content),'admin'
        ];
        if ($draft) {
            $statement = $pdo->prepare(
                'UPDATE personality_content_revisions SET name=?,main_meme=?,identity_card_meme=?,
                 friend_meme=?,tshirt_copy=?,share_copy=?,content_json=?,updated_by=? WHERE revision_id=?'
            );
            $statement->execute([...$values, (int)$draft['revision_id']]);
        } else {
            $versionStatement = $pdo->prepare('SELECT COALESCE(MAX(version),0)+1 FROM personality_content_revisions WHERE type_id=?');
            $versionStatement->execute([$typeId]);
            $version = (int)$versionStatement->fetchColumn();
            $statement = $pdo->prepare(
                'INSERT INTO personality_content_revisions
                 (type_id,version,status,name,main_meme,identity_card_meme,friend_meme,tshirt_copy,
                  share_copy,content_json,created_by,updated_by)
                 VALUES (?, ?, "DRAFT", ?, ?, ?, ?, ?, ?, ?, "admin", "admin")'
            );
            $statement->execute([$typeId,$version,...array_slice($values,0,7)]);
        }
    } elseif ($action === 'publish') {
        $revisionId = (int)($body['revision_id'] ?? 0);
        $statement = $pdo->prepare(
            'SELECT * FROM personality_content_revisions
             WHERE type_id=? AND status="DRAFT" AND (?=0 OR revision_id=?)
             ORDER BY version DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$typeId,$revisionId,$revisionId]);
        $draft = $statement->fetch();
        if (!$draft) throw new InvalidArgumentException('没有可发布的草稿');
        $pdo->prepare('UPDATE personality_content_revisions SET status="ARCHIVED" WHERE type_id=? AND status="PUBLISHED"')
            ->execute([$typeId]);
        $pdo->prepare(
            'UPDATE personality_content_revisions
             SET status="PUBLISHED",published_at=CURRENT_TIMESTAMP(3),updated_by="admin" WHERE revision_id=?'
        )->execute([(int)$draft['revision_id']]);
        $pdo->prepare(
            'UPDATE personality_types SET name=?,published_revision_id=? WHERE type_id=?'
        )->execute([(string)$draft['name'],(int)$draft['revision_id'],$typeId]);
    } elseif ($action === 'set_active') {
        $active = !empty($body['active']) ? 1 : 0;
        $pdo->prepare('UPDATE personality_types SET active=? WHERE type_id=?')->execute([$active,$typeId]);
    } else {
        throw new InvalidArgumentException('Unsupported action');
    }

    $pdo->commit();
    personalityContentWriteCache(personalityContentRows($pdo));
    jsonResponse(['code'=>0,'types'=>personalityAdminSnapshot($pdo)]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['code'=>-1,'message'=>$error->getMessage()], 400);
}
