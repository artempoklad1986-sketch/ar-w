<?php
$dbPath = '/home/d/dizain9n/saitt.itmag.site/public_html/data/printcrm.sqlite';
$dir = dirname($dbPath);
if (!file_exists($dir)) mkdir($dir, 0755, true);

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
} catch (Exception $e) {
    die('PDO Error: ' . $e->getMessage());
}

$sqls = [
'orders'              => 'CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY AUTOINCREMENT, num TEXT, client TEXT, phone TEXT, client_id INTEGER, date TEXT, deadline TEXT, manager TEXT, service TEXT, service_label TEXT, size TEXT, status TEXT, payment TEXT, total INTEGER DEFAULT 0, prepay INTEGER DEFAULT 0, comment TEXT, options TEXT, files TEXT, created_at TEXT, updated_at TEXT)',
'finance'             => 'CREATE TABLE IF NOT EXISTS finance (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, amount INTEGER DEFAULT 0, category TEXT, desc TEXT, method TEXT, date TEXT, order_id INTEGER, shift_id INTEGER, created_at TEXT, updated_at TEXT)',
'clients'             => 'CREATE TABLE IF NOT EXISTS clients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, phone TEXT, email TEXT, bizcat TEXT, address TEXT, comment TEXT, total_orders INTEGER DEFAULT 0, total_sum INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)',
'debts'               => 'CREATE TABLE IF NOT EXISTS debts (id INTEGER PRIMARY KEY AUTOINCREMENT, client TEXT, phone TEXT, amount REAL DEFAULT 0, paid REAL DEFAULT 0, direction TEXT, desc TEXT, date TEXT, due_date TEXT, status TEXT, created_at TEXT, updated_at TEXT)',
'notes'               => 'CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY AUTOINCREMENT, text TEXT, color TEXT, date TEXT, author TEXT, created_at TEXT, updated_at TEXT)',
'warehouse'           => 'CREATE TABLE IF NOT EXISTS warehouse (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, category TEXT, qty REAL DEFAULT 0, unit TEXT, min_qty REAL DEFAULT 0, price REAL DEFAULT 0, comment TEXT, created_at TEXT, updated_at TEXT)',
'warehouse_movements' => 'CREATE TABLE IF NOT EXISTS warehouse_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER, type TEXT, qty REAL DEFAULT 0, comment TEXT, date TEXT, created_at TEXT)',
'staff'               => 'CREATE TABLE IF NOT EXISTS staff (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, role TEXT, phone TEXT, pin TEXT, salary REAL DEFAULT 0, bonus_pct REAL DEFAULT 0, active INTEGER DEFAULT 1, comment TEXT, created_at TEXT, updated_at TEXT)',
'staff_log'           => 'CREATE TABLE IF NOT EXISTS staff_log (id INTEGER PRIMARY KEY AUTOINCREMENT, staff_id INTEGER, action TEXT, date TEXT, created_at TEXT)',
'shifts'              => 'CREATE TABLE IF NOT EXISTS shifts (id INTEGER PRIMARY KEY AUTOINCREMENT, staff_id INTEGER, manager TEXT, status TEXT, opened_at TEXT, closed_at TEXT, cash_start REAL DEFAULT 0, cash_end REAL DEFAULT 0, cash_fact REAL DEFAULT 0, income REAL DEFAULT 0, expense REAL DEFAULT 0, profit REAL DEFAULT 0, salary_day REAL DEFAULT 0, bonus_pct REAL DEFAULT 0, bonus_amount REAL DEFAULT 0, operations TEXT, notes TEXT, created_at TEXT, updated_at TEXT)',
'salary'              => 'CREATE TABLE IF NOT EXISTS salary (id INTEGER PRIMARY KEY AUTOINCREMENT, staff_id INTEGER, staff_name TEXT, type TEXT, amount REAL DEFAULT 0, date TEXT, note TEXT, created_at TEXT, updated_at TEXT)',
'cal_events'          => 'CREATE TABLE IF NOT EXISTS cal_events (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, date TEXT, time TEXT, color TEXT, type TEXT, order_id INTEGER, comment TEXT, done INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)',
'settings'            => 'CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)',
'integrations'        => 'CREATE TABLE IF NOT EXISTS integrations (id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT, key TEXT, value TEXT, updated_at TEXT)',
'notifications_log'   => 'CREATE TABLE IF NOT EXISTS notifications_log (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, target TEXT, message TEXT, status TEXT, created_at TEXT)',
'api_log'             => 'CREATE TABLE IF NOT EXISTS api_log (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT, method TEXT, ip TEXT, status INTEGER, error TEXT, created_at TEXT)',
'weborders'           => 'CREATE TABLE IF NOT EXISTS weborders (id INTEGER PRIMARY KEY AUTOINCREMENT, client TEXT, phone TEXT, service TEXT, desc TEXT, status TEXT, source TEXT, data TEXT, created_at TEXT, updated_at TEXT)',
'timers'              => 'CREATE TABLE IF NOT EXISTS timers (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, title TEXT, deadline TEXT, status TEXT, notified INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)',
'sizeguide'           => 'CREATE TABLE IF NOT EXISTS sizeguide (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, category TEXT, width REAL, height REAL, unit TEXT, desc TEXT, created_at TEXT)',
'stamps'              => 'CREATE TABLE IF NOT EXISTS stamps (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, client TEXT, category TEXT, file_path TEXT, file_name TEXT, note TEXT, created_at TEXT, updated_at TEXT)',
'layouts'             => 'CREATE TABLE IF NOT EXISTS layouts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, client TEXT, category TEXT, file_path TEXT, file_name TEXT, note TEXT, created_at TEXT, updated_at TEXT)',
'queue'               => 'CREATE TABLE IF NOT EXISTS queue (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, priority INTEGER DEFAULT 0, status TEXT, note TEXT, created_at TEXT, updated_at TEXT)',
'savings'             => 'CREATE TABLE IF NOT EXISTS savings (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, goal REAL DEFAULT 0, current REAL DEFAULT 0, color TEXT, icon TEXT, desc TEXT, status TEXT, created_at TEXT, updated_at TEXT)',
'delivery'            => 'CREATE TABLE IF NOT EXISTS delivery (id INTEGER PRIMARY KEY AUTOINCREMENT, client TEXT, phone TEXT, address TEXT, order_id INTEGER, courier TEXT, status TEXT, price REAL DEFAULT 0, date TEXT, comment TEXT, created_at TEXT, updated_at TEXT)',
'briefs'              => 'CREATE TABLE IF NOT EXISTS briefs (id INTEGER PRIMARY KEY AUTOINCREMENT, client TEXT, phone TEXT, service TEXT, desc TEXT, status TEXT, date TEXT, manager TEXT, total REAL DEFAULT 0, created_at TEXT, updated_at TEXT)',
'pricelist'           => 'CREATE TABLE IF NOT EXISTS pricelist (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, category TEXT, price REAL DEFAULT 0, unit TEXT, desc TEXT, active INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT)',
'doc_templates'       => 'CREATE TABLE IF NOT EXISTS doc_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, type TEXT, content TEXT, created_at TEXT, updated_at TEXT)',
'schedule'            => 'CREATE TABLE IF NOT EXISTS schedule (id INTEGER PRIMARY KEY AUTOINCREMENT, staff_id INTEGER, date TEXT, shift_type TEXT, note TEXT, created_at TEXT)',
'checklists'          => 'CREATE TABLE IF NOT EXISTS checklists (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, type TEXT, items TEXT, status TEXT, completed TEXT, staff TEXT, date TEXT, created_at TEXT, updated_at TEXT)',
];

$ok = 0;
$errors = [];

foreach ($sqls as $t => $sql) {
    try {
        $pdo->exec($sql);
        $ok++;
    } catch (Exception $e) {
        $errors[] = $t . ': ' . $e->getMessage();
    }
}

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
               ->fetchAll(PDO::FETCH_COLUMN);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Setup</title>
<style>
body { background:#0d0f1a; color:#e2e8f0; font-family:monospace; padding:32px; }
h1 { color:#7c3aed; margin-bottom:16px; }
.ok  { color:#10b981; }
.err { color:#ef4444; }
.box { background:#141828; border:1px solid #1e2a3a; border-radius:8px; padding:16px; margin-bottom:16px; }
.btn { display:inline-block; margin-top:16px; padding:12px 24px; background:#10b981; color:#fff; border-radius:8px; text-decoration:none; font-size:1rem; }
</style>
</head>
<body>
<h1>🔧 PrintCRM — Setup БД</h1>

<div class="box">
    <?php if (empty($errors)): ?>
        <div class="ok" style="font-size:1.2rem; margin-bottom:12px;">✅ Все таблицы созданы! (<?= $ok ?>)</div>
    <?php else: ?>
        <div class="err" style="font-size:1.2rem; margin-bottom:12px;">⚠️ Создано: <?= $ok ?>, Ошибок: <?= count($errors) ?></div>
        <?php foreach ($errors as $e): ?>
            <div class="err">❌ <?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="box">
    <div style="color:#94a3b8; margin-bottom:8px;">📋 Таблиц в БД: <?= count($tables) ?></div>
    <?php foreach ($tables as $t): ?>
        <div class="ok">✓ <?= $t ?></div>
    <?php endforeach; ?>
</div>

<a href="https://saitt.itmag.site/" class="btn">🚀 Открыть PrintCRM</a>

</body>
</html>