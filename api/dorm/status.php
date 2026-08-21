<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/dorm.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);

try {
    $uid = getCurrentUserId();
    $code = dormValidateCode((string)($_GET['code'] ?? $_GET['dorm'] ?? ''));
    $snapshot = dormStatus($code);
    jsonResponse(['code'=>0,'dorm'=>dormPublicPayload($snapshot,$uid)]);
} catch (Throwable $e) {
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],404);
}
