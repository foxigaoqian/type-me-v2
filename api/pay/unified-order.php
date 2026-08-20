<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/wechat_pay.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();
$amount = isset($body['amount']) ? (int)$body['amount'] : 0;
$description = trim((string)($body['description'] ?? ''));
$scene = strtoupper((string)($body['scene'] ?? 'NATIVE'));
$items = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
$receiverName = trim((string)($body['receiver_name'] ?? ''));
$receiverPhone = trim((string)($body['receiver_phone'] ?? ''));
$receiverAddress = trim((string)($body['receiver_address'] ?? ''));
$designType = trim((string)($body['design_type'] ?? ''));
$quizResult = trim((string)($body['quiz_result'] ?? ''));

if ($amount <= 0 || $description === '') jsonResponse(['code'=>-1,'message'=>'参数不完整或金额非法'],400);
if ($amount !== 12900) jsonResponse(['code'=>-1,'message'=>'V2 当前仅允许人格认证价 ¥129'],400);

$uid = getCurrentUserId();
$outTradeNo = 'ORD' . time() . random_int(1000,9999);
$baseOrder = [
    'uid'=>$uid,'out_trade_no'=>$outTradeNo,'amount'=>$amount,'description'=>$description,'items'=>$items,
    'receiver_name'=>$receiverName,'receiver_phone'=>$receiverPhone,'receiver_address'=>$receiverAddress,
    'design_type'=>$designType,'quiz_result'=>$quizResult,'status'=>'PENDING_PAYMENT',
    'amount_origin_fen'=>$amount,'discount_fen'=>0,'amount_pay_fen'=>$amount,'created_at'=>date('c')
];

if ($scene === 'JSAPI') {
    $openid = trim((string)($_SERVER['HTTP_X_USER_OPENID'] ?? ''));
    if ($openid === '') jsonResponse(['code'=>-2,'message'=>'缺少openid，请在微信内授权后重试'],400);
    $result = createJsapiOrder($description,$outTradeNo,$amount,$openid);
    if (!$result['success']) jsonResponse(['code'=>-3,'message'=>'JSAPI下单失败','error'=>$result['error']??null],500);
    $prepayId = (string)($result['data']['prepay_id'] ?? '');
    if ($prepayId === '') jsonResponse(['code'=>-3,'message'=>'JSAPI下单返回缺少prepay_id'],500);
    $baseOrder['openid']=$openid;$baseOrder['channel']='JSAPI';saveOrder($baseOrder);
    jsonResponse(['channel'=>'JSAPI','out_trade_no'=>$outTradeNo,'payParams'=>buildJsapiPayParams($prepayId),'discount_fen'=>0,'used_coupon'=>false,'pay_amount_fen'=>$amount]);
}

$result = createNativeOrder($description,$outTradeNo,$amount);
if (!$result['success']) jsonResponse(['code'=>-3,'message'=>'Native下单失败','error'=>$result['error']??null],500);
$codeUrl = (string)($result['data']['code_url'] ?? '');
if ($codeUrl === '') jsonResponse(['code'=>-3,'message'=>'Native下单返回缺少code_url'],500);
$baseOrder['channel']='NATIVE';saveOrder($baseOrder);
jsonResponse(['channel'=>'NATIVE','out_trade_no'=>$outTradeNo,'code_url'=>$codeUrl,'discount_fen'=>0,'used_coupon'=>false,'pay_amount_fen'=>$amount]);
