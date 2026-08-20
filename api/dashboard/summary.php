<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/analytics.php';
require_once dirname(__DIR__) . '/lib/personality.php';
requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);

$events = readNdjson(analyticsStoragePath('events.ndjson'));
$results = readNdjson(analyticsStoragePath('test-results.ndjson'));
$attempts = readNdjson(analyticsStoragePath('test-attempts.ndjson'));
$orders = array_values(readOrders());
$now = time();

function eventTs(array $e): int { return strtotime((string)($e['timestamp'] ?? $e['completed_at'] ?? $e['started_at'] ?? $e['created_at'] ?? '')) ?: 0; }
function since(array $rows, int $seconds): array { global $now; return array_values(array_filter($rows, fn($r)=>eventTs($r) >= $now-$seconds)); }
function countEvent(array $events, string $name): int { return count(array_filter($events, fn($e)=>($e['event_name']??'')===$name)); }
function uniqueEventUsers(array $events, string $name): int { $u=[]; foreach($events as $e) if(($e['event_name']??'')===$name) $u[$e['anonymous_user_id']??$e['session_id']??uniqid('',true)]=1; return count($u); }
function rate(int $a,int $b): float { return $a>0?round($b/$a*100,1):0.0; }

$todayStart = strtotime(date('Y-m-d 00:00:00'));
$yesterdayStart = $todayStart-86400;
$landingByWindow = function(int $start,int $end) use($events): int {
    $u=[];
    foreach($events as $e){$t=eventTs($e);if(($e['event_name']??'')==='landing_view'&&$t>=$start&&$t<$end)$u[$e['anonymous_user_id']??$e['session_id']??uniqid('',true)]=1;}
    return count($u);
};

$events30=since($events,30*86400);$events7=since($events,7*86400);
$landing30=uniqueEventUsers($events30,'landing_view');
$start30=countEvent($events30,'test_start')+countEvent($events30,'viral_test_start');
$complete30=countEvent($events30,'test_complete')+countEvent($events30,'viral_test_complete');

$sourceStats=[];
foreach($events30 as $e){
    $source=(string)($e['source']??'direct');if($source==='')$source='direct';
    if(!isset($sourceStats[$source]))$sourceStats[$source]=['source'=>$source,'landing_users'=>[],'complete'=>0,'share'=>0,'product'=>0,'paid'=>0];
    if(($e['event_name']??'')==='landing_view')$sourceStats[$source]['landing_users'][$e['anonymous_user_id']??$e['session_id']??uniqid('',true)]=1;
    if(in_array(($e['event_name']??''),['test_complete','viral_test_complete'],true))$sourceStats[$source]['complete']++;
    if(($e['event_name']??'')==='share_click')$sourceStats[$source]['share']++;
    if(($e['event_name']??'')==='product_view')$sourceStats[$source]['product']++;
    if(($e['event_name']??'')==='payment_success')$sourceStats[$source]['paid']++;
}
foreach($sourceStats as &$s){$uv=count($s['landing_users']);unset($s['landing_users']);$s['uv']=$uv;$s['complete_rate']=rate($uv,$s['complete']);$s['share_rate']=rate($s['complete'],$s['share']);$s['product_ctr']=rate($s['complete'],$s['product']);$s['paid_rate']=rate($s['product'],$s['paid']);}unset($s);

$questionViews=array_fill(1,12,0);
foreach($events30 as $e){if(($e['event_name']??'')==='question_view'){$i=(int)($e['question_index']??0);if($i>=1&&$i<=12)$questionViews[$i]++;}}
$questionDrop=[];
for($i=1;$i<=12;$i++){$reached=$questionViews[$i];$next=$i<12?$questionViews[$i+1]:$complete30;$exit=max(0,$reached-$next);$questionDrop[]=['question'=>$i,'reached'=>$reached,'exits'=>$exit,'exit_rate'=>rate($reached,$exit)];}

$duration=[];$startsByAttempt=[];
foreach($attempts as $a)if(!empty($a['attempt_id']))$startsByAttempt[$a['attempt_id']]=eventTs($a);
foreach($results as $r){$id=(string)($r['attempt_id']??'');if($id!==''&&isset($startsByAttempt[$id])){$d=eventTs($r)-$startsByAttempt[$id];if($d>=0&&$d<3600)$duration[]=$d;}}
$avgDuration=$duration?round(array_sum($duration)/count($duration),1):0;

$personalityDefs=loadPersonalityConfig()['personalities']??[];$personalityCounts=array_fill_keys(array_keys($personalityDefs),0);
foreach($results as $r){$k=(string)($r['primary_personality']??'');if(isset($personalityCounts[$k]))$personalityCounts[$k]++;}
$totalResults=array_sum($personalityCounts);$personalityDistribution=[];
foreach($personalityCounts as $k=>$count)$personalityDistribution[]=['key'=>$k,'type'=>$personalityDefs[$k]['type']??$k,'name'=>$personalityDefs[$k]['cn']??$k,'count'=>$count,'percent'=>$totalResults>=100?round($count/max(1,$totalResults)*100,1):null];
usort($personalityDistribution,fn($a,$b)=>$b['count']<=>$a['count']);

$resultViews=countEvent($events30,'result_view');$shareClicks=countEvent($events30,'share_click');$shareOpens=countEvent($events30,'share_open');$viralStarts=countEvent($events30,'viral_test_start');$viralCompletes=countEvent($events30,'viral_test_complete');
$productViews=countEvent($events30,'product_view');$sizeSelects=countEvent($events30,'size_select');$addCarts=countEvent($events30,'add_cart');$checkouts=countEvent($events30,'checkout_start');$paidEvents=countEvent($events30,'payment_success');

$paidOrders=array_values(array_filter($orders,fn($o)=>in_array((string)($o['status']??''),['PAID','SUCCESS'],true)));
$refundOrders=array_values(array_filter($orders,fn($o)=>in_array((string)($o['status']??''),['REFUND_REQUESTED','REFUNDED','PROCESSING'],true)));
$orderInWindow=function(array $orders,int $seconds)use($now):array{return array_values(array_filter($orders,fn($o)=>eventTs($o)>=$now-$seconds));};
$todayOrders=array_values(array_filter($paidOrders,fn($o)=>eventTs($o)>=$todayStart));$paid7=$orderInWindow($paidOrders,7*86400);$paid30=$orderInWindow($paidOrders,30*86400);
$gmv=function($list):int{return array_sum(array_map(fn($o)=>(int)($o['amount_pay_fen']??$o['amount']??0),$list));};
$paidUsers=[];foreach($paid30 as $o)$paidUsers[$o['uid']??$o['out_trade_no']??uniqid('',true)]=1;

$skuSales=['personality'=>[],'size'=>[],'color'=>[]];
foreach($paid30 as $o){foreach(($o['items']??[]) as $item){$qty=(int)($item['qty']??1);foreach([['personality','personality'],['size','size'],['color','color']] as [$bucket,$field]){$v=(string)($item[$field]??'');if($v!=='')$skuSales[$bucket][$v]=($skuSales[$bucket][$v]??0)+$qty;}}}

$personalityValue=[];
foreach($personalityDefs as $key=>$def){$tests=0;$shares=0;$products=0;$paid=0;foreach($events30 as $e){if(($e['primary_personality']??'')!==$key)continue;$n=$e['event_name']??'';if(in_array($n,['test_complete','viral_test_complete'],true))$tests++;elseif($n==='share_click')$shares++;elseif($n==='product_view')$products++;elseif($n==='payment_success')$paid++;}$personalityValue[]=['key'=>$key,'name'=>$def['cn']??$key,'tests'=>$tests,'share_rate'=>rate($tests,$shares),'product_ctr'=>rate($tests,$products),'paid_rate'=>rate($products,$paid),'paid'=>$paid];}

$schoolStats=[];
foreach($events30 as $e){$school=(string)($e['school_id']??'');if($school==='')continue;if(!isset($schoolStats[$school]))$schoolStats[$school]=['school_id'=>$school,'complete'=>0,'share'=>0,'paid'=>0];$n=$e['event_name']??'';if(in_array($n,['test_complete','viral_test_complete'],true))$schoolStats[$school]['complete']++;elseif($n==='share_click')$schoolStats[$school]['share']++;elseif($n==='payment_success')$schoolStats[$school]['paid']++;}
foreach($schoolStats as &$s){$s['share_rate']=rate($s['complete'],$s['share']);}unset($s);

jsonResponse([
 'code'=>0,
 'traffic'=>['today_landing_uv'=>$landingByWindow($todayStart,$now+1),'yesterday_landing_uv'=>$landingByWindow($yesterdayStart,$todayStart),'landing_uv_7d'=>uniqueEventUsers($events7,'landing_view'),'landing_uv_30d'=>$landing30,'sources'=>array_values($sourceStats)],
 'test_funnel'=>['landing_uv'=>$landing30,'test_start'=>$start30,'test_complete'=>$complete30,'landing_to_start'=>rate($landing30,$start30),'start_to_complete'=>rate($start30,$complete30),'average_test_duration_seconds'=>$avgDuration,'questions'=>$questionDrop],
 'personality_distribution'=>['total'=>$totalResults,'items'=>$personalityDistribution],
 'viral'=>['result_views'=>$resultViews,'share_clicks'=>$shareClicks,'share_rate'=>rate($resultViews,$shareClicks),'share_opens'=>$shareOpens,'share_open_rate'=>rate($shareClicks,$shareOpens),'viral_test_start'=>$viralStarts,'viral_test_complete'=>$viralCompletes,'k_factor'=>$complete30>0?round($viralCompletes/$complete30,3):0],
 'product_funnel'=>['result_view'=>$resultViews,'product_view'=>$productViews,'size_select'=>$sizeSelects,'add_cart'=>$addCarts,'checkout'=>$checkouts,'paid'=>$paidEvents,'result_to_product_ctr'=>rate($resultViews,$productViews),'product_to_add_cart'=>rate($productViews,$addCarts),'product_to_checkout'=>rate($productViews,$checkouts),'product_to_paid'=>rate($productViews,$paidEvents)],
 'sales'=>['today_orders'=>count($todayOrders),'today_gmv_fen'=>$gmv($todayOrders),'gmv_7d_fen'=>$gmv($paid7),'gmv_30d_fen'=>$gmv($paid30),'paid_users_30d'=>count($paidUsers),'aov_fen'=>count($paid30)?round($gmv($paid30)/count($paid30)):0,'refund_count'=>count($refundOrders),'refund_rate'=>rate(count($orders),count($refundOrders)),'sku_sales'=>$skuSales],
 'personality_value'=>$personalityValue,
 'channel_value'=>array_values($sourceStats),
 'school_value'=>array_values($schoolStats),
]);
