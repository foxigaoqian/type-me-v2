<?php
declare(strict_types=1);

require_once __DIR__ . '/analytics.php';

const PERSONALITY_ALGORITHM_VERSION = 'campus-zscore-v1';

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

/**
 * Calculate the neutral-answer baseline for each personality.
 * Each option is treated as equally likely only for normalization; real users
 * are never forced into an equal distribution. This removes the structural
 * advantage caused by some personalities appearing in more answer weights.
 */
function scoreNormalizer(array $questions, array $keys, int $start = 0, ?int $length = null): array
{
    $end = $length === null ? count($questions) : min(count($questions), $start + $length);
    $out = [];
    foreach ($keys as $key) {
        $mean = 0.0;
        $variance = 0.0;
        for ($qi = $start; $qi < $end; $qi++) {
            $options = $questions[$qi]['options'] ?? [];
            if (!$options) continue;
            $values = [];
            foreach ($options as $option) {
                $values[] = (float)(($option['weights'] ?? [])[$key] ?? 0);
            }
            $qMean = array_sum($values) / count($values);
            $qVariance = 0.0;
            foreach ($values as $value) $qVariance += ($value - $qMean) ** 2;
            $qVariance /= count($values);
            $mean += $qMean;
            $variance += $qVariance;
        }
        $out[$key] = ['mean' => $mean, 'sd' => sqrt(max($variance, 0.000001))];
    }
    return $out;
}

function standardizeScores(array $raw, array $normalizer): array
{
    $z = [];
    $index = [];
    foreach ($raw as $key => $value) {
        $mean = (float)($normalizer[$key]['mean'] ?? 0);
        $sd = max(0.000001, (float)($normalizer[$key]['sd'] ?? 1));
        $scoreZ = ((float)$value - $mean) / $sd;
        $z[$key] = $scoreZ;
        // A readable 0-100 index for result payloads/metrics. Ranking uses z.
        $index[$key] = round(max(0, min(100, 50 + ($scoreZ * 15))), 2);
    }
    return ['z' => $z, 'index' => $index];
}

function buildMetrics(array $personality, float $scoreIndex): array
{
    $normalized = min(1, max(0, $scoreIndex / 100));
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

    $keys = array_keys($defs);
    $rawScores = array_fill_keys($keys, 0);
    $rawLast4 = array_fill_keys($keys, 0);

    foreach ($questions as $qi => $question) {
        $answerIndex = (int)($answers[$qi] ?? -1);
        $options = $question['options'] ?? [];
        if (!isset($options[$answerIndex])) throw new InvalidArgumentException('invalid answer at question ' . ($qi + 1));
        foreach (($options[$answerIndex]['weights'] ?? []) as $key => $value) {
            if (!array_key_exists($key, $rawScores)) continue;
            $rawScores[$key] += (int)$value;
            if ($qi >= count($questions) - 4) $rawLast4[$key] += (int)$value;
        }
    }

    $allNorm = scoreNormalizer($questions, $keys);
    $lastStart = max(0, count($questions) - 4);
    $lastNorm = scoreNormalizer($questions, $keys, $lastStart, count($questions) - $lastStart);
    $allStandard = standardizeScores($rawScores, $allNorm);
    $lastStandard = standardizeScores($rawLast4, $lastNorm);
    $zScores = $allStandard['z'];
    $scores = $allStandard['index'];
    $last4Z = $lastStandard['z'];
    $last4Scores = $lastStandard['index'];

    usort($keys, static function (string $a, string $b) use ($zScores, $last4Z, $answers, $tieChoice): int {
        $mainDiff = $zScores[$b] <=> $zScores[$a];
        if ($mainDiff !== 0) return $mainDiff;
        $lastDiff = $last4Z[$b] <=> $last4Z[$a];
        if ($lastDiff !== 0) return $lastDiff;
        if ($tieChoice !== null) {
            if ($a === $tieChoice && $b !== $tieChoice) return -1;
            if ($b === $tieChoice && $a !== $tieChoice) return 1;
        }
        return strcmp(stableTieValue($answers, $a), stableTieValue($answers, $b));
    });

    $make = static function (string $key) use ($defs, $scores, $rawScores): array {
        $p = $defs[$key];
        $p['key'] = $key;
        $p['score'] = $scores[$key];
        $p['raw_score'] = $rawScores[$key];
        $p['metrics'] = buildMetrics($p, (float)$scores[$key]);
        return $p;
    };

    return [
        'algorithm' => PERSONALITY_ALGORITHM_VERSION,
        'primary' => $make($keys[0]),
        'secondary' => $make($keys[1]),
        'scores' => $scores,
        'raw_scores' => $rawScores,
        'last4_scores' => $last4Scores,
        'raw_last4_scores' => $rawLast4,
    ];
}

function appendTestResult(array $row): void
{
    appendNdjson(analyticsStoragePath('test-results.ndjson'), $row);
    bestEffortDb(static fn() => dbPersistResult($row), 'test_result');
}

function personalitySample(string $key): array
{
    try {
        $dbSample = dbPersonalitySample($key);
        if ($dbSample !== null) return $dbSample;
    } catch (Throwable $e) {
        error_log('[TYPE-ME DB fallback][personality_sample] ' . $e->getMessage());
    }

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
