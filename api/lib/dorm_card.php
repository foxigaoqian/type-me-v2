<?php

declare(strict_types=1);

require_once __DIR__ . '/dorm.php';
require_once __DIR__ . '/identity_card.php';

function renderDormDoorplate(array $payload): array
{
    if (($payload['status'] ?? '') !== 'COMPLETE' || !is_array($payload['report'] ?? null)) {
        throw new RuntimeException('4 位室友全部完成后才能生成宿舍人格门牌');
    }
    if (!extension_loaded('gd') || !function_exists('imagettftext')) throw new RuntimeException('服务器缺少 GD/FreeType 扩展');

    $font = identityCardFontPath();
    $w=1080; $h=1920;
    $im=imagecreatetruecolor($w,$h);
    imagealphablending($im,true);
    $bg=imagecolorallocate($im,15,15,16);
    $white=imagecolorallocate($im,250,250,247);
    $muted=imagecolorallocate($im,158,158,162);
    $line=imagecolorallocate($im,58,58,62);
    $accent=imagecolorallocate($im,243,255,56);
    imagefilledrectangle($im,0,0,$w,$h,$bg);
    imagefilledrectangle($im,0,0,$w,26,$accent);

    drawCardText($im,$font,'TYPE ME',42,70,105,$white);
    drawCardText($im,$font,'DORM SPECIES REPORT',22,70,150,$muted);
    imageline($im,70,195,1010,195,$line);

    drawCardText($im,$font,(string)$payload['name'],70,70,315,$white,9,18);
    drawCardText($im,$font,'宿舍人格挑战 · 4 / 4 COMPLETE',22,74,375,$accent);
    $report=$payload['report'];
    $y=drawCardText($im,$font,(string)$report['title'],42,70,500,$white,15,20);
    $y=drawCardText($im,$font,(string)$report['core'],28,70,$y+35,$muted,20,18);

    $y+=45;
    drawCardText($im,$font,'本宿舍物种组成',24,70,$y,$accent);
    $y+=62;
    foreach (($payload['members'] ?? []) as $member) {
        $slot=(int)($member['slot_no']??0);
        $type=(string)($member['type']??'');
        $name=(string)($member['name']??'');
        $role=(string)($member['role']??'');
        drawCardText($im,$font,sprintf('%d  %s · %s',$slot,$type,$name),28,70,$y,$white);
        drawCardText($im,$font,$role,20,660,$y,$muted,12,12);
        $y+=62;
    }

    $y+=20;
    imageline($im,70,$y,1010,$y,$line);
    $y+=62;
    foreach (($report['metrics'] ?? []) as $metric) {
        $name=(string)($metric['name']??'');
        $value=max(0,min(100,(int)($metric['value']??0)));
        drawCardText($im,$font,$name,22,70,$y,$white);
        drawCardText($im,$font,$value.'%',22,900,$y,$accent);
        imagefilledrectangle($im,70,$y+19,980,$y+33,$line);
        imagefilledrectangle($im,70,$y+19,70+(int)(910*$value/100),$y+33,$accent);
        $y+=70;
    }

    $qr=fetchQrImage((string)$payload['invite_url']);
    $qrSize=300; $qrX=70; $qrY=1490;
    imagefilledrectangle($im,$qrX-14,$qrY-14,$qrX+$qrSize+14,$qrY+$qrSize+14,$white);
    imagecopyresampled($im,$qr,$qrX,$qrY,0,0,$qrSize,$qrSize,imagesx($qr),imagesy($qr));
    imagedestroy($qr);
    drawCardText($im,$font,'测测你们宿舍是什么物种',29,430,1580,$white,12,15);
    drawCardText($im,$font,'扫码创建 / 加入宿舍人格挑战',21,430,1695,$muted,16,12);
    drawCardText($im,$font,'娱乐化宿舍报告 · 不是心理测量或医学结论',18,70,1870,$muted);

    $filename='dorm_'.strtolower((string)$payload['invite_code']).'_'.bin2hex(random_bytes(5)).'.png';
    if (!empty(getConfig()['card_inline_response'])) {
        ob_start();
        $ok=imagepng($im,null,8);
        $bytes=ob_get_clean();
        imagedestroy($im);
        if(!$ok||!is_string($bytes)||$bytes==='') throw new RuntimeException('宿舍门牌 PNG 编码失败');
        return ['filename'=>$filename,'url'=>'data:image/png;base64,'.base64_encode($bytes),'width'=>$w,'height'=>$h,'inline'=>true];
    }

    $dir=(string)getConfig()['storage_cards'];
    if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir)){imagedestroy($im);throw new RuntimeException('无法创建图片目录');}
    $path=$dir.DIRECTORY_SEPARATOR.$filename;
    if(!imagepng($im,$path,8)){imagedestroy($im);throw new RuntimeException('宿舍门牌 PNG 写入失败');}
    imagedestroy($im);
    return ['filename'=>$filename,'url'=>'/storage/cards/'.$filename,'width'=>$w,'height'=>$h,'inline'=>false];
}
