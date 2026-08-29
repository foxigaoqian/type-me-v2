<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/dorm_card.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$body=readJsonBody();

try{
    $uid=getCurrentUserId();
    $code=dormValidateCode((string)($body['invite_code']??''));
    $snapshot=dormStatus($code);
    $payload=dormPublicPayload($snapshot,$uid);
    $card=renderDormDoorplate($payload);
    try{trackEvent('dorm_doorplate_generate',['dorm_id'=>$payload['dorm_id'],'dorm_code'=>$payload['invite_code'],'dorm_member_count'=>$payload['member_count']]);}catch(Throwable $e){}
    jsonResponse(['code'=>0,'card_url'=>$card['url'],'filename'=>$card['filename'],'width'=>$card['width'],'height'=>$card['height'],'preview_mode'=>!empty($payload['preview_ephemeral'])]);
}catch(Throwable $e){
    jsonResponse(['code'=>-1,'message'=>$e->getMessage()],400);
}
