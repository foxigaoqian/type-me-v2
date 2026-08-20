<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/personality.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
$rows = readNdjson(analyticsStoragePath('test-results.ndjson'));
$defs = loadPersonalityConfig()['personalities'] ?? [];
$counts = array_fill_keys(array_keys($defs),0);
$total = 0;
foreach($rows as $row){
  if(($row['event']??'')!=='test_result') continue;
  $key=(string)($row['primary_personality']??'');
  if(isset($counts[$key])){$counts[$key]++;$total++;}
}
$items=[];
foreach($counts as $key=>$count){
  $items[]=['key'=>$key,'count'=>$count,'percent'=>$total>=100?round($count/max(1,$total)*100,1):null];
}
usort($items,fn($a,$b)=>$b['count']<=>$a['count']);
jsonResponse(['code'=>0,'total'=>$total,'items'=>$items]);
