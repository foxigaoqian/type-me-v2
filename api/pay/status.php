<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/wechat_pay.php';
require_once dirname(__DIR__) . '/lib/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$outTradeNo = trim((string)($_GET['out_trade_no'] ?? ''));
if ($outTradeNo === '') jsonResponse(['code'=>-1,'message'=>'缺少out_trade_no'],400);
$orders = readOrders();
if (!isset($orders[$outTradeNo])) jsonResponse(['code'=>-1,'message'=>'订单不存在'],404);
if ((string)($orders[$outTradeNo]['uid'] ?? '') !== getCurrentUserId()) jsonResponse(['code'=>-1,'message'=>'无权查询此订单'],403);
$result = queryOrderByOutTradeNo($outTradeNo);
if (!$result['success']) jsonResponse(['code'=>-1,'message'=>'查询失败','error'=>$result['error']??null],500);
$tradeState = (string)($result['data']['trade_state'] ?? 'NOTPAY');
$transactionId = (string)($result['data']['transaction_id'] ?? '');
try {
    if ($tradeState === 'SUCCESS') dbFinalizePaidOrder($outTradeNo,$transactionId);
    elseif (in_array($tradeState,['CLOSED','REVOKED','PAYERROR'],true)) dbReleaseReservation($outTradeNo,$tradeState);
    updateOrderStatus($outTradeNo,$tradeState,$transactionId);
    jsonResponse(['out_trade_no'=>$outTradeNo,'trade_state'=>$tradeState,'transaction_id'=>$transactionId]);
} catch(Throwable $e){
    jsonResponse(['code'=>-1,'message'=>'订单状态同步失败: '.$e->getMessage()],500);
}
