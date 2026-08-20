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
    $tradeState = (string)($decrypted['trade_state'] ?? '');
    $transactionId = (string)($decrypted['transaction_id'] ?? '');
    if ($outTradeNo === '' || $tradeState === '') throw new RuntimeException('支付通知订单字段缺失');

    if ($tradeState === 'SUCCESS') {
        dbFinalizePaidOrder($outTradeNo, $transactionId);
    } elseif (in_array($tradeState, ['CLOSED','REVOKED','PAYERROR'], true)) {
        dbReleaseReservation($outTradeNo, $tradeState);
    }
    // JSON store remains as compatibility mirror until DB migration is fully cut over.
    updateOrderStatus($outTradeNo, $tradeState, $transactionId);
    jsonResponse(['code'=>'SUCCESS','message'=>'成功']);
} catch (Throwable $e) {
    jsonResponse(['code'=>'FAIL','message'=>$e->getMessage()],400);
}
