<?php
// /public_html/fix_shifts.php
// УДАЛИТЬ ПОСЛЕ ИСПОЛЬЗОВАНИЯ!

require_once __DIR__ . '/api/core/CQLite.php';
$db = CQLite::getInstance(__DIR__ . '/data/printcrm.sqlite');

$cols = [
    "ALTER TABLE shifts ADD COLUMN manager TEXT DEFAULT ''",
    "ALTER TABLE shifts ADD COLUMN emp_id TEXT DEFAULT ''",  
    "ALTER TABLE shifts ADD COLUMN start_cash REAL DEFAULT 0",
    "ALTER TABLE shifts ADD COLUMN cash REAL DEFAULT 0",
    "ALTER TABLE shifts ADD COLUMN bonus_pct REAL DEFAULT 0.10",
    "ALTER TABLE shifts ADD COLUMN total_income REAL DEFAULT 0",
    "ALTER TABLE shifts ADD COLUMN total_expense REAL DEFAULT 0",
    "ALTER TABLE shifts ADD COLUMN accrued_bonus REAL DEFAULT 0",
    "ALTER TABLE shifts ADD COLUMN operations TEXT DEFAULT '[]'",
    "ALTER TABLE shifts ADD COLUMN open_time TEXT DEFAULT ''",
    "ALTER TABLE shifts ADD COLUMN close_time TEXT DEFAULT ''",
    "ALTER TABLE shifts ADD COLUMN end_cash REAL DEFAULT 0",
    "ALTER TABLE shifts ADD COLUMN cash_diff REAL DEFAULT 0",
    "ALTER TABLE shifts ADD COLUMN base_salary REAL DEFAULT 0",
    "ALTER TABLE shifts ADD COLUMN total_salary REAL DEFAULT 0",
    "ALTER TABLE shifts ADD COLUMN completed INTEGER DEFAULT 0",
];

$results = [];
foreach ($cols as $sql) {
    try {
        $db->execute($sql);
        $results[] = '✅ ' . $sql;
    } catch (Throwable $e) {
        // Колонка уже есть — не страшно
        $results[] = '⚠️ (уже есть) ' . $e->getMessage();
    }
}

// Показываем реальную структуру после ALTER
$pragma = $db->query("PRAGMA table_info(shifts)");

header('Content-Type: text/plain; charset=utf-8');
echo "=== РЕЗУЛЬТАТ ===\n";
foreach ($results as $r) echo $r . "\n";
echo "\n=== КОЛОНКИ ПОСЛЕ FIX ===\n";
foreach ($pragma as $col) {
    echo $col['cid'] . '. ' . $col['name'] . ' — ' . $col['type'] . " DEFAULT " . $col['dflt_value'] . "\n";
}
echo "\n✅ ГОТОВО! Удалите этот файл!";