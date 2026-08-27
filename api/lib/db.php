<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

function dbConnection(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) return $pdo ?: null;
    $cfg = getConfig();
    $dsn = trim((string)($cfg['db_dsn'] ?? ''));
    if ($dsn === '') { $pdo = null; return null; }
    $pdo = new PDO($dsn, (string)($cfg['db_user'] ?? ''), (string)($cfg['db_pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function dbEnabled(): bool
{
    return dbConnection() instanceof PDO;
}

function dbCreateReservedOrder(array $order): void
{
    $pdo = dbConnection();
    if (!$pdo) return;
    $items = $order['items'] ?? [];
    if (!is_array($items) || count($items) < 1) throw new RuntimeException('订单商品为空');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO orders (out_trade_no,uid,status,channel,amount_fen,receiver_name,receiver_phone,receiver_address,quiz_result,primary_personality,source,campaign,seed_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $order['out_trade_no'],$order['uid'],'PENDING_PAYMENT',(string)($order['channel'] ?? ''),(int)$order['amount'],
            (string)($order['receiver_name'] ?? ''),(string)($order['receiver_phone'] ?? ''),(string)($order['receiver_address'] ?? ''),
            (string)($order['quiz_result'] ?? ''),(string)($order['primary_personality'] ?? ''),
            (string)($order['source'] ?? 'direct'),(string)($order['campaign'] ?? ''),(string)($order['seed_id'] ?? '')
        ]);

        $lockSku = $pdo->prepare('SELECT stock_on_hand,stock_reserved,active FROM skus WHERE sku_id=? FOR UPDATE');
        $reserve = $pdo->prepare('UPDATE skus SET stock_reserved=stock_reserved+? WHERE sku_id=?');
        $insertItem = $pdo->prepare('INSERT INTO order_items (out_trade_no,product_id,sku_id,item_name,unit_price_fen,qty) VALUES (?,?,?,?,?,?)');
        foreach ($items as $item) {
            $skuId = (string)($item['sku_id'] ?? '');
            $productId = (string)($item['product_id'] ?? '');
            $qty = max(1,(int)($item['qty'] ?? 1));
            $lockSku->execute([$skuId]);
            $sku = $lockSku->fetch();
            if (!$sku || !(int)$sku['active']) throw new RuntimeException('SKU 不存在或已下架：'.$skuId);
            $available = (int)$sku['stock_on_hand'] - (int)$sku['stock_reserved'];
            if ($available < $qty) throw new RuntimeException('库存不足：'.$skuId);
            $reserve->execute([$qty,$skuId]);
            $insertItem->execute([$order['out_trade_no'],$productId,$skuId,(string)($item['name'] ?? ''),(int)($item['unit_price_fen'] ?? 0),$qty]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function dbReleaseReservation(string $outTradeNo, string $newStatus = 'CREATE_FAILED'): void
{
    $pdo = dbConnection();
    if (!$pdo) return;
    $pdo->beginTransaction();
    try {
        $orderStmt = $pdo->prepare('SELECT status FROM orders WHERE out_trade_no=? FOR UPDATE');
        $orderStmt->execute([$outTradeNo]);
        $order = $orderStmt->fetch();
        if (!$order) { $pdo->commit(); return; }
        if (!in_array((string)$order['status'], ['PENDING_PAYMENT'], true)) { $pdo->commit(); return; }
        $itemsStmt = $pdo->prepare('SELECT sku_id,qty FROM order_items WHERE out_trade_no=?');
        $itemsStmt->execute([$outTradeNo]);
        $lockSku = $pdo->prepare('SELECT stock_reserved FROM skus WHERE sku_id=? FOR UPDATE');
        $release = $pdo->prepare('UPDATE skus SET stock_reserved=GREATEST(stock_reserved-?,0) WHERE sku_id=?');
        foreach ($itemsStmt->fetchAll() as $item) {
            $lockSku->execute([$item['sku_id']]);
            $lockSku->fetch();
            $release->execute([(int)$item['qty'],$item['sku_id']]);
        }
        $pdo->prepare('UPDATE orders SET status=? WHERE out_trade_no=?')->execute([$newStatus,$outTradeNo]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function dbFinalizePaidOrder(string $outTradeNo, string $transactionId): void
{
    $pdo = dbConnection();
    if (!$pdo) return;
    $pdo->beginTransaction();
    try {
        $orderStmt = $pdo->prepare('SELECT status FROM orders WHERE out_trade_no=? FOR UPDATE');
        $orderStmt->execute([$outTradeNo]);
        $order = $orderStmt->fetch();
        if (!$order) { $pdo->commit(); return; }
        if ((string)$order['status'] === 'PAID') { $pdo->commit(); return; }
        if ((string)$order['status'] !== 'PENDING_PAYMENT') throw new RuntimeException('订单状态不可支付：'.$order['status']);

        $itemsStmt = $pdo->prepare('SELECT sku_id,qty FROM order_items WHERE out_trade_no=?');
        $itemsStmt->execute([$outTradeNo]);
        $lockSku = $pdo->prepare('SELECT stock_on_hand,stock_reserved FROM skus WHERE sku_id=? FOR UPDATE');
        $finalize = $pdo->prepare('UPDATE skus SET stock_reserved=stock_reserved-?,stock_on_hand=stock_on_hand-? WHERE sku_id=?');
        foreach ($itemsStmt->fetchAll() as $item) {
            $qty = (int)$item['qty'];
            $lockSku->execute([$item['sku_id']]);
            $sku = $lockSku->fetch();
            if (!$sku || (int)$sku['stock_reserved'] < $qty || (int)$sku['stock_on_hand'] < $qty) throw new RuntimeException('库存预留状态异常');
            $finalize->execute([$qty,$qty,$item['sku_id']]);
        }
        $pdo->prepare('UPDATE orders SET status="PAID",transaction_id=?,paid_at=CURRENT_TIMESTAMP(3) WHERE out_trade_no=?')->execute([$transactionId,$outTradeNo]);
        $eventKey = 'paid:' . ($transactionId !== '' ? $transactionId : $outTradeNo);
        $pdo->prepare('INSERT IGNORE INTO payment_events (event_key,out_trade_no,transaction_id,trade_state) VALUES (?,?,?,"SUCCESS")')->execute([$eventKey,$outTradeNo,$transactionId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function dbMarkRefund(string $outTradeNo, string $outRefundNo, string $status, int $refundFen = 0, int $totalFen = 0): void
{
    $pdo = dbConnection();
    if (!$pdo) return;
    $check = $pdo->prepare('SELECT 1 FROM orders WHERE out_trade_no=? LIMIT 1');
    $check->execute([$outTradeNo]);
    if (!$check->fetchColumn()) return; // legacy JSON-only order during migration
    $stmt = $pdo->prepare('INSERT INTO refunds (out_refund_no,out_trade_no,refund_fen,total_fen,status) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),refund_fen=VALUES(refund_fen),total_fen=VALUES(total_fen)');
    $stmt->execute([$outRefundNo,$outTradeNo,$refundFen,$totalFen,$status]);
    if ($status === 'SUCCESS') $pdo->prepare('UPDATE orders SET status="REFUNDED" WHERE out_trade_no=? AND status="PAID"')->execute([$outTradeNo]);
}

function dbExpiredPendingOrders(int $ttlMinutes, int $limit = 100): array
{
    $pdo = dbConnection();
    if (!$pdo) return [];
    $ttlMinutes = max(5,min(120,$ttlMinutes));
    $limit = max(1,min(500,$limit));
    $sql = 'SELECT out_trade_no,created_at FROM orders WHERE status="PENDING_PAYMENT" AND created_at < (CURRENT_TIMESTAMP(3) - INTERVAL ' . $ttlMinutes . ' MINUTE) ORDER BY created_at ASC LIMIT ' . $limit;
    return $pdo->query($sql)->fetchAll() ?: [];
}
