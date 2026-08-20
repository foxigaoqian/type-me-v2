<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/wechat_pay.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$outTradeNo = trim((string)($_GET['out_trade_no'] ?? ''));
if ($outTradeNo === '') jsonResponse(['code'=>-1,'message'=>'缺少out_trade_no'],400);
$result = queryOrderByOutTradeNo($outTradeNo);
if (!$result['success']) jsonResponse(['code'=>-1,'message'=>'查询失败','error'=>$result['error']??null],500);
$tradeState = (string)($result['data']['trade_state'] ?? 'NOTPAY');
$transactionId = (string)($result['data']['transaction_id'] ?? '');
updateOrderStatus($outTradeNo,$tradeState,$transactionId);
jsonResponse(['out_trade_no'=>$outTradeNo,'trade_state'=>$tradeState,'transaction_id'=>$transactionId]);
