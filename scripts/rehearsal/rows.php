<?php

// Canonical field-level fingerprints, including identity/password hashes, never row output.
function rowManifest(PDO $pdo, ?array $template = null): array
{
    $manifest = [];
    $tables = $template === null ? $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) : array_keys($template);
    sort($tables);
    foreach ($tables as $table) {
        $quote = fn ($name) => '`'.str_replace('`', '``', $name).'`';
        $columns = $template[$table]['columns'] ?? $pdo->query('SHOW COLUMNS FROM '.$quote($table))->fetchAll(PDO::FETCH_COLUMN);
        $rows = $pdo->query('SELECT '.implode(',', array_map($quote, $columns)).' FROM '.$quote($table))->fetchAll(PDO::FETCH_NUM);
        $hashes = array_map(fn ($row) => hash('sha256', serialize(array_map(fn ($value) => $value === null ? null : (string) $value, $row))), $rows);
        sort($hashes);
        $manifest[$table] = ['columns' => $columns, 'count' => count($rows), 'sha256' => hash('sha256', implode('', $hashes))];
    }

    return $manifest;
}
