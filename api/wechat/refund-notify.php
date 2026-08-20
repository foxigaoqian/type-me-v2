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
    $outTradeNo=(string)($decrypted['out_trade_no']??'');
    $outRefundNo=(string)($decrypted['out_refund_no']??'');
    $refundStatus=(string)($decrypted['refund_status']??'');
    if($outTradeNo!==''){
        $orders=readOrders();
        if(isset($orders[$outTradeNo])){
            $orders[$outTradeNo]['refund_status']=$refundStatus;
            $orders[$outTradeNo]['out_refund_no']=$outRefundNo;
            $orders[$outTradeNo]['refund_notify_at']=date('c');
            if($refundStatus==='SUCCESS')$orders[$outTradeNo]['status']='REFUNDED';
            writeOrders($orders);
        }
    }
    jsonResponse(['code'=>'SUCCESS','message'=>'成功']);
} catch(Throwable $e){jsonResponse(['code'=>'FAIL','message'=>'解密失败'],500);}
