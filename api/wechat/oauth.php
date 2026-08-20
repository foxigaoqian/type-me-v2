<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';

$config = getConfig();
$appid = $config['appid'] ?? '';
$appSecret = $config['app_secret'] ?? '';
if ($appid === '' || $appSecret === '') { http_response_code(500); echo '缺少APPID或APP_SECRET配置'; exit; }

$code = trim((string)($_GET['code'] ?? ''));
$state = (string)($_GET['state'] ?? '');
$returnUrl = $state !== '' ? urldecode($state) : '/';

if ($code === '') {
    $current = (string)($_GET['return'] ?? '/');
    $redirectUri = 'https://' . $_SERVER['HTTP_HOST'] . '/api/wechat/oauth.php';
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
if ($errno !== 0) { http_response_code(500); echo '获取openid失败: ' . $error; exit; }
$data = json_decode((string)$resp,true);
if (!is_array($data) || empty($data['openid'])) { http_response_code(500); echo '获取openid失败'; exit; }
$openid = (string)$data['openid'];
setcookie('wx_openid',$openid,['expires'=>time()+86400*30,'path'=>'/','secure'=>true,'httponly'=>false,'samesite'=>'Lax']);
header('Location: ' . $returnUrl);exit;
