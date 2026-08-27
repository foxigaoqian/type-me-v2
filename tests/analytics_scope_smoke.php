<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/analytics_dashboard.php';

function expectAnalytics(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$rows = [
    ['source'=>'direct','anonymous_user_id'=>'u1','event_name'=>'landing_view'],
    ['source'=>'direct','anonymous_user_id'=>'u1','event_name'=>'landing_view'],
    ['source'=>'server-smoke','anonymous_user_id'=>'smoke','event_name'=>'landing_view'],
    ['source'=>'share','campaign'=>'developer-check','anonymous_user_id'=>'dev','event_name'=>'landing_view'],
];

$operations = analyticsFilterScope($rows, 'operations');
expectAnalytics(count($operations) === 2, 'internal rows must be excluded from operations scope');
expectAnalytics(count(analyticsFilterScope($rows, 'all')) === 4, 'all scope must preserve every row');
expectAnalytics(count(analyticsUserSet($operations, ['landing_view'])) === 1, 'UV must deduplicate repeated events');
expectAnalytics(analyticsRate(8, 11) === 100.0, 'bounded conversion must never exceed 100%');
expectAnalytics(!analyticsIsInternalValue('contest'), 'partial word test must not be treated as internal');
expectAnalytics(analyticsIsInternalValue('test-mobile'), 'test-prefixed source must be internal');

echo "Analytics scope smoke test passed\n";
