<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR,"CLI only\n");
    exit(2);
}

require_once dirname(__DIR__) . '/api/config.php';

$root = dirname(__DIR__);
$source = $root . DIRECTORY_SEPARATOR . 'storage';
$target = rtrim((string)getConfig()['private_storage_path'],'/\\');
$force = in_array('--force',$argv,true);

if (!is_dir($source)) {
    fwrite(STDOUT,"Legacy storage directory does not exist; nothing to migrate.\n");
    exit(0);
}
if (!is_dir($target) && !mkdir($target,0770,true) && !is_dir($target)) {
    fwrite(STDERR,"Cannot create target: $target\n");
    exit(1);
}

$copied=0;$skipped=0;$errors=0;
foreach (scandir($source) ?: [] as $name) {
    if ($name === '.' || $name === '..') continue;
    if (!preg_match('/\.(json|ndjson)$/i',$name)) continue;
    $from=$source.DIRECTORY_SEPARATOR.$name;
    if (!is_file($from)) continue;
    $to=$target.DIRECTORY_SEPARATOR.$name;
    if (is_file($to) && !$force) {
        fwrite(STDOUT,"SKIP $name (target exists)\n");
        $skipped++;
        continue;
    }
    if (!copy($from,$to)) {
        fwrite(STDERR,"ERROR $name\n");
        $errors++;
        continue;
    }
    @chmod($to,0660);
    fwrite(STDOUT,"COPIED $name\n");
    $copied++;
}

fwrite(STDOUT,json_encode(['target'=>$target,'copied'=>$copied,'skipped'=>$skipped,'errors'=>$errors],JSON_UNESCAPED_UNICODE).PHP_EOL);
exit($errors>0?1:0);
