<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/wechat_pay.php';
require_once dirname(__DIR__) . '/lib/catalog.php';

header('Cache-Control: no-store, max-age=0');

try {
    jsonResponse(['code'=>0] + catalogPublicSnapshot());
} catch (Throwable $error) {
    error_log('[TYPE-ME public catalog] ' . $error->getMessage());
    jsonResponse(['code'=>-1,'message'=>'商品库存暂时无法读取'], 503);
}
