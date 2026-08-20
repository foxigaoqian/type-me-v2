<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';

function safeOauthReturn(string $value): string
{
    $value = trim($value);
    if ($value === '' || preg_match('/[\r\n]/',$value)) return '/';
    if ($value[0] === '/' && !str_starts_with($value,'//')) return $value;

    $parts = parse_url($value);
    if (!is_array($parts)) return '/';
    $host = strtolower((string)($parts['host'] ?? ''));
    $currentHost = strtolower(preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST'] ?? '')));
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($host === '' || $host !== $currentHost || !in_array($scheme,['https','http'],true)) return '/';

    $path = (string)($parts['path'] ?? '/');
    if ($path === '' || $path[0] !== '/') $path = '/';
    if (isset($parts['query']) && $parts['query'] !== '') $path .= '?' . $parts['query'];
    if (isset($parts['fragment']) && $parts['fragment'] !== '') $path .= '#' . $parts['fragment'];
    return $path;
}

$config = getConfig();
$appid = $config['appid'] ?? '';
$appSecret = $config['app_secret'] ?? '';
if ($appid === '' || $appSecret === '') { http_response_code(500); echo '缺少APPID或APP_SECRET配置'; exit; }

$code = trim((string)($_GET['code'] ?? ''));
$state = (string)($_GET['state'] ?? '');
$returnUrl = safeOauthReturn($state);

if ($code === '') {
    $current = safeOauthReturn((string)($_GET['return'] ?? '/'));
    $host = preg_replace('/[\r\n]/','',(string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') { http_response_code(400); echo '无效域名'; exit; }
    $redirectUri = 'https://' . $host . '/api/wechat/oauth.php';
    $authUrl = 'https://open.weixin.qq.com/connect/oauth2/authorize?appid=' . rawurlencode($appid)
        . '&redirect_uri=' . rawurlencode($redirectUri)
        . '&response_type=code&scope=snsapi_base&state=' . rawurlencode($current)
        . '#wechat_redirect';
    header('Location: ' . $authUrl); exit;
}

$tokenUrl = 'https://api.weixin.qq.com/sns/oauth2/access_token?appid=' . rawurlencode($appid)
    . '&secret=' . rawurlencode($appSecret) . '&code=' . rawurlencode($code) . '&grant_type=authorization_code';
$ch = curl_init();
curl_setopt_array($ch,[CURLOPT_URL=>$tokenUrl,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'type-me-v2/2.0']);
$resp = curl_exec($ch);$errno = curl_errno($ch);$error = curl_error($ch);curl_close($ch);
if ($errno !== 0) { http_response_code(500); echo '获取openid失败: ' . htmlspecialchars($error,ENT_QUOTES,'UTF-8'); exit; }
$data = json_decode((string)$resp,true);
if (!is_array($data) || empty($data['openid'])) { http_response_code(500); echo '获取openid失败'; exit; }
$openid = (string)$data['openid'];
setcookie('wx_openid',$openid,['expires'=>time()+86400*30,'path'=>'/','secure'=>true,'httponly'=>false,'samesite'=>'Lax']);
header('Location: ' . $returnUrl);exit;
