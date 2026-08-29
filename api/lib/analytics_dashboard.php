<?php

declare(strict_types=1);

function analyticsDimension($value, int $maxLength = 128): string
{
    $clean = trim((string)($value ?? ''));
    return function_exists('mb_substr') ? mb_substr($clean, 0, $maxLength) : substr($clean, 0, $maxLength);
}

function analyticsIsInternalValue(string $value): bool
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') return false;
    $exact = ['server-smoke', 'developer', 'admin', 'test', 'preview', 'smoke'];
    if (in_array($normalized, $exact, true)) return true;
    return preg_match('/^(server[-_]smoke|developer|dev|admin|test|preview|smoke)[-_:]/', $normalized) === 1;
}

function analyticsIsInternalRow(array $row): bool
{
    return analyticsIsInternalValue((string)($row['source'] ?? ''))
        || analyticsIsInternalValue((string)($row['campaign'] ?? ''));
}

function analyticsFilterScope(array $rows, string $scope): array
{
    if ($scope === 'all') return array_values($rows);
    return array_values(array_filter($rows, static fn(array $row): bool => !analyticsIsInternalRow($row)));
}

function analyticsUserKey(array $row): string
{
    foreach (['anonymous_user_id', 'user_id', 'uid', 'visitor_id', 'session_id'] as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function analyticsUserSet(array $rows, ?array $eventNames = null): array
{
    $set = [];
    foreach ($rows as $row) {
        if ($eventNames !== null && !in_array((string)($row['event_name'] ?? ''), $eventNames, true)) continue;
        $key = analyticsUserKey($row);
        if ($key !== '') $set[$key] = true;
    }
    return $set;
}

function analyticsIntersectCount(array $left, array $right): int
{
    return count(array_intersect_key($left, $right));
}

function analyticsRate(int $denominator, int $numerator): float
{
    if ($denominator <= 0) return 0.0;
    return round(min($denominator, max(0, $numerator)) / $denominator * 100, 1);
}
