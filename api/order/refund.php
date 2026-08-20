<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/wechat_pay.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();
$outTradeNo = trim((string)($body['out_trade_no'] ?? ''));
$reason = trim((string)($body['reason'] ?? '用户申请退款'));
if ($outTradeNo === '') jsonResponse(['code'=>-1,'message'=>'缺少out_trade_no'],400);
$orders = readOrders();
if (!isset($orders[$outTradeNo])) jsonResponse(['code'=>-1,'message'=>'订单不存在'],404);
$order = $orders[$outTradeNo];
$status = (string)($order['status'] ?? '');
if (!in_array($status,['SUCCESS','PAID'],true)) jsonResponse(['code'=>-1,'message'=>'当前订单状态不可退款'],400);
if (!empty($order['refund_status']) && in_array($order['refund_status'],['PROCESSING','SUCCESS'],true)) {
    jsonResponse(['code'=>0,'message'=>'退款已在处理中','out_trade_no'=>$outTradeNo,'out_refund_no'=>$order['out_refund_no']??'','refund_status'=>$order['refund_status']]);
}
$totalFen = (int)($order['amount_pay_fen'] ?? $order['amount'] ?? 0);
if ($totalFen <= 0) jsonResponse(['code'=>-1,'message'=>'订单金额异常，无法发起退款'],400);
$outRefundNo='RFD'.time().random_int(1000,9999);
$refundResult=createRefund($outTradeNo,$outRefundNo,$totalFen,$totalFen,$reason);
if(!$refundResult['success']) jsonResponse(['code'=>-1,'message'=>'退款申请失败','error'=>$refundResult['error']??null],500);
$order['status']='REFUND_REQUESTED';$order['refund_reason']=$reason;$order['refund_requested_at']=date('c');$order['out_refund_no']=$outRefundNo;$order['refund_status']=(string)($refundResult['data']['status']??'PROCESSING');
$orders[$outTradeNo]=$order;writeOrders($orders);
jsonResponse(['code'=>0,'message'=>'退款申请已提交，后台将自动处理并原路返回','out_trade_no'=>$outTradeNo,'out_refund_no'=>$outRefundNo,'refund_status'=>$order['refund_status']]);
