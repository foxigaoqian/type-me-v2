<?php
declare(strict_types=1);

require_once __DIR__ . '/wechat_pay.php';
require_once __DIR__ . '/db_analytics.php';

function analyticsStoragePath(string $name): string
{
    return rtrim((string)getConfig()['private_storage_path'], '/\\') . DIRECTORY_SEPARATOR . $name;
}

function appendNdjson(string $file, array $row): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建私有数据目录');
    }
    $line = json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('运行数据写入失败');
    }
}

function readNdjson(string $file): array
{
    if (!is_file($file)) return [];
    $rows = [];
    $fh = fopen($file, 'rb');
    if (!$fh) return [];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $row = json_decode($line, true);
        if (is_array($row)) $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function bestEffortDb(callable $operation, string $context): void
{
    try {
        $operation();
    } catch (Throwable $e) {
        error_log('[TYPE-ME DB fallback][' . $context . '] ' . $e->getMessage());
    }
}

function assertFileAttemptOwner(string $attemptId, string $uid): void
{
    if ($attemptId === '') throw new RuntimeException('attempt_id required');
    $rows = readNdjson(analyticsStoragePath('test-attempts.ndjson'));
    for ($i = count($rows)-1; $i >= 0; $i--) {
        $row = $rows[$i];
        if ((string)($row['attempt_id'] ?? '') !== $attemptId) continue;
        if (!hash_equals((string)($row['uid'] ?? ''), $uid)) throw new RuntimeException('测试 attempt 不属于当前用户');
        return;
    }
    throw new RuntimeException('测试 attempt 不存在');
}

function cleanEventField($value): string
{
    return trim((string)($value ?? ''));
}

function trackEvent(string $eventName, array $payload = []): array
{
    $allowed = [
        'landing_view','test_start','question_view','question_answer','test_complete','result_view',
        'identity_card_generate','identity_card_save','share_click','share_open','viral_test_start',
        'viral_test_complete','product_view','color_select','size_select','add_cart','checkout_start',
        'payment_success','refund'
    ];
    if (!in_array($eventName, $allowed, true)) throw new InvalidArgumentException('Unsupported event_name');

    $row = [
        'event_id' => 'evt_' . bin2hex(random_bytes(10)),
        'anonymous_user_id' => getCurrentUserId(),
        'user_id' => cleanEventField($payload['user_id'] ?? ''),
        'session_id' => cleanEventField($payload['session_id'] ?? ''),
        'event_name' => $eventName,
        'timestamp' => date('c'),
        'source' => cleanEventField($payload['source'] ?? 'direct') ?: 'direct',
        'campaign' => cleanEventField($payload['campaign'] ?? ''),
        'school_id' => cleanEventField($payload['school_id'] ?? ''),
        'creator_id' => cleanEventField($payload['creator_id'] ?? ''),
        'referrer_id' => cleanEventField($payload['referrer_id'] ?? ''),
        'share_id' => cleanEventField($payload['share_id'] ?? ''),
        'primary_personality' => cleanEventField($payload['primary_personality'] ?? ''),
        'secondary_personality' => cleanEventField($payload['secondary_personality'] ?? ''),
        'product_id' => cleanEventField($payload['product_id'] ?? ''),
        'sku_id' => cleanEventField($payload['sku_id'] ?? ''),
        'order_id' => cleanEventField($payload['order_id'] ?? ''),
    ];
    foreach (['attempt_id','question_id','question_index','answer_index','color','size'] as $extra) {
        if (array_key_exists($extra, $payload)) $row[$extra] = $payload[$extra];
    }

    appendNdjson(analyticsStoragePath('events.ndjson'), $row);
    bestEffortDb(static fn() => dbPersistEvent($row), 'event:' . $eventName);
    return $row;
}
