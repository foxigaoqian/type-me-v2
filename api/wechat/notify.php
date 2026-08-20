<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/wechat_pay.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>'FAIL','message'=>'Method Not Allowed'],405);
$body = readJsonBody();
$resource = isset($body['resource']) && is_array($body['resource']) ? $body['resource'] : null;
if ($resource === null) jsonResponse(['code'=>'FAIL','message'=>'无效通知'],400);
$ciphertext = (string)($resource['ciphertext'] ?? '');
$nonce = (string)($resource['nonce'] ?? '');
$associatedData = (string)($resource['associated_data'] ?? '');
if ($ciphertext === '' || $nonce === '') jsonResponse(['code'=>'FAIL','message'=>'通知字段缺失'],400);
try {
    $decrypted = decryptNotifyResource($ciphertext,$nonce,$associatedData);
    $outTradeNo = (string)($decrypted['out_trade_no'] ?? '');
    $tradeState = (string)($decrypted['trade_state'] ?? '');
    $transactionId = (string)($decrypted['transaction_id'] ?? '');
    if ($outTradeNo !== '' && $tradeState !== '') updateOrderStatus($outTradeNo,$tradeState,$transactionId);
    jsonResponse(['code'=>'SUCCESS','message'=>'成功']);
} catch (Throwable $e) {
    jsonResponse(['code'=>'FAIL','message'=>'解密失败'],500);
}
