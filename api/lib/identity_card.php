<?php

declare(strict_types=1);

require_once __DIR__ . '/personality.php';

function identityCardFontPath(): string
{
    $configured = trim((string)(getConfig()['card_font_path'] ?? ''));
    $candidates = array_filter([
        $configured,
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/opentype/noto/NotoSansCJKsc-Regular.otf',
        '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
        '/System/Library/Fonts/PingFang.ttc',
    ]);
    foreach ($candidates as $font) {
        if (is_file($font) && is_readable($font)) return $font;
    }
    throw new RuntimeException('未找到可用中文字体，请配置 CARD_FONT_PATH');
}

function hexRgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return [243,255,56];
    return [hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
}

function cardTextLines(string $text, int $maxChars): array
{
    if (!extension_loaded('mbstring')) throw new RuntimeException('服务器缺少 mbstring 扩展');
    $text = trim($text);
    if ($text === '') return [''];
    $lines = [];
    while (mb_strlen($text, 'UTF-8') > $maxChars) {
        $slice = mb_substr($text, 0, $maxChars, 'UTF-8');
        $break = max((int)mb_strrpos($slice, '，', 0, 'UTF-8'), (int)mb_strrpos($slice, '。', 0, 'UTF-8'), (int)mb_strrpos($slice, ' ', 0, 'UTF-8'));
        if ($break < (int)floor($maxChars * 0.55)) $break = $maxChars;
        else $break++;
        $lines[] = trim(mb_substr($text, 0, $break, 'UTF-8'));
        $text = trim(mb_substr($text, $break, null, 'UTF-8'));
    }
    if ($text !== '') $lines[] = $text;
    return $lines;
}

function drawCardText($im, string $font, string $text, int $size, int $x, int $y, int $color, int $maxChars = 0, int $lineGap = 14): int
{
    $lines = $maxChars > 0 ? cardTextLines($text, $maxChars) : [$text];
    foreach ($lines as $line) {
        imagettftext($im, $size, 0, $x, $y, $color, $font, $line);
        $y += $size + $lineGap;
    }
    return $y;
}

function fetchQrImage(string $targetUrl)
{
    $base = rtrim((string)(getConfig()['qr_api_url'] ?? ''), '?&');
    if ($base === '') throw new RuntimeException('QR_API_URL 未配置');
    $url = $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query([
        'size' => '360x360',
        'format' => 'png',
        'margin' => 8,
        'data' => $targetUrl,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_USERAGENT=>'type-me-v2/2.0']);
    $bytes = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($bytes) || $bytes === '' || $http < 200 || $http >= 300) {
        throw new RuntimeException('二维码生成失败' . ($err !== '' ? ': ' . $err : ''));
    }
    $qr = @imagecreatefromstring($bytes);
    if (!$qr) throw new RuntimeException('二维码图片无效');
    return $qr;
}

function identityCardVisual(array $primary)
{
    if (!function_exists('imagecreatefromwebp')) return null;
    $key = (string)($primary['key'] ?? '');
    $relative = (string)(loadV2Config()['media_config']['personalities'][$key]['main'] ?? '');
    if ($relative === '') return null;
    $root = realpath(dirname(__DIR__, 2));
    $path = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($relative, '/\\'));
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_readable($path)) return null;
    return @imagecreatefromwebp($path) ?: null;
}

function drawCoverImage($target, $source, int $x, int $y, int $width, int $height): void
{
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    if ($sourceWidth < 1 || $sourceHeight < 1) return;
    $targetRatio = $width / $height;
    $sourceRatio = $sourceWidth / $sourceHeight;
    if ($sourceRatio > $targetRatio) {
        $cropHeight = $sourceHeight;
        $cropWidth = (int)round($sourceHeight * $targetRatio);
        $sourceX = (int)floor(($sourceWidth - $cropWidth) / 2);
        $sourceY = 0;
    } else {
        $cropWidth = $sourceWidth;
        $cropHeight = (int)round($sourceWidth / $targetRatio);
        $sourceX = 0;
        $sourceY = (int)floor(($sourceHeight - $cropHeight) / 2);
    }
    imagecopyresampled($target, $source, $x, $y, $sourceX, $sourceY, $width, $height, $cropWidth, $cropHeight);
}

function renderIdentityCard(array $primary, array $secondary, array $sample, string $shareUrl): array
{
    if (!extension_loaded('gd') || !function_exists('imagettftext')) throw new RuntimeException('服务器缺少 GD/FreeType 扩展');
    $font = identityCardFontPath();

    $w = 1080; $h = 1920;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);
    $bg = imagecolorallocate($im, 14, 14, 15);
    $white = imagecolorallocate($im, 250, 250, 247);
    $muted = imagecolorallocate($im, 166, 166, 166);
    $line = imagecolorallocate($im, 55, 55, 58);
    [$ar,$ag,$ab] = hexRgb((string)($primary['accent'] ?? '#f3ff38'));
    $accent = imagecolorallocate($im, $ar, $ag, $ab);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);
    $visual = identityCardVisual($primary);
    if ($visual) {
        drawCoverImage($im, $visual, 0, 0, $w, 690);
        imagedestroy($visual);
        $visualShade = imagecolorallocatealpha($im, 0, 0, 0, 42);
        imagefilledrectangle($im, 0, 0, $w, 690, $visualShade);
    }
    imagefilledrectangle($im, 0, 0, $w, 26, $accent);

    drawCardText($im,$font,'TYPE ME',42,70,105,$white);
    drawCardText($im,$font,'CAMPUS SPECIES ID',22,70,150,$muted);
    imageline($im,70,195,1010,195,$line);

    drawCardText($im,$font,(string)$primary['type'],34,70,275,$accent);
    drawCardText($im,$font,(string)$primary['cn'],86,70,390,$white);
    drawCardText($im,$font,(string)$primary['en'],28,74,445,$muted);
    $cardMeme = (string)($primary['identity_card_meme'] ?? $primary['core'] ?? '');
    $y = drawCardText($im,$font,$cardMeme,42,70,560,$white,16,20);
    $y += 35;

    foreach (($primary['metrics'] ?? []) as $metric) {
        $name = (string)($metric['name'] ?? '');
        $value = max(0,min(100,(int)($metric['value'] ?? 0)));
        drawCardText($im,$font,$name,24,70,$y,$white);
        drawCardText($im,$font,$value.'%',24,900,$y,$accent);
        imagefilledrectangle($im,70,$y+20,980,$y+36,$line);
        imagefilledrectangle($im,70,$y+20,70+(int)(910*$value/100),$y+36,$accent);
        $y += 82;
    }

    $y += 20;
    imageline($im,70,$y,1010,$y,$line);
    $y += 60;
    drawCardText($im,$font,'隐藏人格',23,70,$y,$muted);
    $y += 55;
    drawCardText($im,$font,(string)$secondary['type'].' · '.(string)$secondary['cn'],34,70,$y,$white);
    drawCardText($im,$font,(string)$secondary['en'],22,70,$y+42,$muted);

    $sampleText = ((int)($sample['total'] ?? 0) >= 100 && $sample['percent'] !== null)
        ? '当前 TYPE ME 用户中：'.(string)$sample['percent'].'%'
        : '人格样本正在积累中';
    drawCardText($im,$font,$sampleText,24,70,$y+115,$muted);

    $qr = fetchQrImage($shareUrl);
    $qrSize = 320;
    $qrX = 70; $qrY = 1480;
    imagefilledrectangle($im,$qrX-14,$qrY-14,$qrX+$qrSize+14,$qrY+$qrSize+14,$white);
    imagecopyresampled($im,$qr,$qrX,$qrY,0,0,$qrSize,$qrSize,imagesx($qr),imagesy($qr));
    imagedestroy($qr);
    drawCardText($im,$font,'测测你是什么校园物种',30,440,1570,$white,11,14);
    drawCardText($im,$font,'扫码进入 TYPE ME',22,440,1690,$muted);
    drawCardText($im,$font,'娱乐测试结果 · 不是心理测量或医学结论',18,70,1870,$muted);

    $filename = 'card_' . bin2hex(random_bytes(12)) . '.png';
    if (!empty(getConfig()['card_inline_response'])) {
        ob_start();
        $ok = imagepng($im, null, 8);
        $bytes = ob_get_clean();
        imagedestroy($im);
        if (!$ok || !is_string($bytes) || $bytes === '') throw new RuntimeException('身份证 PNG 编码失败');
        return [
            'filename'=>$filename,
            'path'=>null,
            'url'=>'data:image/png;base64,'.base64_encode($bytes),
            'width'=>$w,
            'height'=>$h,
            'inline'=>true,
        ];
    }

    $dir = (string)getConfig()['storage_cards'];
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        imagedestroy($im);
        throw new RuntimeException('无法创建身份证目录');
    }
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!imagepng($im, $path, 8)) {
        imagedestroy($im);
        throw new RuntimeException('身份证 PNG 写入失败');
    }
    imagedestroy($im);
    return ['filename'=>$filename,'path'=>$path,'url'=>'/storage/cards/'.$filename,'width'=>$w,'height'=>$h,'inline'=>false];
}

function findOwnedTestResult(string $attemptId, string $uid): ?array
{
    $rows = readNdjson(analyticsStoragePath('test-results.ndjson'));
    for ($i = count($rows)-1; $i >= 0; $i--) {
        $row = $rows[$i];
        if (($row['attempt_id'] ?? '') === $attemptId && ($row['uid'] ?? '') === $uid) return $row;
    }
    return null;
}
