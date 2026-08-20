<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

const WECHAT_API_BASE = 'https://api.mch.weixin.qq.com';

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function randomNonce(int $length = 32): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $nonce = '';
    for ($i = 0; $i < $length; $i++) $nonce .= $chars[random_int(0, strlen($chars) - 1)];
    return $nonce;
}

function getPrivateKey(string $path): string
{
    if (!is_file($path)) throw new RuntimeException('商户私钥文件不存在');
    $key = file_get_contents($path);
    if ($key === false) throw new RuntimeException('读取商户私钥失败');
    return $key;
}

function signMessage(string $message, string $privateKey): string
{
    $ok = openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$ok) throw new RuntimeException('签名失败');
    return base64_encode($signature);
}

function buildAuthorization(string $method, string $urlPathWithQuery, string $body, array $config): string
{
    $timestamp = (string)time();
    $nonce = randomNonce(32);
    $message = $method . "\n" . $urlPathWithQuery . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
    $signature = signMessage($message, getPrivateKey($config['private_key_path']));
    return sprintf(
        'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
        $config['mchid'], $nonce, $timestamp, $config['serial_no'], $signature
    );
}

function wechatRequest(string $method, string $urlPathWithQuery, array $payload = []): array
{
    $config = getConfig();
    $body = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : '';
    $authorization = buildAuthorization($method, $urlPathWithQuery, $body, $config);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => WECHAT_API_BASE . $urlPathWithQuery,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERAGENT => 'type-me-v2/2.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: type-me-v2/2.0',
            'Authorization: ' . $authorization,
        ],
    ]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) return ['success' => false, 'error' => 'curl错误: ' . $error, 'http_code' => $httpCode];
    $decoded = json_decode((string)$resp, true);
    if ($httpCode >= 200 && $httpCode < 300) return ['success' => true, 'data' => is_array($decoded) ? $decoded : []];
    return ['success' => false, 'http_code' => $httpCode, 'error' => is_array($decoded) ? $decoded : ['raw' => $resp]];
}

function wechatOrderExpireAt(): string
{
    $minutes = (int)(getConfig()['order_reservation_ttl_minutes'] ?? 30);
    return (new DateTimeImmutable('now'))->modify('+' . $minutes . ' minutes')->format(DATE_RFC3339);
}

function createJsapiOrder(string $description, string $outTradeNo, int $totalFen, string $openid): array
{
    $config = getConfig();
    return wechatRequest('POST', '/v3/pay/transactions/jsapi', [
        'appid' => $config['appid'], 'mchid' => $config['mchid'], 'description' => $description,
        'out_trade_no' => $outTradeNo, 'notify_url' => $config['notify_url'], 'time_expire' => wechatOrderExpireAt(),
        'amount' => ['total' => $totalFen, 'currency' => 'CNY'], 'payer' => ['openid' => $openid],
    ]);
}

function createNativeOrder(string $description, string $outTradeNo, int $totalFen): array
{
    $config = getConfig();
    return wechatRequest('POST', '/v3/pay/transactions/native', [
        'appid' => $config['appid'], 'mchid' => $config['mchid'], 'description' => $description,
        'out_trade_no' => $outTradeNo, 'notify_url' => $config['notify_url'], 'time_expire' => wechatOrderExpireAt(),
        'amount' => ['total' => $totalFen, 'currency' => 'CNY'],
    ]);
}

function queryOrderByOutTradeNo(string $outTradeNo): array
{
    $config = getConfig();
    return wechatRequest('GET', '/v3/pay/transactions/out-trade-no/' . rawurlencode($outTradeNo) . '?mchid=' . rawurlencode($config['mchid']));
}

function closeWechatOrder(string $outTradeNo): array
{
    $config = getConfig();
    return wechatRequest('POST', '/v3/pay/transactions/out-trade-no/' . rawurlencode($outTradeNo) . '/close', [
        'mchid' => $config['mchid'],
    ]);
}

function createRefund(string $outTradeNo, string $outRefundNo, int $refundFen, int $totalFen, string $reason = '用户申请退款'): array
{
    $config = getConfig();
    return wechatRequest('POST', '/v3/refund/domestic/refunds', [
        'out_trade_no' => $outTradeNo, 'out_refund_no' => $outRefundNo, 'reason' => $reason,
        'notify_url' => $config['refund_notify_url'] ?? $config['notify_url'],
        'amount' => ['refund' => $refundFen, 'total' => $totalFen, 'currency' => 'CNY'],
    ]);
}

function buildJsapiPayParams(string $prepayId): array
{
    $config = getConfig();
    $timeStamp = (string)time();
    $nonceStr = randomNonce(32);
    $package = 'prepay_id=' . $prepayId;
    $message = $config['appid'] . "\n" . $timeStamp . "\n" . $nonceStr . "\n" . $package . "\n";
    $paySign = signMessage($message, getPrivateKey($config['private_key_path']));
    return ['appId' => $config['appid'], 'timeStamp' => $timeStamp, 'nonceStr' => $nonceStr, 'package' => $package, 'signType' => 'RSA', 'paySign' => $paySign];
}

function resolvePlatformCertPath(string $path): string
{
    if ($path === '') throw new RuntimeException('未配置微信支付平台证书路径');
    if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) return $path;
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}

function verifyWechatPayCallbackSignature(string $rawBody): void
{
    $timestamp = trim((string)($_SERVER['HTTP_WECHATPAY_TIMESTAMP'] ?? ''));
    $nonce = trim((string)($_SERVER['HTTP_WECHATPAY_NONCE'] ?? ''));
    $signature = trim((string)($_SERVER['HTTP_WECHATPAY_SIGNATURE'] ?? ''));
    $serial = strtoupper(ltrim(trim((string)($_SERVER['HTTP_WECHATPAY_SERIAL'] ?? '')), '0'));

    if ($timestamp === '' || $nonce === '' || $signature === '' || $serial === '') {
        throw new RuntimeException('微信支付回调验签头缺失');
    }
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) {
        throw new RuntimeException('微信支付回调时间戳无效或已过期');
    }

    $certPath = resolvePlatformCertPath((string)(getConfig()['platform_cert_path'] ?? ''));
    if (!is_file($certPath)) throw new RuntimeException('微信支付平台证书不存在');
    $certPem = file_get_contents($certPath);
    if ($certPem === false || $certPem === '') throw new RuntimeException('读取微信支付平台证书失败');

    $cert = openssl_x509_read($certPem);
    if ($cert === false) throw new RuntimeException('微信支付平台证书格式无效');
    $certInfo = openssl_x509_parse($cert);
    $certSerial = strtoupper(ltrim((string)($certInfo['serialNumberHex'] ?? ''), '0'));
    if ($certSerial !== '' && $serial !== $certSerial) {
        throw new RuntimeException('微信支付回调证书序列号不匹配');
    }

    $decodedSignature = base64_decode($signature, true);
    if ($decodedSignature === false) throw new RuntimeException('微信支付回调签名格式无效');
    $message = $timestamp . "\n" . $nonce . "\n" . $rawBody . "\n";
    $verify = openssl_verify($message, $decodedSignature, $certPem, OPENSSL_ALGO_SHA256);
    if ($verify !== 1) throw new RuntimeException('微信支付回调签名校验失败');
}

function decryptNotifyResource(string $ciphertext, string $nonce, string $associatedData): array
{
    $key = getConfig()['api_v3_key'];
    if (strlen((string)$key) !== 32) throw new RuntimeException('API_V3_KEY 长度必须为32字节');
    $raw = base64_decode($ciphertext, true);
    if ($raw === false || strlen($raw) < 17) throw new RuntimeException('无效ciphertext');
    $authTag = substr($raw, -16);
    $cipher = substr($raw, 0, -16);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $authTag, $associatedData);
    if ($plain === false) throw new RuntimeException('回调解密失败');
    $decoded = json_decode($plain, true);
    if (!is_array($decoded)) throw new RuntimeException('回调解密后JSON无效');
    return $decoded;
}

function readJsonStore(string $file): array
{
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function writeJsonStore(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function getCurrentUserId(): string
{
    $uid = isset($_COOKIE['uid']) ? trim((string)$_COOKIE['uid']) : '';
    if ($uid !== '') return $uid;
    $uid = 'u_' . bin2hex(random_bytes(8));
    setcookie('uid', $uid, ['expires'=>time()+86400*365,'path'=>'/','secure'=>true,'httponly'=>false,'samesite'=>'Lax']);
    return $uid;
}

function readOrders(): array
{
    return readJsonStore(getConfig()['storage_orders']);
}

function writeOrders(array $orders): void
{
    writeJsonStore(getConfig()['storage_orders'], $orders);
}

function saveOrder(array $order): void
{
    $orders = readOrders();
    $orders[$order['out_trade_no']] = $order;
    writeOrders($orders);
}

function updateOrderStatus(string $outTradeNo, string $tradeState, ?string $transactionId = null): void
{
    $orders = readOrders();
    $normalized = $tradeState === 'SUCCESS' ? 'PAID' : $tradeState;
    if (!isset($orders[$outTradeNo])) {
        $orders[$outTradeNo] = ['out_trade_no'=>$outTradeNo,'status'=>$normalized,'updated_at'=>date('c')];
    } else {
        $orders[$outTradeNo]['status'] = $normalized;
        $orders[$outTradeNo]['updated_at'] = date('c');
    }
    if ($transactionId) $orders[$outTradeNo]['transaction_id'] = $transactionId;
    writeOrders($orders);
}
