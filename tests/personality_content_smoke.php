<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/personality.php';

$config = loadPersonalityConfig();
$personalities = $config['personalities'] ?? [];
if (count($personalities) !== 8) throw new RuntimeException('Expected 8 personality types');

$required = [
    'type','cn','en','core','description','metrics','metric_bias','skill','weakness','accent',
    'main_meme','identity_card_meme','friend_meme','tshirt_copy','share_copy','content_source','content_version',
];
foreach ($personalities as $key => $personality) {
    foreach ($required as $field) {
        if (!array_key_exists($field, $personality)) {
            throw new RuntimeException($key . ' missing ' . $field);
        }
    }
    if (count((array)$personality['metrics']) !== 4 || count((array)$personality['metric_bias']) !== 4) {
        throw new RuntimeException($key . ' metrics invalid');
    }
}

$questions = loadQuizConfig()['questions'] ?? [];
$result = scoreAnswers(array_fill(0, count($questions), 0));
if (empty($result['primary']['key']) || empty($result['secondary']['key'])) {
    throw new RuntimeException('Scoring result invalid');
}

echo json_encode([
    'ok'=>true,
    'types'=>count($personalities),
    'sources'=>array_count_values(array_map(static fn(array $item): string => (string)$item['content_source'], $personalities)),
    'algorithm'=>$result['algorithm'],
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
