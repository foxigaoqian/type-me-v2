<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/wechat_pay.php';
require_once dirname(__DIR__) . '/lib/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>'FAIL','message'=>'Method Not Allowed'],405);
$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') jsonResponse(['code'=>'FAIL','message'=>'空通知'],400);

try {
    verifyWechatPayCallbackSignature($rawBody);
    $body = json_decode($rawBody, true);
    if (!is_array($body)) throw new RuntimeException('通知JSON无效');
    $resource = isset($body['resource']) && is_array($body['resource']) ? $body['resource'] : null;
    if ($resource === null) throw new RuntimeException('无效通知');
    $ciphertext = (string)($resource['ciphertext'] ?? '');
    $nonce = (string)($resource['nonce'] ?? '');
    $associatedData = (string)($resource['associated_data'] ?? '');
    if ($ciphertext === '' || $nonce === '') throw new RuntimeException('通知字段缺失');

    $decrypted = decryptNotifyResource($ciphertext, $nonce, $associatedData);
    $outTradeNo = (string)($decrypted['out_trade_no'] ?? '');
    $outRefundNo = (string)($decrypted['out_refund_no'] ?? '');
    $refundStatus = (string)($decrypted['refund_status'] ?? '');
    $amount = isset($decrypted['amount']) && is_array($decrypted['amount']) ? $decrypted['amount'] : [];
    $refundFen = (int)($amount['refund'] ?? 0);
    $totalFen = (int)($amount['total'] ?? 0);
    if ($outTradeNo === '' || $outRefundNo === '' || $refundStatus === '') throw new RuntimeException('退款通知订单字段缺失');

    dbMarkRefund($outTradeNo,$outRefundNo,$refundStatus,$refundFen,$totalFen);

    // JSON mirror retained only for compatibility until full cutover.
    $orders = readOrders();
    if (isset($orders[$outTradeNo])) {
        $orders[$outTradeNo]['refund_status'] = $refundStatus;
        $orders[$outTradeNo]['out_refund_no'] = $outRefundNo;
        $orders[$outTradeNo]['refund_notify_at'] = date('c');
        if ($refundStatus === 'SUCCESS') $orders[$outTradeNo]['status'] = 'REFUNDED';
        writeOrders($orders);
    }
    jsonResponse(['code'=>'SUCCESS','message'=>'成功']);
} catch (Throwable $e) {
    jsonResponse(['code'=>'FAIL','message'=>$e->getMessage()],400);
}
