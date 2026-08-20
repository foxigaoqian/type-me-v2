<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require_once dirname(__DIR__) . '/api/lib/wechat_pay.php';
require_once dirname(__DIR__) . '/api/lib/db.php';

if (!dbEnabled()) {
    fwrite(STDOUT, "DB_DSN is not configured; nothing to reconcile.\n");
    exit(0);
}

$ttl = (int)(getConfig()['order_reservation_ttl_minutes'] ?? 30);
$rows = dbExpiredPendingOrders($ttl, 100);
$stats = ['checked'=>0,'paid'=>0,'released'=>0,'skipped'=>0,'errors'=>0];

foreach ($rows as $row) {
    $outTradeNo = (string)($row['out_trade_no'] ?? '');
    if ($outTradeNo === '') continue;
    $stats['checked']++;
    try {
        $query = queryOrderByOutTradeNo($outTradeNo);
        if (!$query['success']) {
            $stats['errors']++;
            fwrite(STDERR, "[$outTradeNo] query failed\n");
            continue;
        }
        $state = (string)($query['data']['trade_state'] ?? 'NOTPAY');
        $transactionId = (string)($query['data']['transaction_id'] ?? '');

        if ($state === 'SUCCESS') {
            dbFinalizePaidOrder($outTradeNo,$transactionId);
            updateOrderStatus($outTradeNo,'SUCCESS',$transactionId);
            $stats['paid']++;
            fwrite(STDOUT, "[$outTradeNo] finalized PAID\n");
            continue;
        }

        if (in_array($state,['CLOSED','REVOKED','PAYERROR'],true)) {
            dbReleaseReservation($outTradeNo,$state);
            updateOrderStatus($outTradeNo,$state);
            $stats['released']++;
            fwrite(STDOUT, "[$outTradeNo] released ($state)\n");
            continue;
        }

        if ($state === 'NOTPAY') {
            $closed = closeWechatOrder($outTradeNo);
            if (!$closed['success']) {
                $stats['errors']++;
                fwrite(STDERR, "[$outTradeNo] close failed; reservation kept\n");
                continue;
            }
            dbReleaseReservation($outTradeNo,'CLOSED');
            updateOrderStatus($outTradeNo,'CLOSED');
            $stats['released']++;
            fwrite(STDOUT, "[$outTradeNo] closed and released\n");
            continue;
        }

        // USERPAYING or any future/unknown state: do not release inventory.
        $stats['skipped']++;
        fwrite(STDOUT, "[$outTradeNo] kept reservation ($state)\n");
    } catch (Throwable $e) {
        $stats['errors']++;
        fwrite(STDERR, "[$outTradeNo] error: {$e->getMessage()}\n");
    }
}

fwrite(STDOUT, json_encode($stats,JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit($stats['errors'] > 0 ? 1 : 0);
