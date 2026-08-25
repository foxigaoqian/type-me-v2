<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/wechat_pay.php';
require_once dirname(__DIR__) . '/lib/db.php';

function enrichV2OrderItems(array $items): array
{
    if (count($items) !== 1) throw new InvalidArgumentException('V2 当前每单仅允许 1 件人格 T 恤');
    $item = $items[0];
    $personality = trim((string)($item['personality'] ?? ''));
    $color = trim((string)($item['color'] ?? ''));
    $size = trim((string)($item['size'] ?? ''));
    $qty = (int)($item['qty'] ?? 1);
    if ($qty !== 1) throw new InvalidArgumentException('V2 当前每单数量必须为 1');

    $raw = file_get_contents(dirname(__DIR__, 2) . '/config/v2.json');
    $cfg = json_decode((string)$raw, true);
    $productCfg = is_array($cfg) ? ($cfg['product_config'] ?? []) : [];
    if (($productCfg['sales_enabled'] ?? false) !== true) {
        throw new RuntimeException((string)($productCfg['sales_status'] ?? '商品暂未开放购买'));
    }
    $product = $productCfg['products'][$personality] ?? null;
    if (!is_array($product)) throw new InvalidArgumentException('人格商品不存在');
    if (!in_array($color, $productCfg['colors'] ?? [], true)) throw new InvalidArgumentException('无效颜色');
    if (!in_array($size, $productCfg['sizes'] ?? [], true)) throw new InvalidArgumentException('无效尺码');

    $productId = (string)$product['product_id'];
    return [[
        'name'=>(string)$product['name'],
        'qty'=>1,
        'type'=>'personality_tee',
        'personality'=>$personality,
        'color'=>$color,
        'size'=>$size,
        'product_id'=>$productId,
        'sku_id'=>$productId.'-'.$color.'-'.$size,
        'unit_price_fen'=>12900,
    ]];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body = readJsonBody();
$amount = isset($body['amount']) ? (int)$body['amount'] : 0;
$description = trim((string)($body['description'] ?? ''));
$scene = strtoupper((string)($body['scene'] ?? 'NATIVE'));
$receiverName = trim((string)($body['receiver_name'] ?? ''));
$receiverPhone = trim((string)($body['receiver_phone'] ?? ''));
$receiverAddress = trim((string)($body['receiver_address'] ?? ''));
$designType = trim((string)($body['design_type'] ?? ''));
$quizResult = trim((string)($body['quiz_result'] ?? ''));

if ($amount !== 12900 || $description === '') jsonResponse(['code'=>-1,'message'=>'V2 当前仅允许人格认证价 ¥129'],400);
if ($receiverName === '' || $receiverPhone === '' || $receiverAddress === '') jsonResponse(['code'=>-1,'message'=>'收货信息不完整'],400);
if (!in_array($scene, ['JSAPI','NATIVE'], true)) jsonResponse(['code'=>-1,'message'=>'不支持的支付场景'],400);

try {
    $items = enrichV2OrderItems(isset($body['items']) && is_array($body['items']) ? $body['items'] : []);
} catch (Throwable $e) {
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],400);
}

$uid = getCurrentUserId();
$outTradeNo = 'ORD' . date('YmdHis') . random_int(100000,999999);
$primaryPersonality = (string)$items[0]['personality'];
$baseOrder = [
    'uid'=>$uid,'out_trade_no'=>$outTradeNo,'amount'=>$amount,'description'=>(string)$items[0]['name'],'items'=>$items,
    'receiver_name'=>$receiverName,'receiver_phone'=>$receiverPhone,'receiver_address'=>$receiverAddress,
    'design_type'=>$designType,'quiz_result'=>$quizResult,'primary_personality'=>$primaryPersonality,
    'status'=>'PENDING_PAYMENT','amount_origin_fen'=>$amount,'discount_fen'=>0,'amount_pay_fen'=>$amount,'created_at'=>date('c')
];

if ($scene === 'JSAPI') {
    $openid = trim((string)($_SERVER['HTTP_X_USER_OPENID'] ?? ''));
    if ($openid === '') jsonResponse(['code'=>-2,'message'=>'缺少openid，请在微信内授权后重试'],400);
    $baseOrder['openid']=$openid;$baseOrder['channel']='JSAPI';
    try { dbCreateReservedOrder($baseOrder); }
    catch (Throwable $e) { jsonResponse(['code'=>-4,'message'=>$e->getMessage()],409); }
    $result = createJsapiOrder((string)$items[0]['name'],$outTradeNo,$amount,$openid);
    if (!$result['success']) {
        try { dbReleaseReservation($outTradeNo,'CREATE_FAILED'); } catch (Throwable $ignored) {}
        jsonResponse(['code'=>-3,'message'=>'JSAPI下单失败','error'=>$result['error']??null],500);
    }
    $prepayId = (string)($result['data']['prepay_id'] ?? '');
    if ($prepayId === '') {
        try { dbReleaseReservation($outTradeNo,'CREATE_FAILED'); } catch (Throwable $ignored) {}
        jsonResponse(['code'=>-3,'message'=>'JSAPI下单返回缺少prepay_id'],500);
    }
    saveOrder($baseOrder);
    jsonResponse(['channel'=>'JSAPI','out_trade_no'=>$outTradeNo,'payParams'=>buildJsapiPayParams($prepayId),'discount_fen'=>0,'used_coupon'=>false,'pay_amount_fen'=>$amount]);
}

$baseOrder['channel']='NATIVE';
try { dbCreateReservedOrder($baseOrder); }
catch (Throwable $e) { jsonResponse(['code'=>-4,'message'=>$e->getMessage()],409); }
$result = createNativeOrder((string)$items[0]['name'],$outTradeNo,$amount);
if (!$result['success']) {
    try { dbReleaseReservation($outTradeNo,'CREATE_FAILED'); } catch (Throwable $ignored) {}
    jsonResponse(['code'=>-3,'message'=>'Native下单失败','error'=>$result['error']??null],500);
}
$codeUrl = (string)($result['data']['code_url'] ?? '');
if ($codeUrl === '') {
    try { dbReleaseReservation($outTradeNo,'CREATE_FAILED'); } catch (Throwable $ignored) {}
    jsonResponse(['code'=>-3,'message'=>'Native下单返回缺少code_url'],500);
}
saveOrder($baseOrder);
jsonResponse(['channel'=>'NATIVE','out_trade_no'=>$outTradeNo,'code_url'=>$codeUrl,'discount_fen'=>0,'used_coupon'=>false,'pay_amount_fen'=>$amount]);
