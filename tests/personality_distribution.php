<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/personality.php';

$quiz = loadQuizConfig();
$questions = $quiz['questions'] ?? [];
$keys = array_keys(loadPersonalityConfig()['personalities'] ?? []);
if (count($questions) !== 12 || count($keys) !== 8) {
    fwrite(STDERR, "Unexpected quiz/personality config\n");
    exit(1);
}

mt_srand(20260820);
$samples = 50000;
$counts = array_fill_keys($keys, 0);
for ($i = 0; $i < $samples; $i++) {
    $answers = [];
    foreach ($questions as $question) {
        $optionCount = count($question['options'] ?? []);
        if ($optionCount < 1) {
            fwrite(STDERR, "Question without options\n");
            exit(1);
        }
        $answers[] = mt_rand(0, $optionCount - 1);
    }
    $result = scoreAnswers($answers);
    if (($result['algorithm'] ?? '') !== PERSONALITY_ALGORITHM_VERSION) {
        fwrite(STDERR, "Algorithm version missing/mismatched\n");
        exit(1);
    }
    $primary = (string)($result['primary']['key'] ?? '');
    if (!array_key_exists($primary, $counts)) {
        fwrite(STDERR, "Unknown primary personality: {$primary}\n");
        exit(1);
    }
    $counts[$primary]++;
}

$failed = false;
foreach ($counts as $key => $count) {
    $pct = ($count / $samples) * 100;
    printf("%-12s %6.2f%% (%d)\n", $key, $pct, $count);
    // This is a structural-bias guardrail, not a production distribution target.
    if ($pct < 8.0 || $pct > 18.0) $failed = true;
}

$probe = array_fill(0, 12, 0);
$a = scoreAnswers($probe);
$b = scoreAnswers($probe);
if (($a['primary']['key'] ?? null) !== ($b['primary']['key'] ?? null) || ($a['secondary']['key'] ?? null) !== ($b['secondary']['key'] ?? null)) {
    fwrite(STDERR, "Scoring is not deterministic for identical answers\n");
    $failed = true;
}

if ($failed) {
    fwrite(STDERR, "Neutral scoring distribution guardrail failed\n");
    exit(1);
}

echo "campus-zscore-v1 distribution guardrail passed\n";
