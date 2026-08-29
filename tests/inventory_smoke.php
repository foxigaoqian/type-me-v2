<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/db.php';

function failTest(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertSameValue(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        failTest($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$pdo = dbConnection();
if (!$pdo) failTest('DB connection is not enabled');

$paidSku = 'tee-01-黑-M';
$releaseSku = 'tee-01-白-M';
$pdo->exec('UPDATE skus SET stock_on_hand=0, stock_reserved=0');
$stmt = $pdo->prepare('UPDATE skus SET stock_on_hand=1, stock_reserved=0 WHERE sku_id IN (?,?)');
$stmt->execute([$paidSku, $releaseSku]);
if ($stmt->rowCount() !== 2) failTest('expected both fixture SKUs to exist');

$order = static function (string $outTradeNo, string $skuId): array {
    return [
        'out_trade_no' => $outTradeNo,
        'uid' => 'ci_user',
        'channel' => 'NATIVE',
        'amount' => 12900,
        'receiver_name' => 'CI User',
        'receiver_phone' => '00000000000',
        'receiver_address' => 'CI only',
        'quiz_result' => 'TYPE 01 间歇卷王',
        'primary_personality' => 'periodic',
        'items' => [[
            'product_id' => 'tee-01',
            'sku_id' => $skuId,
            'name' => 'TYPE 01 · 间歇卷王人格 T 恤',
            'unit_price_fen' => 12900,
            'qty' => 1,
        ]],
    ];
};

$skuState = static function (string $skuId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT stock_on_hand,stock_reserved FROM skus WHERE sku_id=?');
    $stmt->execute([$skuId]);
    $row = $stmt->fetch();
    if (!$row) failTest('missing SKU ' . $skuId);
    return [(int)$row['stock_on_hand'], (int)$row['stock_reserved']];
};

$orderStatus = static function (string $outTradeNo) use ($pdo): ?string {
    $stmt = $pdo->prepare('SELECT status FROM orders WHERE out_trade_no=?');
    $stmt->execute([$outTradeNo]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string)$value;
};

// 1) Reserve exactly one available SKU.
$paidOrder = 'CI_PAID_' . bin2hex(random_bytes(5));
dbCreateReservedOrder($order($paidOrder, $paidSku));
assertSameValue($skuState($paidSku), [1, 1], 'reserve keeps on-hand and increments reserved');
assertSameValue($orderStatus($paidOrder), 'PENDING_PAYMENT', 'reserved order status');

// 2) Finalize payment: reserved drops, on-hand drops, order becomes PAID.
$transactionId = 'WX_CI_' . bin2hex(random_bytes(5));
dbFinalizePaidOrder($paidOrder, $transactionId);
assertSameValue($skuState($paidSku), [0, 0], 'paid finalize consumes stock');
assertSameValue($orderStatus($paidOrder), 'PAID', 'paid order status');

// 3) Replaying the same successful payment must be idempotent.
dbFinalizePaidOrder($paidOrder, $transactionId);
assertSameValue($skuState($paidSku), [0, 0], 'replayed finalize is idempotent');
$eventStmt = $pdo->prepare('SELECT COUNT(*) FROM payment_events WHERE out_trade_no=? AND trade_state="SUCCESS"');
$eventStmt->execute([$paidOrder]);
assertSameValue((int)$eventStmt->fetchColumn(), 1, 'one payment event after replay');

// 4) A second order cannot oversell the zero-available SKU; insert must roll back too.
$oversellOrder = 'CI_OVER_' . bin2hex(random_bytes(5));
$oversellBlocked = false;
try {
    dbCreateReservedOrder($order($oversellOrder, $paidSku));
} catch (RuntimeException $e) {
    $oversellBlocked = str_contains($e->getMessage(), '库存不足');
}
if (!$oversellBlocked) failTest('oversell was not blocked');
assertSameValue($orderStatus($oversellOrder), null, 'oversell order transaction rolled back');
assertSameValue($skuState($paidSku), [0, 0], 'oversell does not mutate stock');

// 5) Releasing an unpaid reservation returns reserved stock without reducing on-hand.
$releaseOrder = 'CI_REL_' . bin2hex(random_bytes(5));
dbCreateReservedOrder($order($releaseOrder, $releaseSku));
assertSameValue($skuState($releaseSku), [1, 1], 'release fixture reserved');
dbReleaseReservation($releaseOrder, 'CLOSED');
assertSameValue($skuState($releaseSku), [1, 0], 'release restores availability');
assertSameValue($orderStatus($releaseOrder), 'CLOSED', 'released order status');

// Releasing again must not underflow reserved stock.
dbReleaseReservation($releaseOrder, 'CLOSED');
assertSameValue($skuState($releaseSku), [1, 0], 'replayed release is idempotent');

fwrite(STDOUT, "transactional inventory smoke passed\n");
