<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/wechat_pay.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$uid=getCurrentUserId();
$orders=array_values(array_filter(readOrders(),static function(array $order)use($uid):bool{
    if(($order['uid']??'')!==$uid)return false;
    return in_array((string)($order['status']??''),['PAID','SUCCESS','REFUND_REQUESTED','REFUNDED','PROCESSING'],true);
}));
usort($orders,fn($a,$b)=>strtotime((string)($b['created_at']??''))<=>strtotime((string)($a['created_at']??'')));
jsonResponse(['code'=>0,'uid'=>$uid,'count'=>count($orders),'items'=>$orders]);
