<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/wechat_pay.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$config = getConfig();
$appid = $config['appid'] ?? '';
$appSecret = $config['app_secret'] ?? '';
if ($appid === '' || $appSecret === '') jsonResponse(['code'=>-1,'message'=>'缺少APPID或APP_SECRET'],500);
$rawUrl = trim((string)($_GET['url'] ?? ''));
if ($rawUrl === '') jsonResponse(['code'=>-1,'message'=>'缺少url'],400);
$parts = parse_url($rawUrl);
if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) jsonResponse(['code'=>-1,'message'=>'url非法'],400);
$normalizedUrl = $parts['scheme'].'://'.$parts['host'].($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');
$storageFile = dirname(__DIR__,2).'/storage/wechat_token.json';

function readTokenCache(string $file): array {
    if(!is_file($file)) return [];
    $raw=file_get_contents($file); if($raw===false||$raw==='') return [];
    $d=json_decode($raw,true); return is_array($d)?$d:[];
}
function writeTokenCache(string $file,array $data): void {
    $dir=dirname($file); if(!is_dir($dir)) mkdir($dir,0775,true);
    file_put_contents($file,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX);
}
function httpGetJson(string $url): array {
    $ch=curl_init();curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'type-me-v2/2.0']);
    $resp=curl_exec($ch);$errno=curl_errno($ch);$error=curl_error($ch);curl_close($ch);
    if($errno!==0) throw new RuntimeException('请求失败: '.$error);
    $data=json_decode((string)$resp,true);if(!is_array($data)) throw new RuntimeException('响应解析失败');return $data;
}
function getAccessToken(string $appid,string $appSecret,string $cacheFile): string {
    $cache=readTokenCache($cacheFile);$now=time();
    if(!empty($cache['access_token'])&&!empty($cache['access_token_expire'])&&$cache['access_token_expire']>$now+60) return (string)$cache['access_token'];
    $data=httpGetJson('https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.rawurlencode($appid).'&secret='.rawurlencode($appSecret));
    if(empty($data['access_token'])) throw new RuntimeException('获取access_token失败');
    $cache['access_token']=(string)$data['access_token'];$cache['access_token_expire']=$now+(int)($data['expires_in']??7200);writeTokenCache($cacheFile,$cache);return $cache['access_token'];
}
function getJsapiTicket(string $accessToken,string $cacheFile): string {
    $cache=readTokenCache($cacheFile);$now=time();
    if(!empty($cache['jsapi_ticket'])&&!empty($cache['jsapi_ticket_expire'])&&$cache['jsapi_ticket_expire']>$now+60) return (string)$cache['jsapi_ticket'];
    $data=httpGetJson('https://api.weixin.qq.com/cgi-bin/ticket/getticket?type=jsapi&access_token='.rawurlencode($accessToken));
    if(($data['errcode']??1)!==0||empty($data['ticket'])) throw new RuntimeException('获取jsapi_ticket失败');
    $cache['jsapi_ticket']=(string)$data['ticket'];$cache['jsapi_ticket_expire']=$now+(int)($data['expires_in']??7200);writeTokenCache($cacheFile,$cache);return $cache['jsapi_ticket'];
}
try {
    $accessToken=getAccessToken($appid,$appSecret,$storageFile);$ticket=getJsapiTicket($accessToken,$storageFile);
    $timestamp=(string)time();$nonceStr=randomNonce(16);
    $signStr='jsapi_ticket='.$ticket.'&noncestr='.$nonceStr.'&timestamp='.$timestamp.'&url='.$normalizedUrl;
    jsonResponse(['code'=>0,'appId'=>$appid,'timestamp'=>(int)$timestamp,'nonceStr'=>$nonceStr,'signature'=>sha1($signStr),'url'=>$normalizedUrl]);
} catch(Throwable $e) {
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],500);
}
