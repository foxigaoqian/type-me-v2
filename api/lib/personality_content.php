<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function personalityTypeId(array $definition): string
{
    $type = strtoupper((string)($definition['type'] ?? ''));
    if (preg_match('/TYPE\s*0?([1-8])/', $type, $match)) {
        return 'TYPE0' . $match[1];
    }
    return '';
}

function personalityDefaultContent(array $definition): array
{
    $meme = trim((string)($definition['core'] ?? ''));
    return [
        'name' => trim((string)($definition['cn'] ?? '')),
        'en' => trim((string)($definition['en'] ?? '')),
        'main_meme' => $meme,
        'identity_card_meme' => trim((string)($definition['identity_card_meme'] ?? $meme)),
        'friend_meme' => trim((string)($definition['friend_meme'] ?? $meme)),
        'tshirt_copy' => trim((string)($definition['tshirt_copy'] ?? $meme)),
        'share_copy' => trim((string)($definition['share_copy'] ?? $meme)),
        'description' => trim((string)($definition['description'] ?? '')),
        'metrics' => array_values((array)($definition['metrics'] ?? [])),
        'metric_bias' => array_values((array)($definition['metric_bias'] ?? [])),
        'skill' => trim((string)($definition['skill'] ?? '')),
        'weakness' => trim((string)($definition['weakness'] ?? '')),
        'accent' => trim((string)($definition['accent'] ?? '#f3ff38')),
    ];
}

function personalityRevisionContent(array $row): array
{
    $extra = json_decode((string)($row['content_json'] ?? ''), true);
    if (!is_array($extra)) $extra = [];
    return [
        'name' => trim((string)($row['revision_name'] ?? $row['name'] ?? '')),
        'en' => trim((string)($extra['en'] ?? '')),
        'main_meme' => trim((string)($row['main_meme'] ?? '')),
        'identity_card_meme' => trim((string)($row['identity_card_meme'] ?? '')),
        'friend_meme' => trim((string)($row['friend_meme'] ?? '')),
        'tshirt_copy' => trim((string)($row['tshirt_copy'] ?? '')),
        'share_copy' => trim((string)($row['share_copy'] ?? '')),
        'description' => trim((string)($extra['description'] ?? '')),
        'metrics' => array_values((array)($extra['metrics'] ?? [])),
        'metric_bias' => array_values((array)($extra['metric_bias'] ?? [])),
        'skill' => trim((string)($extra['skill'] ?? '')),
        'weakness' => trim((string)($extra['weakness'] ?? '')),
        'accent' => trim((string)($extra['accent'] ?? '')),
    ];
}

function personalityContentMerge(array $definition, array $row): array
{
    if (empty($row['active']) || empty($row['revision_id'])) return $definition;
    $defaults = personalityDefaultContent($definition);
    $content = personalityRevisionContent($row);
    foreach ($content as $key => $value) {
        if (($value === '' || $value === []) && array_key_exists($key, $defaults)) {
            $content[$key] = $defaults[$key];
        }
    }
    $definition['cn'] = $content['name'];
    $definition['en'] = $content['en'];
    $definition['core'] = $content['main_meme'];
    $definition['main_meme'] = $content['main_meme'];
    $definition['identity_card_meme'] = $content['identity_card_meme'];
    $definition['friend_meme'] = $content['friend_meme'];
    $definition['tshirt_copy'] = $content['tshirt_copy'];
    $definition['share_copy'] = $content['share_copy'];
    $definition['description'] = $content['description'];
    $definition['metrics'] = $content['metrics'];
    $definition['metric_bias'] = $content['metric_bias'];
    $definition['skill'] = $content['skill'];
    $definition['weakness'] = $content['weakness'];
    $definition['accent'] = $content['accent'];
    $definition['content_version'] = (int)($row['version'] ?? 0);
    $definition['content_source'] = 'database';
    return $definition;
}

function personalityContentRows(PDO $pdo): array
{
    return $pdo->query(
        'SELECT t.type_id,t.personality_key,t.name,t.active,t.published_revision_id,
                r.revision_id,r.version,r.status,r.name AS revision_name,r.main_meme,r.identity_card_meme,
                r.friend_meme,r.tshirt_copy,r.share_copy,r.content_json,r.published_at
         FROM personality_types t
         LEFT JOIN personality_content_revisions r ON r.revision_id=t.published_revision_id
         ORDER BY t.type_id'
    )->fetchAll() ?: [];
}

function personalityContentCachePath(): string
{
    return rtrim((string)getConfig()['private_storage_path'], DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'personality-content-cache.json';
}

function personalityContentWriteCache(array $rows): void
{
    if (!$rows) return;
    $path = personalityContentCachePath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return;
    $json = json_encode(['cached_at'=>date('c'),'types'=>$rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return;
    $temp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($temp, $json, LOCK_EX) === false) return;
    @chmod($temp, 0660);
    @rename($temp, $path);
}

function personalityContentReadCache(): array
{
    $path = personalityContentCachePath();
    if (!is_file($path) || !is_readable($path)) return [];
    $cached = json_decode((string)file_get_contents($path), true);
    return is_array($cached['types'] ?? null) ? $cached['types'] : [];
}

function resolvePersonalityContent(array $config): array
{
    $definitions = $config['personalities'] ?? [];
    if (!is_array($definitions)) return $config;
    $rows = [];
    try {
        $pdo = dbConnection();
        if ($pdo) {
            $rows = personalityContentRows($pdo);
            personalityContentWriteCache($rows);
        }
    } catch (Throwable $error) {
        error_log('[TYPE-ME content fallback][database] ' . $error->getMessage());
    }
    if (!$rows) $rows = personalityContentReadCache();
    $byKey = [];
    foreach ($rows as $row) $byKey[(string)($row['personality_key'] ?? '')] = $row;
    foreach ($definitions as $key => $definition) {
        if (!is_array($definition)) continue;
        $definitions[$key] = personalityContentMerge($definition, $byKey[$key] ?? []);
        if (!isset($definitions[$key]['content_source'])) {
            $definitions[$key]['content_source'] = 'default';
            $definitions[$key]['content_version'] = 0;
            $defaults = personalityDefaultContent($definition);
            foreach (['main_meme','identity_card_meme','friend_meme','tshirt_copy','share_copy'] as $field) {
                $definitions[$key][$field] = $defaults[$field];
            }
        }
    }
    $config['personalities'] = $definitions;
    return $config;
}

function personalityContentString(array $payload, string $field, int $max, bool $required = true): string
{
    $value = trim((string)($payload[$field] ?? ''));
    if ($required && $value === '') throw new InvalidArgumentException($field . ' 不能为空');
    if (mb_strlen($value, 'UTF-8') > $max) throw new InvalidArgumentException($field . ' 内容过长');
    return $value;
}

function validatePersonalityContent(array $payload): array
{
    $metrics = array_values((array)($payload['metrics'] ?? []));
    $biases = array_values((array)($payload['metric_bias'] ?? []));
    if (count($metrics) !== 4 || count($biases) !== 4) {
        throw new InvalidArgumentException('人格指标和指标偏移必须各有 4 项');
    }
    $metrics = array_map(static function ($value): string {
        $value = trim((string)$value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 40) throw new InvalidArgumentException('人格指标无效');
        return $value;
    }, $metrics);
    $biases = array_map(static function ($value): int {
        $value = (int)$value;
        if ($value < -50 || $value > 50) throw new InvalidArgumentException('指标偏移必须在 -50 到 50 之间');
        return $value;
    }, $biases);
    $accent = personalityContentString($payload, 'accent', 7);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) throw new InvalidArgumentException('强调色格式无效');
    return [
        'name' => personalityContentString($payload, 'name', 64),
        'en' => personalityContentString($payload, 'en', 80),
        'main_meme' => personalityContentString($payload, 'main_meme', 120),
        'identity_card_meme' => personalityContentString($payload, 'identity_card_meme', 120),
        'friend_meme' => personalityContentString($payload, 'friend_meme', 160),
        'tshirt_copy' => personalityContentString($payload, 'tshirt_copy', 120),
        'share_copy' => personalityContentString($payload, 'share_copy', 240),
        'description' => personalityContentString($payload, 'description', 1200),
        'metrics' => $metrics,
        'metric_bias' => $biases,
        'skill' => personalityContentString($payload, 'skill', 300),
        'weakness' => personalityContentString($payload, 'weakness', 300),
        'accent' => strtolower($accent),
    ];
}
