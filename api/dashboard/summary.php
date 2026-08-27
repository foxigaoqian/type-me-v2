<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/analytics.php';
require_once dirname(__DIR__) . '/lib/personality.php';
requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'],405);
header('Cache-Control: no-store, max-age=0');

$scope = (string)($_GET['scope'] ?? 'operations');
if (!in_array($scope, ['operations', 'all'], true)) $scope = 'operations';
$allEvents = readNdjson(analyticsStoragePath('events.ndjson'));
$allResults = readNdjson(analyticsStoragePath('test-results.ndjson'));
$allAttempts = readNdjson(analyticsStoragePath('test-attempts.ndjson'));
$allShares = readNdjson(analyticsStoragePath('shares-v2.ndjson'));
$allShareOpens = readNdjson(analyticsStoragePath('share-opens-v2.ndjson'));
$allOrders = array_values(readOrders());
$events = analyticsFilterScope($allEvents, $scope);
$results = analyticsFilterScope($allResults, $scope);
$attempts = analyticsFilterScope($allAttempts, $scope);
$shares = analyticsFilterScope($allShares, $scope);
$shareOpens = analyticsFilterScope($allShareOpens, $scope);
$orders = analyticsFilterScope($allOrders, $scope);
if ($scope === 'operations') {
    $internalUsers = [];
    foreach (array_merge($allEvents, $allAttempts, $allResults) as $row) {
        if (!analyticsIsInternalRow($row)) continue;
        $key = analyticsUserKey($row);if ($key !== '') $internalUsers[$key] = true;
    }
    $shares = array_values(array_filter($shares, static fn(array $row): bool => !isset($internalUsers[(string)($row['referrer_id'] ?? '')])));
    $allowedShareIds = [];foreach ($shares as $row) {$id=(string)($row['share_id']??'');if($id!=='')$allowedShareIds[$id]=true;}
    $shareOpens = array_values(array_filter($shareOpens, static fn(array $row): bool => isset($allowedShareIds[(string)($row['share_id'] ?? '')])));
    $orders = array_values(array_filter($orders, static fn(array $row): bool => !isset($internalUsers[(string)($row['uid'] ?? '')])));
}
$now = time();

function eventTs(array $e): int { return strtotime((string)($e['timestamp'] ?? $e['occurred_at'] ?? $e['completed_at'] ?? $e['started_at'] ?? $e['opened_at'] ?? $e['paid_at'] ?? $e['created_at'] ?? $e['updated_at'] ?? '')) ?: 0; }
function since(array $rows, int $seconds): array { global $now; return array_values(array_filter($rows, fn($r)=>eventTs($r) >= $now-$seconds)); }
function countEvent(array $events, string $name): int { return count(array_filter($events, fn($e)=>($e['event_name']??'')===$name)); }
function uniqueEventUsers(array $events, string $name): int { return count(analyticsUserSet($events,[$name])); }
function rate(int $a,int $b): float { return analyticsRate($a,$b); }
function uniqueDormEventCount(array $events,string $name): int { $d=[]; foreach($events as $e){if(($e['event_name']??'')!==$name)continue;$id=(string)($e['dorm_id']??$e['dorm_code']??'');if($id!=='')$d[$id]=1;}return count($d); }
function uniqueValueSet(array $rows,string $field): array { $set=[];foreach($rows as $row){$value=trim((string)($row[$field]??''));if($value!=='')$set[$value]=true;}return $set; }

$todayStart = strtotime(date('Y-m-d 00:00:00'));
$yesterdayStart = $todayStart-86400;
$landingByWindow = function(int $start,int $end) use($events): int {
    $u=[];
    foreach($events as $e){$t=eventTs($e);if(($e['event_name']??'')==='landing_view'&&$t>=$start&&$t<$end)$u[$e['anonymous_user_id']??$e['session_id']??uniqid('',true)]=1;}
    return count($u);
};

$events30=since($events,30*86400);$events7=since($events,7*86400);
$attempts30=since($attempts,30*86400);
$landingUsers=analyticsUserSet($events30,['landing_view']);
$startUsers=analyticsUserSet($attempts30);
$attemptIds=uniqueValueSet($attempts30,'attempt_id');
$completedRows=array_values(array_filter($results,static fn(array $row):bool=>isset($attemptIds[(string)($row['attempt_id']??'')])));
$completeUsers=analyticsUserSet($completedRows);
$landing30=count($landingUsers);
$start30=count($startUsers);
$complete30=analyticsIntersectCount($startUsers,$completeUsers);
$landingStarts=analyticsIntersectCount($landingUsers,$startUsers);

$sourceStats=[];
foreach($events30 as $e){
    $source=(string)($e['source']??'direct');if($source==='')$source='direct';
    if(!isset($sourceStats[$source]))$sourceStats[$source]=['source'=>$source,'landing_users'=>[],'start_users'=>[],'complete_users'=>[],'share_users'=>[],'product_users'=>[],'paid_users'=>[]];
    $user=analyticsUserKey($e);if($user==='')continue;$name=(string)($e['event_name']??'');
    if($name==='landing_view')$sourceStats[$source]['landing_users'][$user]=true;
    if(in_array($name,['test_start','viral_test_start'],true))$sourceStats[$source]['start_users'][$user]=true;
    if(in_array($name,['test_complete','viral_test_complete'],true))$sourceStats[$source]['complete_users'][$user]=true;
    if($name==='share_click')$sourceStats[$source]['share_users'][$user]=true;
    if($name==='product_view')$sourceStats[$source]['product_users'][$user]=true;
    if($name==='payment_success')$sourceStats[$source]['paid_users'][$user]=true;
}
foreach($sourceStats as &$s){
    $uv=count($s['landing_users']);$starts=analyticsIntersectCount($s['landing_users'],$s['start_users']);$complete=analyticsIntersectCount($s['start_users'],$s['complete_users']);
    $shares=analyticsIntersectCount($s['complete_users'],$s['share_users']);$products=analyticsIntersectCount($s['complete_users'],$s['product_users']);$paid=analyticsIntersectCount($s['product_users'],$s['paid_users']);
    $s=['source'=>$s['source'],'uv'=>$uv,'starts'=>$starts,'complete'=>$complete,'complete_rate'=>rate($starts,$complete),'share_rate'=>rate($complete,$shares),'product_ctr'=>rate($complete,$products),'paid_rate'=>rate($products,$paid)];
}unset($s);

$questionUsers=array_fill(1,12,[]);
foreach($events30 as $e){if(($e['event_name']??'')==='question_view'){$i=(int)($e['question_index']??0);$user=analyticsUserKey($e);if($i>=1&&$i<=12&&$user!=='')$questionUsers[$i][$user]=true;}}
$questionViews=[];foreach($questionUsers as $i=>$users)$questionViews[$i]=count($users);
$questionDrop=[];
for($i=1;$i<=12;$i++){$reached=$questionViews[$i];$next=$i<12?$questionViews[$i+1]:$complete30;$exit=max(0,$reached-$next);$questionDrop[]=['question'=>$i,'reached'=>$reached,'exits'=>$exit,'exit_rate'=>rate($reached,$exit)];}

$duration=[];$startsByAttempt=[];
foreach($attempts30 as $a)if(!empty($a['attempt_id']))$startsByAttempt[$a['attempt_id']]=eventTs($a);
foreach($completedRows as $r){$id=(string)($r['attempt_id']??'');if($id!==''&&isset($startsByAttempt[$id])){$d=eventTs($r)-$startsByAttempt[$id];if($d>=0&&$d<3600)$duration[]=$d;}}
$avgDuration=$duration?round(array_sum($duration)/count($duration),1):0;

$personalityDefs=loadPersonalityConfig()['personalities']??[];$personalityCounts=array_fill_keys(array_keys($personalityDefs),0);
foreach($results as $r){$k=(string)($r['primary_personality']??'');if(isset($personalityCounts[$k]))$personalityCounts[$k]++;}
$totalResults=array_sum($personalityCounts);$personalityDistribution=[];
foreach($personalityCounts as $k=>$count)$personalityDistribution[]=['key'=>$k,'type'=>$personalityDefs[$k]['type']??$k,'name'=>$personalityDefs[$k]['cn']??$k,'count'=>$count,'percent'=>$totalResults>=100?round($count/max(1,$totalResults)*100,1):null];
usort($personalityDistribution,fn($a,$b)=>$b['count']<=>$a['count']);

$resultUsers=analyticsUserSet($events30,['result_view']);
$shareUsers=analyticsUserSet($events30,['share_click']);
$viralStartUsers=analyticsUserSet($events30,['viral_test_start']);
$viralCompleteUsers=analyticsUserSet($events30,['viral_test_complete']);
$productUsers=analyticsUserSet($events30,['product_view']);
$sizeUsers=analyticsUserSet($events30,['size_select']);
$cartUsers=analyticsUserSet($events30,['add_cart']);
$checkoutUsers=analyticsUserSet($events30,['checkout_start']);
$paidEventUsers=analyticsUserSet($events30,['payment_success']);
$resultViews=count($resultUsers);$shareClicks=analyticsIntersectCount($resultUsers,$shareUsers);$viralStarts=count($viralStartUsers);$viralCompletes=count($viralCompleteUsers);
$productViews=analyticsIntersectCount($resultUsers,$productUsers);$sizeSelects=analyticsIntersectCount($productUsers,$sizeUsers);$addCarts=analyticsIntersectCount($productUsers,$cartUsers);$checkouts=analyticsIntersectCount($productUsers,$checkoutUsers);$paidEvents=analyticsIntersectCount($productUsers,$paidEventUsers);
$shares30=since($shares,30*86400);$shareOpens30=since($shareOpens,30*86400);
$createdShareIds=uniqueValueSet($shares30,'share_id');$openedShareIds=[];
foreach($shareOpens30 as $open){$shareId=(string)($open['share_id']??'');if($shareId!==''&&isset($createdShareIds[$shareId]))$openedShareIds[$shareId]=true;}
$shareOpens=count($openedShareIds);

// 宿舍 MVP 漏斗：以独立 dorm_id 为主，避免同一宿舍重复打开/刷新造成虚高。
$dormInviteViews=countEvent($events30,'dorm_invite_view');
$dormCreates=uniqueDormEventCount($events30,'dorm_create');
$dormJoins=countEvent($events30,'dorm_join');
$dormCompletes=uniqueDormEventCount($events30,'dorm_complete');
$dormViews=countEvent($events30,'dorm_view');
$dormShares=countEvent($events30,'dorm_share');
$dormDoorplates=uniqueDormEventCount($events30,'dorm_doorplate_generate');
$dormDoorplateSaves=countEvent($events30,'dorm_doorplate_save');
$dormJoinUsers=uniqueEventUsers($events30,'dorm_join');
$dormCompletedMembers=$dormCreates + $dormJoins;

$paidOrders=array_values(array_filter($orders,fn($o)=>in_array((string)($o['status']??''),['PAID','SUCCESS'],true)));
$refundOrders=array_values(array_filter($orders,fn($o)=>in_array((string)($o['status']??''),['REFUND_REQUESTED','REFUNDED','PROCESSING'],true)));
$orderInWindow=function(array $orders,int $seconds)use($now):array{return array_values(array_filter($orders,fn($o)=>eventTs($o)>=$now-$seconds));};
$todayOrders=array_values(array_filter($paidOrders,fn($o)=>eventTs($o)>=$todayStart));$paid7=$orderInWindow($paidOrders,7*86400);$paid30=$orderInWindow($paidOrders,30*86400);
$gmv=function($list):int{return array_sum(array_map(fn($o)=>(int)($o['amount_pay_fen']??$o['amount']??0),$list));};
$paidUsers=[];foreach($paid30 as $o)$paidUsers[$o['uid']??$o['out_trade_no']??uniqid('',true)]=1;

$skuSales=['personality'=>[],'size'=>[],'color'=>[]];
foreach($paid30 as $o){foreach(($o['items']??[]) as $item){$qty=(int)($item['qty']??1);foreach([['personality','personality'],['size','size'],['color','color']] as [$bucket,$field]){$v=(string)($item[$field]??'');if($v!=='')$skuSales[$bucket][$v]=($skuSales[$bucket][$v]??0)+$qty;}}}

$personalityValue=[];
foreach($personalityDefs as $key=>$def){$tests=[];$sharesByType=[];$productsByType=[];$paidByType=[];foreach($events30 as $e){if(($e['primary_personality']??'')!==$key)continue;$user=analyticsUserKey($e);if($user==='')continue;$n=$e['event_name']??'';if(in_array($n,['test_complete','viral_test_complete'],true))$tests[$user]=true;elseif($n==='share_click')$sharesByType[$user]=true;elseif($n==='product_view')$productsByType[$user]=true;elseif($n==='payment_success')$paidByType[$user]=true;}$testCount=count($tests);$shareCount=analyticsIntersectCount($tests,$sharesByType);$productCount=analyticsIntersectCount($tests,$productsByType);$paidCount=analyticsIntersectCount($productsByType,$paidByType);$personalityValue[]=['key'=>$key,'name'=>$def['cn']??$key,'tests'=>$testCount,'share_rate'=>rate($testCount,$shareCount),'product_ctr'=>rate($testCount,$productCount),'paid_rate'=>rate($productCount,$paidCount),'paid'=>$paidCount];}

$schoolStats=[];
foreach($events30 as $e){$school=(string)($e['school_id']??'');$user=analyticsUserKey($e);if($school===''||$user==='')continue;if(!isset($schoolStats[$school]))$schoolStats[$school]=['school_id'=>$school,'complete_users'=>[],'share_users'=>[],'paid_users'=>[]];$n=$e['event_name']??'';if(in_array($n,['test_complete','viral_test_complete'],true))$schoolStats[$school]['complete_users'][$user]=true;elseif($n==='share_click')$schoolStats[$school]['share_users'][$user]=true;elseif($n==='payment_success')$schoolStats[$school]['paid_users'][$user]=true;}
foreach($schoolStats as &$s){$complete=count($s['complete_users']);$share=analyticsIntersectCount($s['complete_users'],$s['share_users']);$paid=count($s['paid_users']);$s=['school_id'=>$s['school_id'],'complete'=>$complete,'share_rate'=>rate($complete,$share),'paid'=>$paid];}unset($s);

$seedStats=[];
foreach($events30 as $e){
    $seed=analyticsDimension($e['seed_id']??'',64);$user=analyticsUserKey($e);if($seed===''||$user==='')continue;
    if(!isset($seedStats[$seed]))$seedStats[$seed]=['seed_id'=>$seed,'source'=>(string)($e['source']??''),'campaign'=>(string)($e['campaign']??''),'landing'=>[],'start'=>[],'complete'=>[],'share'=>[],'viral_complete'=>[],'paid'=>[]];
    $n=(string)($e['event_name']??'');
    if($n==='landing_view')$seedStats[$seed]['landing'][$user]=true;
    if(in_array($n,['test_start','viral_test_start'],true))$seedStats[$seed]['start'][$user]=true;
    if(in_array($n,['test_complete','viral_test_complete'],true))$seedStats[$seed]['complete'][$user]=true;
    if($n==='share_click')$seedStats[$seed]['share'][$user]=true;
    if($n==='viral_test_complete')$seedStats[$seed]['viral_complete'][$user]=true;
    if($n==='payment_success')$seedStats[$seed]['paid'][$user]=true;
}
foreach($seedStats as &$seed){$uv=count($seed['landing']);$starts=analyticsIntersectCount($seed['landing'],$seed['start']);$completes=analyticsIntersectCount($seed['start'],$seed['complete']);$shareUsersCount=analyticsIntersectCount($seed['complete'],$seed['share']);$viralCompleteCount=count($seed['viral_complete']);$paid=count($seed['paid']);$seed=['seed_id'=>$seed['seed_id'],'source'=>$seed['source'],'campaign'=>$seed['campaign'],'uv'=>$uv,'starts'=>$starts,'completes'=>$completes,'complete_rate'=>rate($starts,$completes),'share_users'=>$shareUsersCount,'viral_completes'=>$viralCompleteCount,'paid'=>$paid];}unset($seed);
usort($seedStats,static fn(array $a,array $b):int=>$b['uv']<=>$a['uv']);

jsonResponse([
 'code'=>0,
 'scope'=>[
    'mode'=>$scope,
    'label'=>$scope==='all'?'全部数据':'运营数据',
    'excluded'=>[
        'events'=>count($allEvents)-count($events),
        'attempts'=>count($allAttempts)-count($attempts),
        'results'=>count($allResults)-count($results),
        'shares'=>count($allShares)-count($shares),
        'orders'=>count($allOrders)-count($orders),
    ],
    'excluded_sources'=>['server-smoke','developer','admin','test','preview'],
 ],
 'traffic'=>['today_landing_uv'=>$landingByWindow($todayStart,$now+1),'yesterday_landing_uv'=>$landingByWindow($yesterdayStart,$todayStart),'landing_uv_7d'=>uniqueEventUsers($events7,'landing_view'),'landing_uv_30d'=>$landing30,'sources'=>array_values($sourceStats)],
 'test_funnel'=>['landing_uv'=>$landing30,'test_start'=>$start30,'test_complete'=>$complete30,'landing_to_start'=>rate($landing30,$landingStarts),'start_to_complete'=>rate($start30,$complete30),'average_test_duration_seconds'=>$avgDuration,'questions'=>$questionDrop],
 'personality_distribution'=>['total'=>$totalResults,'items'=>$personalityDistribution],
 'viral'=>['result_views'=>$resultViews,'share_clicks'=>$shareClicks,'share_rate'=>rate($resultViews,$shareClicks),'share_links'=>count($createdShareIds),'share_opens'=>$shareOpens,'share_open_rate'=>rate(count($createdShareIds),$shareOpens),'viral_test_start'=>$viralStarts,'viral_test_complete'=>$viralCompletes,'k_factor'=>$complete30>0?round($viralCompletes/$complete30,3):0],
 'dorm_funnel'=>[
    'invite_views'=>$dormInviteViews,
    'dorm_creates'=>$dormCreates,
    'dorm_joins'=>$dormJoins,
    'unique_join_users'=>$dormJoinUsers,
    'dorm_views'=>$dormViews,
    'dorm_completes'=>$dormCompletes,
    'create_to_complete_rate'=>rate($dormCreates,$dormCompletes),
    'avg_completed_members_per_created_dorm'=>$dormCreates>0?round($dormCompletedMembers/$dormCreates,2):0,
    'dorm_shares'=>$dormShares,
    'doorplate_generates'=>$dormDoorplates,
    'doorplate_saves'=>$dormDoorplateSaves,
    'complete_to_doorplate_rate'=>rate($dormCompletes,$dormDoorplates),
 ],
 'product_funnel'=>['result_view'=>$resultViews,'product_view'=>$productViews,'size_select'=>$sizeSelects,'add_cart'=>$addCarts,'checkout'=>$checkouts,'paid'=>$paidEvents,'result_to_product_ctr'=>rate($resultViews,$productViews),'product_to_add_cart'=>rate($productViews,$addCarts),'product_to_checkout'=>rate($productViews,$checkouts),'product_to_paid'=>rate($productViews,$paidEvents)],
 'sales'=>['today_orders'=>count($todayOrders),'today_gmv_fen'=>$gmv($todayOrders),'gmv_7d_fen'=>$gmv($paid7),'gmv_30d_fen'=>$gmv($paid30),'paid_users_30d'=>count($paidUsers),'aov_fen'=>count($paid30)?round($gmv($paid30)/count($paid30)):0,'refund_count'=>count($refundOrders),'refund_rate'=>rate(count($orders),count($refundOrders)),'sku_sales'=>$skuSales],
 'personality_value'=>$personalityValue,
 'channel_value'=>array_values($sourceStats),
 'school_value'=>array_values($schoolStats),
 'seed_attribution'=>array_values($seedStats),
]);
