<?php
declare(strict_types=1);

require_once __DIR__ . '/analytics.php';

function loadV2Config(): array
{
    static $all = null;
    if ($all !== null) return $all;
    $file = dirname(__DIR__, 2) . '/config/v2.json';
    $raw = file_get_contents($file);
    $all = json_decode((string)$raw, true);
    if (!is_array($all)) throw new RuntimeException('config/v2.json invalid');
    return $all;
}

function loadQuizConfig(): array
{
    $data = loadV2Config()['quiz'] ?? null;
    if (!is_array($data)) throw new RuntimeException('config/v2.json quiz invalid');
    return $data;
}

function loadPersonalityConfig(): array
{
    $data = loadV2Config()['personality_config'] ?? null;
    if (!is_array($data)) throw new RuntimeException('config/v2.json personality_config invalid');
    return $data;
}

function stableTieValue(array $answers, string $key): string
{
    return hash('sha256', implode(',', $answers) . '|' . $key);
}

function buildMetrics(array $personality, int $score): array
{
    $normalized = min(1, max(0, $score / 36));
    $names = $personality['metrics'] ?? [];
    $biases = $personality['metric_bias'] ?? [0,0,0,0];
    $items = [];
    foreach ($names as $i => $name) {
        $value = (int)round(36 + ($normalized * 50) + (int)($biases[$i] ?? 0));
        $value = max(8, min(97, $value));
        $items[] = ['name' => (string)$name, 'value' => $value];
    }
    return $items;
}

function scoreAnswers(array $answers, ?string $tieChoice = null): array
{
    $quiz = loadQuizConfig();
    $defs = loadPersonalityConfig()['personalities'] ?? [];
    $questions = $quiz['questions'] ?? [];
    if (count($answers) !== count($questions)) throw new InvalidArgumentException('answers count mismatch');

    $scores = array_fill_keys(array_keys($defs), 0);
    $last4 = array_fill_keys(array_keys($defs), 0);

    foreach ($questions as $qi => $question) {
        $answerIndex = (int)($answers[$qi] ?? -1);
        $options = $question['options'] ?? [];
        if (!isset($options[$answerIndex])) throw new InvalidArgumentException('invalid answer at question ' . ($qi + 1));
        foreach (($options[$answerIndex]['weights'] ?? []) as $key => $value) {
            if (!array_key_exists($key, $scores)) continue;
            $scores[$key] += (int)$value;
            if ($qi >= count($questions) - 4) $last4[$key] += (int)$value;
        }
    }

    $keys = array_keys($scores);
    usort($keys, static function (string $a, string $b) use ($scores, $last4, $answers, $tieChoice): int {
        if ($scores[$a] !== $scores[$b]) return $scores[$b] <=> $scores[$a];
        if ($last4[$a] !== $last4[$b]) return $last4[$b] <=> $last4[$a];
        if ($tieChoice !== null) {
            if ($a === $tieChoice && $b !== $tieChoice) return -1;
            if ($b === $tieChoice && $a !== $tieChoice) return 1;
        }
        return strcmp(stableTieValue($answers, $a), stableTieValue($answers, $b));
    });

    $make = static function (string $key) use ($defs, $scores): array {
        $p = $defs[$key];
        $p['key'] = $key;
        $p['score'] = $scores[$key];
        $p['metrics'] = buildMetrics($p, $scores[$key]);
        return $p;
    };

    return ['primary' => $make($keys[0]), 'secondary' => $make($keys[1]), 'scores' => $scores, 'last4_scores' => $last4];
}

function appendTestResult(array $row): void
{
    appendNdjson(analyticsStoragePath('test-results.ndjson'), $row);
}

function personalitySample(string $key): array
{
    $rows = readNdjson(analyticsStoragePath('test-results.ndjson'));
    $total = 0;
    $count = 0;
    foreach ($rows as $row) {
        if (($row['event'] ?? '') !== 'test_result') continue;
        $total++;
        if (($row['primary_personality'] ?? '') === $key) $count++;
    }
    return ['total' => $total, 'personality_count' => $count, 'percent' => $total >= 100 ? round(($count / max(1, $total)) * 100, 1) : null];
}
