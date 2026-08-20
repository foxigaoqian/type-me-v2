<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/wechat_pay.php';
requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$limit = max(1,min(500,(int)($_GET['limit']??100)));
$orders = array_values(readOrders());
usort($orders,fn($a,$b)=>strtotime((string)($b['created_at']??''))<=>strtotime((string)($a['created_at']??'')));
$orders=array_slice($orders,0,$limit);
jsonResponse(['code'=>0,'count'=>count($orders),'items'=>$orders]);
