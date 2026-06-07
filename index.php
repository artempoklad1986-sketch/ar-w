<?php
// ============================================================
// PrintCRM API v3.0 — api/index.php
// Единая точка входа. Все эндпоинты. SQLite backend.
// PHP 8.2 | Apache | Beget shared hosting
// ============================================================

declare(strict_types=1);

// ── Глобальный обработчик ошибок ────────────────────────────
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) return false;
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => $errstr,
        'file'  => $errfile,
        'line'  => $errline,
    ]);
    exit();
});

set_exception_handler(function(Throwable $e): void {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    exit();
});

// ── CORS ────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── ПУТИ ────────────────────────────────────────────────────
define('ROOT',        dirname(__DIR__));
define('DATA_DIR',    ROOT . '/data');
define('DB_FILE',     DATA_DIR . '/printcrm.sqlite');
define('UPLOADS_DIR', DATA_DIR . '/uploads');
define('LOGS_DIR',    DATA_DIR . '/logs');
define('BACKUP_DIR',  DATA_DIR . '/backups');
define('MODULES_DIR', __DIR__ . '/modules');

// ── КОНФИГ ──────────────────────────────────────────────────
define('API_KEY',     '12345');
define('APP_VERSION', '3.0');

// ── АВТОЗАГРУЗКА ────────────────────────────────────────────
require_once __DIR__ . '/core/CQLite.php';
require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Logger.php';
require_once __DIR__ . '/core/Validator.php';

// ── ИНИЦИАЛИЗАЦИЯ ───────────────────────────────────────────
$db  = CQLite::getInstance(DB_FILE);
$req = new Request();
$res = new Response();
$log = new Logger($db);

// ── АВТОРИЗАЦИЯ ─────────────────────────────────────────────
$endpoint = $req->getEndpoint();

$public = ['ping', 'webhooks'];
if (!in_array($endpoint, $public)) {
    Auth::check($req) or $res->unauthorized();
}

// ── РОУТЕР ──────────────────────────────────────────────────
$method = $req->method();
$path   = $req->path();
$body   = $req->body();
$params = $req->params();

if ($endpoint !== 'ping') {
    $log->api($method, implode('/', $path), $req->ip());
}

try {
    route($path, $method, $body, $params, $db, $req, $res, $log);
} catch (Throwable $e) {
    $log->error('ROUTER', $e->getMessage(), $e->getTraceAsString());
    $res->serverError($e->getMessage());
}

// ════════════════════════════════════════════════════════════
// ГЛАВНЫЙ РОУТЕР
// ════════════════════════════════════════════════════════════
function route(
    array $path, string $method, array $body,
    array $params, CQLite $db, Request $req,
    Response $res, Logger $log
): void {
    $seg0 = $path[0] ?? '';
    $seg1 = $path[1] ?? '';
    $id   = $params['id'] ?? $body['id'] ?? null;

    match ($seg0) {

        'ping' => $res->ok([
            'status'  => 'ok',
            'version' => APP_VERSION,
            'php'     => PHP_VERSION,
            'time'    => date('Y-m-d H:i:s'),
            'db'      => $db->ping() ? 'ok' : 'error',
        ]),

        'db' => match ($seg1) {
            'info'  => handleDbInfo($db, $res),
            'clear' => handleDbClear($db, $res, $log),
            'alter' => handleDbAlter($body, $db, $res),
            default => $res->notFound('db/' . $seg1),
        },

        'import' => handleImport($body, $db, $res, $log),

        'orders' => match ($seg1) {
            ''      => handleOrders($method, $body, $params, $id, $db, $res, $log),
            default => $res->notFound('orders/' . $seg1),
        },

        'finance' => match ($seg1) {
            ''        => handleFinance($method, $body, $params, $id, $db, $res, $log),
            'summary' => handleFinanceSummary($params, $db, $res),
            default   => $res->notFound('finance/' . $seg1),
        },

        'clients' => match ($seg1) {
            ''      => handleClients($method, $body, $params, $id, $db, $res, $log),
            default => $res->notFound('clients/' . $seg1),
        },

        'warehouse' => match ($seg1) {
            ''        => handleWarehouse($method, $body, $params, $id, $db, $res, $log),
            'restock' => handleWarehouseAction('restock', $body, $db, $res, $log),
            'deduct'  => handleWarehouseAction('deduct', $body, $db, $res, $log),
            'history' => handleWarehouseHistory($db, $res),
            default   => $res->notFound('warehouse/' . $seg1),
        },

        'notes' => match ($seg1) {
            ''      => handleNotes($method, $body, $params, $id, $db, $res, $log),
            default => $res->notFound('notes/' . $seg1),
        },

        'calendar' => match ($seg1) {
            ''      => handleCalendar($method, $body, $params, $id, $db, $res, $log),
            default => $res->notFound('calendar/' . $seg1),
        },

        'settings' => handleSettings($method, $body, $db, $res, $log),
        'stats'    => handleStats($params, $db, $res),

        'staff' => match ($seg1) {
            ''       => handleStaff($method, $body, $params, $id, $db, $res, $log),
            'verify' => handleStaffVerify($body, $db, $res),
            'log'    => handleStaffLog($db, $res),
            default  => $res->notFound('staff/' . $seg1),
        },

        'salary' => match ($seg1) {
            ''          => handleSalary($method, $body, $params, $id, $db, $res, $log),
            'employees' => handleSalaryEmployees($method, $body, $params, $id, $db, $res),
            'shifts'    => handleSalaryShifts($method, $body, $params, $id, $db, $res),
            default     => $res->notFound('salary/' . $seg1),
        },

        'shifts' => match ($seg1) {
            ''      => handleShifts($method, $body, $params, $id, $db, $res, $log),
            'open'  => handleShiftOpen($body, $db, $res, $log),
            'close' => handleShiftClose($body, $db, $res, $log),
            default => $res->notFound('shifts/' . $seg1),
        },

        'shift' => match ($seg1) {
            '', 'current'         => handleShiftModule('current',         $body, $params, $db, $res),
            'open'                => handleShiftModule('open',            $body, $params, $db, $res),
            'close'               => handleShiftModule('close',           $body, $params, $db, $res),
            'operation'           => handleShiftModule('operation',       $body, $params, $db, $res),
            'delete-operation'    => handleShiftModule('deleteOperation', $body, $params, $db, $res),
            'edit-operation'      => handleShiftModule('editOperation',   $body, $params, $db, $res),
            'history'             => handleShiftModule('history',         $body, $params, $db, $res),
            'reports'             => handleShiftModule('reports',         $body, $params, $db, $res),
            'report'              => handleShiftModule('report',          $body, $params, $db, $res),
            'managers'            => handleShiftModule('managers',        $body, $params, $db, $res),
            'buttons'             => handleShiftModule('getButtons',      $body, $params, $db, $res),
            'add-button'          => handleShiftModule('addButton',       $body, $params, $db, $res),
            'delete-button'       => handleShiftModule('deleteButton',    $body, $params, $db, $res),
            'last-end-cash'       => handleShiftModule('lastEndCash',     $body, $params, $db, $res),
            default               => $res->notFound('shift/' . $seg1),
        },

        'debts' => match ($seg1) {
            ''      => handleDebts($method, $body, $params, $id, $db, $res, $log),
            'pay'   => handleDebtPay($body, $db, $res, $log),
            default => $res->notFound('debts/' . $seg1),
        },

        'upload'   => handleUpload($db, $res, $log),
        'module'   => handleModule($method, $body, $params, $db, $res, $log),
        'registry' => handleRegistry($res),
        'notify'   => handleNotify($body, $db, $res, $log),

        'integrations' => match ($seg1) {
            ''      => handleIntegrations($method, $body, $params, $id, $db, $res, $log),
            'test'  => handleIntegrationTest($body, $db, $res, $log),
            default => $res->notFound('integrations/' . $seg1),
        },

        'webhooks' => handleWebhook($path, $method, $body, $db, $res, $log),

        'docs' => match ($seg1) {
            'parse' => handleDocParse($res),
            default => $res->notFound('docs/' . $seg1),
        },

        'pos' => match ($seg1) {
            'payment'  => handlePosPayment($body, $db, $res, $log),
            'status'   => handlePosStatus($params, $db, $res),
            'callback' => handlePosCallback($body, $db, $res, $log),
            default    => $res->notFound('pos/' . $seg1),
        },

        'briefs' => match ($seg1) {
            ''      => handleBriefs($method, $body, $params, $id, $db, $res, $log),
            default => $res->notFound('briefs/' . $seg1),
        },

        'checklists' => match ($seg1) {
            ''          => handleChecklists($method, $body, $params, $id, $db, $res),
            'templates' => handleChecklistTemplates($method, $body, $params, $id, $db, $res),
            default     => $res->notFound('checklists/' . $seg1),
        },

        'pricelist' => match ($seg1) {
            ''      => handlePricelist($method, $body, $params, $id, $db, $res),
            default => $res->notFound('pricelist/' . $seg1),
        },

        'templates' => match ($seg1) {
            ''      => handleTemplates($method, $body, $params, $id, $db, $res),
            default => $res->notFound('templates/' . $seg1),
        },

        'schedule' => match ($seg1) {
            ''      => handleSchedule($method, $body, $params, $id, $db, $res),
            default => $res->notFound('schedule/' . $seg1),
        },

        'layouts' => match ($seg1) {
            ''      => handleLayouts($method, $body, $params, $id, $db, $res),
            default => $res->notFound('layouts/' . $seg1),
        },

        'timer' => match ($seg1) {
            ''      => handleTimer($method, $body, $params, $db, $res),
            'start' => handleTimerStart($body, $db, $res),
            'stop'  => handleTimerStop($body, $db, $res),
            default => $res->notFound('timer/' . $seg1),
        },

        'queue' => match ($seg1) {
            ''     => handleQueue($method, $body, $params, $id, $db, $res),
            'next' => handleQueueNext($db, $res),
            default => $res->notFound('queue/' . $seg1),
        },

        'weborders' => match ($seg1) {
            ''       => handleWebOrders($method, $body, $params, $id, $db, $res, $log),
            'accept' => handleWebOrderAccept($body, $db, $res, $log),
            'reject' => handleWebOrderReject($body, $db, $res, $log),
            default  => $res->notFound('weborders/' . $seg1),
        },

        'savings' => match ($seg1) {
            ''      => handleSavings($method, $body, $params, $id, $db, $res),
            default => $res->notFound('savings/' . $seg1),
        },

        'delivery' => match ($seg1) {
            ''      => handleDelivery($method, $body, $params, $id, $db, $res, $log),
            default => $res->notFound('delivery/' . $seg1),
        },

        'sizeguide' => match ($seg1) {
            ''      => handleSizeguide($method, $body, $params, $db, $res),
            default => $res->notFound('sizeguide/' . $seg1),
        },

        'stamps' => match ($seg1) {
            ''      => handleStamps($method, $body, $params, $id, $db, $res),
            default => $res->notFound('stamps/' . $seg1),
        },

        'analytics' => match ($seg1) {
            ''       => handleAnalytics($params, $db, $res),
            'export' => handleAnalyticsExport($params, $db, $res),
            default  => $res->notFound('analytics/' . $seg1),
        },

        'log'     => handleLog($params, $db, $res),

        default   => $res->notFound($seg0),
    };
}

// ════════════════════════════════════════════════════════════
// HELPERS — создание таблиц если не существуют
// ════════════════════════════════════════════════════════════
function ensureTable(CQLite $db, string $table, string $ddl): void
{
    try {
        $db->execute($ddl);
    } catch (Throwable) {
        // таблица уже существует — игнорируем
    }
}

function ensureExtraTables(CQLite $db): void
{
    // Таймеры
    ensureTable($db, 'timers',
        'CREATE TABLE IF NOT EXISTS timers (
            id TEXT PRIMARY KEY,
            name TEXT DEFAULT "",
            order_id TEXT,
            started_at TEXT,
            stopped_at TEXT,
            duration_sec INTEGER DEFAULT 0,
            status TEXT DEFAULT "running",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
    // Размерная сетка
    ensureTable($db, 'sizeguide',
        'CREATE TABLE IF NOT EXISTS sizeguide (
            id TEXT PRIMARY KEY,
            service TEXT DEFAULT "",
            name TEXT DEFAULT "",
            width REAL DEFAULT 0,
            height REAL DEFAULT 0,
            unit TEXT DEFAULT "мм",
            notes TEXT DEFAULT "",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
    // Штампы
    ensureTable($db, 'stamps',
        'CREATE TABLE IF NOT EXISTS stamps (
            id TEXT PRIMARY KEY,
            name TEXT DEFAULT "",
            type TEXT DEFAULT "",
            order_id TEXT,
            file_url TEXT DEFAULT "",
            parameters TEXT DEFAULT "{}",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
    // Макеты
    ensureTable($db, 'layouts',
        'CREATE TABLE IF NOT EXISTS layouts (
            id TEXT PRIMARY KEY,
            name TEXT DEFAULT "",
            order_id TEXT,
            file_url TEXT DEFAULT "",
            status TEXT DEFAULT "pending",
            comment TEXT DEFAULT "",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
    // Очередь
    ensureTable($db, 'queue',
        'CREATE TABLE IF NOT EXISTS queue (
            id TEXT PRIMARY KEY,
            client TEXT DEFAULT "",
            phone TEXT DEFAULT "",
            service TEXT DEFAULT "",
            status TEXT DEFAULT "waiting",
            position INTEGER DEFAULT 0,
            comment TEXT DEFAULT "",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
    // Накопления
    ensureTable($db, 'savings',
        'CREATE TABLE IF NOT EXISTS savings (
            id TEXT PRIMARY KEY,
            name TEXT DEFAULT "",
            amount REAL DEFAULT 0,
            goal REAL DEFAULT 0,
            description TEXT DEFAULT "",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
    // Доставка
    ensureTable($db, 'delivery',
        'CREATE TABLE IF NOT EXISTS delivery (
            id TEXT PRIMARY KEY,
            order_id TEXT,
            client TEXT DEFAULT "",
            phone TEXT DEFAULT "",
            address TEXT DEFAULT "",
            status TEXT DEFAULT "pending",
            courier TEXT DEFAULT "",
            scheduled_at TEXT,
            notes TEXT DEFAULT "",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
    // Брифы
    ensureTable($db, 'briefs',
        'CREATE TABLE IF NOT EXISTS briefs (
            id TEXT PRIMARY KEY,
            client TEXT DEFAULT "",
            staff TEXT DEFAULT "",
            service TEXT DEFAULT "",
            content TEXT DEFAULT "",
            status TEXT DEFAULT "draft",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
    // Прайс-лист
    ensureTable($db, 'pricelist',
        'CREATE TABLE IF NOT EXISTS pricelist (
            id TEXT PRIMARY KEY,
            service TEXT DEFAULT "",
            name TEXT DEFAULT "",
            unit TEXT DEFAULT "",
            price_from REAL DEFAULT 0,
            price_to REAL DEFAULT 0,
            description TEXT DEFAULT "",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
    // Шаблоны документов
    ensureTable($db, 'doc_templates',
        'CREATE TABLE IF NOT EXISTS doc_templates (
            id TEXT PRIMARY KEY,
            name TEXT DEFAULT "",
            type TEXT DEFAULT "",
            content TEXT DEFAULT "",
            variables TEXT DEFAULT "[]",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
    // Расписание
    ensureTable($db, 'schedule',
        'CREATE TABLE IF NOT EXISTS schedule (
            id TEXT PRIMARY KEY,
            staff_id TEXT,
            staff_name TEXT DEFAULT "",
            date TEXT DEFAULT "",
            shift_start TEXT DEFAULT "",
            shift_end TEXT DEFAULT "",
            notes TEXT DEFAULT "",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
    // Чек-листы
    ensureTable($db, 'checklists',
        'CREATE TABLE IF NOT EXISTS checklists (
            id TEXT PRIMARY KEY,
            name TEXT DEFAULT "",
            items TEXT DEFAULT "[]",
            order_id TEXT,
            completed INTEGER DEFAULT 0,
            staff TEXT DEFAULT "",
            created_at TEXT DEFAULT (datetime("now","localtime"))
        )'
    );
}

// Создаём отсутствующие таблицы при старте
ensureExtraTables($db);

// ════════════════════════════════════════════════════════════
// HANDLERS — ЗАКАЗЫ
// ════════════════════════════════════════════════════════════
function handleOrders(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {
    switch ($method) {

        case 'GET':
            $where   = [];
            $options = ['order' => 'created_at DESC'];

            if (!empty($params['status'])) $where['status'] = $params['status'];
            if (!empty($params['client'])) $where['client'] = $params['client'];

            if (!empty($params['search'])) {
                $s    = '%' . $params['search'] . '%';
                $rows = $db->query(
                    'SELECT * FROM orders WHERE (client LIKE ? OR num LIKE ? OR comment LIKE ?) ORDER BY created_at DESC LIMIT 500',
                    [$s, $s, $s]
                );
                $res->ok(['data' => array_map('decodeOrderRow', $rows), 'total' => count($rows)]);
                return;
            }

            if (!empty($params['date'])) $where['created_at LIKE'] = $params['date'] . '%';

            $page    = max(1, (int)($params['page']    ?? 1));
            $perPage = min(200, (int)($params['per_page'] ?? 100));

            $result           = $db->paginate('orders', $where, $page, $perPage, $options);
            $result['data']   = array_map('decodeOrderRow', $result['data']);
            $res->ok($result);
            break;

        case 'POST':
            if (empty($body)) { $res->badRequest('Нет данных'); return; }

            $v = new Validator($body);
            $v->required('client');
            if ($v->fails()) { $res->badRequest($v->errors()); return; }

            $row = [
                'id'            => CQLite::uid('ord'),
                'num'           => $body['num']           ?? autoOrderNum($db),
                'client'        => trim($body['client']   ?? ''),
                'phone'         => $body['phone']         ?? '',
                'manager'       => $body['manager']       ?? '',
                'service'       => $body['service']       ?? 'other',
                'service_label' => $body['service_label'] ?? '',
                'size'          => $body['size']          ?? '',
                'status'        => $body['status']        ?? 'new',
                'payment'       => $body['payment']       ?? 'Наличные',
                'total'         => (float)($body['total']  ?? 0),
                'prepay'        => (float)($body['prepay'] ?? 0),
                'bizcat'        => $body['bizcat']        ?? '',
                'deadline'      => $body['deadline']      ?? null,
                'comment'       => $body['comment']       ?? '',
                'files'         => isset($body['files'])   && is_array($body['files'])   ? json_encode($body['files'],   JSON_UNESCAPED_UNICODE) : ($body['files']   ?? '[]'),
                'options'       => isset($body['options']) && is_array($body['options']) ? json_encode($body['options'], JSON_UNESCAPED_UNICODE) : ($body['options'] ?? '[]'),
                'extra'         => isset($body['extra'])   && is_array($body['extra'])   ? json_encode($body['extra'],   JSON_UNESCAPED_UNICODE) : ($body['extra']   ?? '{}'),
                'created_at'    => $body['date'] ?? date('Y-m-d H:i:s'),
            ];

            $db->insert('orders', $row);
            $log->action('ORDER_CREATE', $row['num'] . ' — ' . $row['client']);
            autoCreateClient($row['client'], $row['phone'], $db);

            $res->ok(['data' => decodeOrderRow($row), 'id' => $row['id']]);
            break;

        case 'PUT':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $existing = $db->selectOne('orders', ['id' => $id]);
            if (!$existing) { $res->notFound('Заказ ' . $id); return; }

            $allowed = [
                'client','phone','manager','service','service_label',
                'size','status','payment','total','prepay','bizcat',
                'deadline','comment','files','options','extra',
            ];
            $update = [];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $body)) {
                    $val = $body[$field];
                    if (in_array($field, ['files','options','extra']) && is_array($val)) {
                        $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                    }
                    $update[$field] = $val;
                }
            }
            if (!empty($update)) {
                $db->update('orders', $update, ['id' => $id]);
                $log->action('ORDER_UPDATE', 'ID: ' . $id . ' status: ' . ($update['status'] ?? '-'));
            }
            $res->ok(['data' => decodeOrderRow($db->selectOne('orders', ['id' => $id]))]);
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete('orders', ['id' => $id]);
            $log->action('ORDER_DELETE', 'ID: ' . $id);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

function autoOrderNum(CQLite $db): string
{
    $count = $db->count('orders');
    return 'ORD-' . str_pad((string)($count + 1), 5, '0', STR_PAD_LEFT);
}

function autoCreateClient(string $name, string $phone, CQLite $db): void
{
    if (!$name || $name === 'Без имени') return;
    $existing = $db->selectOne('clients', ['name' => $name]);
    if (!$existing) {
        $db->insert('clients', [
            'id'    => CQLite::uid('cli'),
            'name'  => $name,
            'phone' => $phone,
        ]);
    }
}

function decodeOrderRow(array $row): array
{
    foreach (['files','options','extra'] as $field) {
        if (isset($row[$field]) && is_string($row[$field])) {
            $decoded = json_decode($row[$field], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row[$field] = $decoded;
            }
        }
    }
    return $row;
}

// ════════════════════════════════════════════════════════════
// HANDLERS — ФИНАНСЫ
// ════════════════════════════════════════════════════════════
function handleFinance(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {
    switch ($method) {

        case 'GET':
            $where   = [];
            $options = ['order' => 'date DESC, created_at DESC'];

            if (!empty($params['type']))     $where['type']       = $params['type'];
            if (!empty($params['month']))    $where['date LIKE']  = $params['month'] . '%';
            if (!empty($params['date']))     $where['date LIKE']  = $params['date']  . '%';
            if (!empty($params['order_id'])) $where['order_id']   = $params['order_id'];

            $page    = max(1,   (int)($params['page']     ?? 1));
            $perPage = min(500, (int)($params['per_page'] ?? 200));

            $result = $db->paginate('finance', $where, $page, $perPage, $options);
            $res->ok($result);
            break;

        case 'POST':
            if (empty($body)) { $res->badRequest('Нет данных'); return; }

            $v = new Validator($body);
            $v->required('type')->required('amount');
            $v->in('type', ['income','expense']);
            if ($v->fails()) { $res->badRequest($v->errors()); return; }

            $row = [
                'id'          => CQLite::uid('fin'),
                'type'        => $body['type'],
                'category'    => $body['category']    ?? '',
                'description' => $body['description'] ?? $body['desc'] ?? '',
                'amount'      => (float)$body['amount'],
                'method'      => $body['method']      ?? 'Наличные',
                'client'      => $body['client']      ?? '',
                'order_id'    => $body['order_id']    ?? null,
                'date'        => !empty($body['date'])
                    ? substr($body['date'], 0, 19)
                    : date('Y-m-d H:i:s'),
            ];

            $db->insert('finance', $row);
            $log->action(
                'FINANCE_' . strtoupper($body['type']),
                ($body['type'] === 'income' ? '+' : '-') . $body['amount'] . '₽ ' . ($body['category'] ?? '')
            );
            $res->ok(['data' => $row, 'id' => $row['id']]);
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete('finance', ['id' => $id]);
            $log->action('FINANCE_DELETE', 'ID: ' . $id);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

function handleFinanceSummary(array $params, CQLite $db, Response $res): void
{
    $month = $params['month'] ?? date('Y-m');
    $today = date('Y-m-d');

    $income   = $db->sum('finance', 'amount', ['type' => 'income',  'date LIKE' => $month . '%']);
    $expense  = $db->sum('finance', 'amount', ['type' => 'expense', 'date LIKE' => $month . '%']);
    $incToday = $db->sum('finance', 'amount', ['type' => 'income',  'date LIKE' => $today . '%']);
    $expToday = $db->sum('finance', 'amount', ['type' => 'expense', 'date LIKE' => $today . '%']);

    $res->ok([
        'month'   => $month,
        'income'  => $income,
        'expense' => $expense,
        'profit'  => $income - $expense,
        'margin'  => $income > 0 ? round(($income - $expense) / $income * 100, 1) : 0,
        'today'   => [
            'income'  => $incToday,
            'expense' => $expToday,
            'profit'  => $incToday - $expToday,
        ],
    ]);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — КЛИЕНТЫ
// ════════════════════════════════════════════════════════════
function handleClients(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {
    switch ($method) {

        case 'GET':
            if ($id) {
                $client = $db->selectOne('clients', ['id' => $id]);
                if (!$client) { $res->notFound('Клиент ' . $id); return; }
                $res->ok(['data' => $client]);
                return;
            }
            if (!empty($params['search'])) {
                $s    = '%' . $params['search'] . '%';
                $rows = $db->query(
                    'SELECT * FROM clients WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? ORDER BY name',
                    [$s, $s, $s]
                );
                $res->ok(['data' => $rows, 'total' => count($rows)]);
                return;
            }
            $result = $db->paginate('clients', [], (int)($params['page'] ?? 1), 200, ['order' => 'name ASC']);
            $res->ok($result);
            break;

        case 'POST':
            if (empty($body['name'])) { $res->badRequest('Нет имени'); return; }

            $row = [
                'id'       => CQLite::uid('cli'),
                'name'     => trim($body['name']),
                'type'     => $body['type']     ?? 'Физическое лицо',
                'phone'    => $body['phone']    ?? '',
                'email'    => $body['email']    ?? '',
                'address'  => $body['address']  ?? '',
                'inn'      => $body['inn']       ?? '',
                'biz_cat'  => $body['bizcat']   ?? '',
                'discount' => (float)($body['discount'] ?? 0),
                'notes'    => $body['notes']    ?? '',
            ];
            $db->insert('clients', $row);
            $log->action('CLIENT_CREATE', $row['name']);
            $res->ok(['data' => $row, 'id' => $row['id']]);
            break;

        case 'PUT':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $existing = $db->selectOne('clients', ['id' => $id]);
            if (!$existing) { $res->notFound('Клиент ' . $id); return; }

            $allowed = ['name','type','phone','email','address','inn','biz_cat','discount','notes'];
            $update  = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) $update[$f] = $body[$f];
            }
            if (!empty($update)) $db->update('clients', $update, ['id' => $id]);
            $res->ok(['data' => $db->selectOne('clients', ['id' => $id])]);
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete('clients', ['id' => $id]);
            $log->action('CLIENT_DELETE', 'ID: ' . $id);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

// ════════════════════════════════════════════════════════════
// HANDLERS — СКЛАД
// ════════════════════════════════════════════════════════════
function handleWarehouse(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {
    switch ($method) {

        case 'GET':
            $where   = [];
            $options = ['order' => 'name ASC'];

            if (!empty($params['category'])) $where['category'] = $params['category'];

            $items = $db->select('warehouse', $where, $options);
            $items = array_map(function ($i) {
                $i['isLow'] = (float)$i['qty'] <= (float)$i['min_qty'];
                return $i;
            }, $items);
            $res->ok(['data' => $items, 'total' => count($items)]);
            break;

        case 'POST':
            if (empty($body['name'])) { $res->badRequest('Нет наименования'); return; }

            $row = [
                'id'       => CQLite::uid('wh'),
                'name'     => trim($body['name']),
                'category' => $body['category'] ?? 'Прочее',
                'unit'     => $body['unit']      ?? 'шт',
                'qty'      => (float)($body['qty']     ?? 0),
                'min_qty'  => (float)($body['min_qty'] ?? $body['minQty'] ?? 0),
                'price'    => (float)($body['price']   ?? 0),
            ];
            $db->insert('warehouse', $row);
            $log->action('WAREHOUSE_ADD', $row['name']);
            $res->ok(['data' => $row, 'id' => $row['id']]);
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete('warehouse', ['id' => $id]);
            $db->delete('warehouse_movements', ['item_id' => $id]);
            $log->action('WAREHOUSE_DELETE', 'ID: ' . $id);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

// FIX: убрана неверная проверка $type !== 'POST'
function handleWarehouseAction(string $type, array $body, CQLite $db, Response $res, Logger $log): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $res->methodNotAllowed(); return;
    }

    $id  = $body['id']  ?? null;
    $qty = (float)($body['qty'] ?? 0);

    if (!$id || $qty <= 0) { $res->badRequest('Нет ID или количества'); return; }

    $item = $db->selectOne('warehouse', ['id' => $id]);
    if (!$item) { $res->notFound('Позиция ' . $id); return; }

    if ($type === 'deduct' && (float)$item['qty'] < $qty) {
        $res->badRequest('Недостаточно остатка: ' . $item['qty'] . ' ' . $item['unit']); return;
    }

    $newQty = $type === 'restock'
        ? (float)$item['qty'] + $qty
        : (float)$item['qty'] - $qty;

    $db->update('warehouse', ['qty' => $newQty], ['id' => $id]);
    $db->insert('warehouse_movements', [
        'item_id' => $id,
        'type'    => $type,
        'qty'     => $qty,
        'comment' => $body['comment'] ?? '',
    ]);

    $isLow = $newQty <= (float)$item['min_qty'];
    $alert = $isLow ? "«{$item['name']}» заканчивается! Остаток: {$newQty} {$item['unit']}" : null;

    $log->action('WAREHOUSE_' . strtoupper($type), $item['name'] . ' ' . ($type === 'restock' ? '+' : '-') . $qty);

    $res->ok(['qty' => $newQty, 'isLow' => $isLow, 'alert' => $alert]);
}

function handleWarehouseHistory(CQLite $db, Response $res): void
{
    $rows = $db->query(
        'SELECT m.*, w.name, w.unit
         FROM warehouse_movements m
         LEFT JOIN warehouse w ON w.id = m.item_id
         ORDER BY m.created_at DESC
         LIMIT 100'
    );
    $res->ok(['data' => $rows]);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — ЗАМЕТКИ
// ════════════════════════════════════════════════════════════
function handleNotes(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {
    switch ($method) {

        case 'GET':
            $rows = $db->select('notes', [], ['order' => 'created_at DESC']);
            $res->ok(['data' => $rows]);
            break;

        case 'POST':
            if (empty($body['title']) && empty($body['body'])) {
                $res->badRequest('Нет текста'); return;
            }
            $row = [
                'title'    => $body['title']    ?? 'Без заголовка',
                'body'     => $body['body']     ?? '',
                'priority' => $body['priority'] ?? 'normal',
                'shift'    => $body['shift']    ?? '',
                'is_read'  => 0,
            ];
            $newId      = $db->insert('notes', $row);
            $row['id']  = $newId;
            $res->ok(['data' => $row, 'id' => $newId]);
            break;

        case 'PUT':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->update('notes',
                array_intersect_key($body, array_flip(['title','body','priority','shift','is_read'])),
                ['id' => $id]
            );
            $res->ok(['data' => $db->selectOne('notes', ['id' => $id])]);
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete('notes', ['id' => $id]);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

// ════════════════════════════════════════════════════════════
// HANDLERS — КАЛЕНДАРЬ
// ════════════════════════════════════════════════════════════
function handleCalendar(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {
    switch ($method) {

        case 'GET':
            $where   = [];
            $options = ['order' => 'date ASC, time ASC'];

            if (!empty($params['from'])) $where['date >='] = $params['from'];
            if (!empty($params['to']))   $where['date <='] = $params['to'];

            $rows = $db->select('cal_events', $where, $options);
            $res->ok(['data' => $rows]);
            break;

        case 'POST':
            if (empty($body['title'])) { $res->badRequest('Нет заголовка'); return; }

            $row = [
                'id'       => CQLite::uid('cal'),
                'title'    => $body['title'],
                'date'     => $body['date']     ?? date('Y-m-d'),
                'time'     => $body['time']     ?? '',
                'type'     => $body['type']     ?? 'task',
                'color'    => $body['color']    ?? '#7c3aed',
                'note'     => $body['note']     ?? '',
                'order_id' => $body['order_id'] ?? null,
            ];
            $db->insert('cal_events', $row);
            $res->ok(['data' => $row, 'id' => $row['id']]);
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete('cal_events', ['id' => $id]);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

// ════════════════════════════════════════════════════════════
// HANDLERS — НАСТРОЙКИ
// ════════════════════════════════════════════════════════════
function handleSettings(string $method, array $body, CQLite $db, Response $res, Logger $log): void
{
    switch ($method) {

        case 'GET':
            $res->ok(['data' => $db->getSettings()]);
            break;

        case 'POST':
        case 'PUT':
            if (empty($body)) { $res->badRequest('Нет данных'); return; }
            $db->transaction(function () use ($body, $db) {
                $db->setSettings($body);
            });
            $log->action('SETTINGS_SAVE', implode(', ', array_keys($body)));
            $res->ok(['message' => 'Настройки сохранены']);
            break;

        default:
            $res->methodNotAllowed();
    }
}

// ════════════════════════════════════════════════════════════
// HANDLERS — СТАТИСТИКА
// ════════════════════════════════════════════════════════════
function handleStats(array $params, CQLite $db, Response $res): void
{
    $month = $params['month'] ?? date('Y-m');
    $today = date('Y-m-d');

    $ordersTotal  = $db->count('orders');
    $ordersMonth  = $db->count('orders', ['created_at LIKE' => $month . '%']);
    $ordersToday  = $db->count('orders', ['created_at LIKE' => $today . '%']);
    $ordersActive = $db->count('orders', ['status IN' => ['new','work']]);
    $ordersDone   = $db->count('orders', ['status' => 'done']);

    $income  = $db->sum('finance', 'amount', ['type' => 'income',  'date LIKE' => $month . '%']);
    $expense = $db->sum('finance', 'amount', ['type' => 'expense', 'date LIKE' => $month . '%']);

    $avgRow   = $db->query(
        'SELECT AVG(total) as avg FROM orders WHERE created_at LIKE ? AND total > 0',
        [$month . '%']
    );
    $avgCheck = round((float)($avgRow[0]['avg'] ?? 0));

    $byService = $db->query(
        'SELECT service_label, COUNT(*) as cnt, SUM(total) as sum
         FROM orders WHERE created_at LIKE ?
         GROUP BY service_label ORDER BY cnt DESC',
        [$month . '%']
    );
    $topClients = $db->query(
        'SELECT client, COUNT(*) as orders, SUM(total) as total
         FROM orders WHERE created_at LIKE ? AND client != ""
         GROUP BY client ORDER BY total DESC LIMIT 5',
        [$month . '%']
    );
    $clientsTotal = $db->count('clients');

    $res->ok([
        'month'       => $month,
        'orders'      => [
            'total'  => $ordersTotal,
            'month'  => $ordersMonth,
            'today'  => $ordersToday,
            'active' => $ordersActive,
            'done'   => $ordersDone,
        ],
        'finance'     => [
            'income'  => $income,
            'expense' => $expense,
            'profit'  => $income - $expense,
            'margin'  => $income > 0 ? round(($income - $expense) / $income * 100, 1) : 0,
        ],
        'avg_check'   => $avgCheck,
        'clients'     => $clientsTotal,
        'by_service'  => $byService,
        'top_clients' => $topClients,
    ]);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — СОТРУДНИКИ
// ════════════════════════════════════════════════════════════
function handleStaff(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {

    // Миграция колонок при каждом обращении
    static $migrated = false;
    if (!$migrated) {
        $cols = array_column($db->query("PRAGMA table_info(staff)"), 'name');
        $migrations = [
            'position'      => "ALTER TABLE staff ADD COLUMN position TEXT DEFAULT ''",
            'email'         => "ALTER TABLE staff ADD COLUMN email TEXT DEFAULT ''",
            'salary'        => "ALTER TABLE staff ADD COLUMN salary REAL DEFAULT 0",
            'bonus_pct'     => "ALTER TABLE staff ADD COLUMN bonus_pct REAL DEFAULT 0.1",
            'birth_date'    => "ALTER TABLE staff ADD COLUMN birth_date TEXT DEFAULT ''",
            'address'       => "ALTER TABLE staff ADD COLUMN address TEXT DEFAULT ''",
            'passport_note' => "ALTER TABLE staff ADD COLUMN passport_note TEXT DEFAULT ''",
            'photo'         => "ALTER TABLE staff ADD COLUMN photo TEXT DEFAULT ''",
            'schedule'      => "ALTER TABLE staff ADD COLUMN schedule TEXT DEFAULT '5/2'",
            'start_date'    => "ALTER TABLE staff ADD COLUMN start_date TEXT DEFAULT ''",
            'status'        => "ALTER TABLE staff ADD COLUMN status TEXT DEFAULT 'active'",
            'notes'         => "ALTER TABLE staff ADD COLUMN notes TEXT DEFAULT ''",
        ];
        foreach ($migrations as $col => $sql) {
            if (!in_array($col, $cols)) {
                try { $db->execute($sql); } catch (Throwable) {}
            }
        }
        $migrated = true;
    }

    switch ($method) {

        case 'GET':
            $rows = $db->select('staff', [], ['order' => 'name ASC']);
            // Убираем pin_hash, нормализуем поля
            $rows = array_map(function ($r) {
                unset($r['pin_hash']);
                // Совместимость: role → position
                $r['position'] = $r['position'] ?? $r['role'] ?? '';
                // Совместимость: is_active → status
                if (empty($r['status'])) {
                    $r['status'] = ($r['is_active'] ?? 1) ? 'active' : 'fired';
                }
                return $r;
            }, $rows);
            $res->ok(['data' => $rows]);
            break;

        case 'POST':
            $v = new Validator($body);
            $v->required('name');
            if ($v->fails()) { $res->badRequest($v->errors()); return; }

            // PIN необязателен для модуля — генерируем если не передан
            $pin = (string)($body['pin'] ?? '0000');
            if (strlen($pin) < 4) $pin = str_pad($pin, 4, '0', STR_PAD_LEFT);

            $row = [
                'id'            => CQLite::uid('st'),
                'name'          => trim($body['name']),
                'pin_hash'      => hashPin($pin, trim($body['name'])),
                'role'          => $body['role']          ?? $body['position']     ?? 'Менеджер',
                'position'      => $body['position']      ?? $body['role']         ?? 'Менеджер',
                'phone'         => $body['phone']         ?? '',
                'email'         => $body['email']         ?? '',
                'color'         => $body['color']         ?? '#7c3aed',
                'salary'        => (float)($body['salary']    ?? 0),
                'bonus_pct'     => (float)($body['bonus_pct'] ?? 0.1),
                'schedule'      => $body['schedule']      ?? '5/2',
                'start_date'    => $body['start_date']    ?? date('Y-m-d'),
                'birth_date'    => $body['birth_date']    ?? '',
                'address'       => $body['address']       ?? '',
                'passport_note' => $body['passport_note'] ?? '',
                'photo'         => $body['photo']         ?? '',
                'status'        => $body['status']        ?? 'active',
                'notes'         => $body['notes']         ?? '',
                'is_active'     => ($body['status'] ?? 'active') !== 'fired' ? 1 : 0,
            ];

            $db->insert('staff', $row);
            $db->insert('staff_log', [
                'staff_id'   => $row['id'],
                'staff_name' => $row['name'],
                'action'     => 'Добавлен',
                'details'    => $row['position'],
            ]);
            $log->action('STAFF_ADD', $row['name']);
            unset($row['pin_hash']);
            $res->ok(['data' => $row, 'id' => $row['id']]);
            break;

        case 'PUT':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $existing = $db->selectOne('staff', ['id' => $id]);
            if (!$existing) { $res->notFound('Сотрудник ' . $id); return; }

            $allowed = [
                'name','role','position','phone','email','color',
                'salary','bonus_pct','schedule','start_date','birth_date',
                'address','passport_note','photo','status','notes',
            ];
            $update = [];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $body)) $update[$field] = $body[$field];
            }
            // Синхронизируем role ↔ position
            if (isset($update['position'])) $update['role']     = $update['position'];
            if (isset($update['role']))     $update['position'] = $update['role'];
            // Синхронизируем status → is_active
            if (isset($update['status'])) {
                $update['is_active'] = $update['status'] !== 'fired' ? 1 : 0;
            }
            // PIN смена
            if (!empty($body['pin']) && strlen((string)$body['pin']) >= 4) {
                $name = $update['name'] ?? $existing['name'];
                $update['pin_hash'] = hashPin((string)$body['pin'], $name);
            }

            if (!empty($update)) {
                $db->update('staff', $update, ['id' => $id]);
                $log->action('STAFF_UPDATE', 'ID: ' . $id);
            }
            $row = $db->selectOne('staff', ['id' => $id]);
            unset($row['pin_hash']);
            $row['position'] = $row['position'] ?? $row['role'] ?? '';
            $row['status']   = $row['status']   ?? ($row['is_active'] ? 'active' : 'fired');
            $res->ok(['data' => $row]);
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            // Мягкое удаление — деактивируем
            $db->update('staff', ['is_active' => 0, 'status' => 'fired'], ['id' => $id]);
            $log->action('STAFF_DELETE', 'ID: ' . $id);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

function handleStaffVerify(array $body, CQLite $db, Response $res): void
{
    $member = $db->selectOne('staff', ['name' => $body['name'] ?? '', 'is_active' => 1]);
    if (!$member) { $res->ok(['ok' => false, 'error' => 'Не найден']); return; }

    $hash = hashPin((string)($body['pin'] ?? ''), $body['name']);
    if ($hash === $member['pin_hash']) {
        $res->ok(['ok' => true, 'staff' => [
            'id'    => $member['id'],
            'name'  => $member['name'],
            'role'  => $member['role'],
            'color' => $member['color'],
        ]]);
    } else {
        $res->ok(['ok' => false, 'error' => 'Неверный PIN']);
    }
}

function handleStaffLog(CQLite $db, Response $res): void
{
    $rows = $db->select('staff_log', [], ['order' => 'created_at DESC', 'limit' => 50]);
    $res->ok(['data' => $rows]);
}

function hashPin(string $pin, string $name): string
{
    return hash('sha256', $pin . '::' . mb_strtolower(mb_substr($name, 0, 4)));
}

// ════════════════════════════════════════════════════════════
// HANDLERS — ЗАРПЛАТА
// ════════════════════════════════════════════════════════════
function handleSalary(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {
    switch ($method) {

        case 'GET':
            $where   = [];
            $options = ['order' => 'created_at DESC'];
            if (!empty($params['staff_id'])) $where['staff_id'] = $params['staff_id'];
            if (!empty($params['period']))   $where['period']   = $params['period'];

            $rows = $db->select('salary', $where, $options);

            // Нормализуем поля — comment → note, staff_id/staff_name алиасы
            $rows = array_map(function ($r) {
                $r['note']      = $r['note']      ?? $r['comment']    ?? '';
                $r['staffId']   = $r['staffId']   ?? $r['staff_id']   ?? '';
                $r['staffName'] = $r['staffName'] ?? $r['staff_name'] ?? '';
                return $r;
            }, $rows);

            $res->ok(['data' => $rows]);
            break;

        case 'POST':
            // Принимаем оба варианта имени поля
            $staffName = $body['staff_name'] ?? $body['staffName'] ?? '';
            $staffId   = $body['staff_id']   ?? $body['staffId']   ?? null;
            $note      = $body['note']       ?? $body['comment']   ?? '';

            if (empty($staffName) && $staffId) {
                $emp = $db->selectOne('staff', ['id' => $staffId]);
                $staffName = $emp['name'] ?? 'Неизвестно';
            }

            if (empty($staffName)) {
                $res->badRequest('Нет staff_name или staffName'); return;
            }
            if (empty($body['amount']) || (float)$body['amount'] <= 0) {
                $res->badRequest('Нет суммы amount'); return;
            }

            $row = [
                'staff_id'   => $staffId,
                'staff_name' => $staffName,
                'type'       => $body['type']   ?? 'salary',
                'amount'     => (float)$body['amount'],
                'period'     => $body['period'] ?? date('Y-m'),
                'comment'    => $note,  // в БД колонка comment
            ];
            $newId     = $db->insert('salary', $row);
            $row['id'] = $newId;
            // Возвращаем с алиасами для JS
            $row['note']      = $note;
            $row['staffId']   = $staffId;
            $row['staffName'] = $staffName;

            $log->action('SALARY_ADD', $staffName . ' ' . $row['amount'] . '₽');
            $res->ok(['data' => $row, 'id' => $newId]);
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete('salary', ['id' => $id]);
            $log->action('SALARY_DELETE', 'ID: ' . $id);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

function handleSalaryEmployees(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res
): void {
    handleStaff($method, $body, $params, $id, $db, $res, new Logger($db));
}

function handleSalaryShifts(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res
): void {
    handleShifts($method, $body, $params, $id, $db, $res, new Logger($db));
}

// ════════════════════════════════════════════════════════════
// HANDLERS — СМЕНЫ (ПОЛНЫЙ CRUD + операции кассы)
// ════════════════════════════════════════════════════════════
function handleShifts(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {

    // Узнаём реальные колонки таблицы один раз
    $realCols = array_column(
        $db->query("PRAGMA table_info(shifts)"),
        'name'
    );
    $has = fn(string $col) => in_array($col, $realCols);

    switch ($method) {

        case 'GET':
            $rows = $db->query(
                "SELECT * FROM shifts ORDER BY " .
                ($has('opened_at') ? 'opened_at' : 'created_at') .
                " DESC LIMIT 50"
            );
            // Декодируем operations если колонка есть
            $rows = array_map(function ($r) use ($has) {
                if ($has('operations')) {
                    $r['operations'] = json_decode($r['operations'] ?? '[]', true) ?: [];
                } else {
                    $r['operations'] = [];
                }
                // Унифицируем поля для JS
                $r['manager']   = $r['manager']    ?? $r['staff_name'] ?? '';
                $r['openTime']  = $r['open_time']  ?? $r['opened_at']  ?? '';
                $r['closeTime'] = $r['close_time'] ?? $r['closed_at']  ?? '';
                $r['completed'] = (int)($r['completed'] ?? ($r['status'] === 'closed' ? 1 : 0));
                return $r;
            }, $rows);

            $current = null;
            $history = [];
            foreach ($rows as $r) {
              $isOpen = ($r['status'] ?? '') === 'open' && ($r['completed'] ?? 0) == 0;
                if ($isOpen && !$current) $current = $r;
                else $history[] = $r;
            }
            $res->ok(['data' => ['current' => $current, 'history' => $history]]);
            break;

        case 'POST':
            $action = $body['action'] ?? 'open';
            if ($action !== 'open') {
                $res->badRequest('Для операций используй PUT');
                return;
            }

            // Проверяем открытую смену
            $openCol   = $has('opened_at') ? 'opened_at' : 'created_at';
            $statusCol = $has('status') ? 'status' : null;

            if ($statusCol) {
                $existing = $db->query("SELECT id FROM shifts WHERE status = 'open' LIMIT 1");
            } else {
                $existing = $db->query("SELECT id FROM shifts WHERE completed = 0 LIMIT 1");
            }
            if (!empty($existing)) {
                $res->error('Смена уже открыта', 409);
                return;
            }

            $empId     = trim($body['empId'] ?? '');
            $manager   = trim($body['manager'] ?? 'Менеджер');
            $startCash = (float)($body['startCash'] ?? 0);
            $bonusPct  = 0.10;

            if ($empId) {
                $emp = $db->selectOne('staff', ['id' => $empId]);
                if ($emp) {
                    $manager  = $emp['name'];
                    $bonusPct = (float)($emp['bonus_pct'] ?? 0.10);
                }
            }

            $now = date('Y-m-d H:i:s');
            $uid = CQLite::uid('sh');

            // Собираем только реально существующие колонки
            $row = ['id' => $uid];
            if ($has('staff_id'))      $row['staff_id']      = $empId ?: null;
            if ($has('staff_name'))    $row['staff_name']    = $manager;
            if ($has('manager'))       $row['manager']       = $manager;
            if ($has('emp_id'))        $row['emp_id']        = $empId ?: null;
            if ($has('start_cash'))    $row['start_cash']    = $startCash;
            if ($has('cash'))          $row['cash']          = $startCash;
            if ($has('bonus_pct'))     $row['bonus_pct']     = $bonusPct;
            if ($has('total_income'))  $row['total_income']  = 0;
            if ($has('total_expense')) $row['total_expense'] = 0;
            if ($has('accrued_bonus')) $row['accrued_bonus'] = 0;
            if ($has('operations'))    $row['operations']    = '[]';
            if ($has('opened_at'))     $row['opened_at']     = $now;
            if ($has('open_time'))     $row['open_time']     = $now;
          if ($has('status'))    $upd['status']    = 'closed';
           if ($has('completed')) $upd['completed'] = 1;
            if ($has('notes'))         $row['notes']         = $body['notes'] ?? '';
            if ($has('income'))        $row['income']        = 0;
            if ($has('expense'))       $row['expense']       = 0;
            if ($has('orders_count'))  $row['orders_count']  = 0;

            $db->insert('shifts', $row);
            $log->action('SHIFT_OPEN', $manager . ' | startCash:' . $startCash);

            $row['operations'] = [];
            $row['manager']    = $manager;
            $row['openTime']   = $now;
            $res->ok(['data' => $row]);
            break;

        case 'PUT':
            $action = $body['action'] ?? '';

            // Находим открытую смену
            if ($has('status')) {
                $shifts = $db->query("SELECT * FROM shifts WHERE status = 'open' ORDER BY " .
                    ($has('opened_at') ? 'opened_at' : 'created_at') . " DESC LIMIT 1");
            } else {
                $shifts = $db->query("SELECT * FROM shifts WHERE completed = 0 ORDER BY created_at DESC LIMIT 1");
            }
            $shift = $shifts[0] ?? null;
            if (!$shift) { $res->error('Смена не открыта', 400); return; }

            $operations = $has('operations')
                ? (json_decode($shift['operations'] ?? '[]', true) ?: [])
                : [];
            $bonusPct = (float)($shift['bonus_pct'] ?? 0.10);

            // ── Добавить операцию ────────────────────────
            if ($action === 'operation') {
                $type      = $body['type']   ?? 'income';
                $amount    = (float)($body['amount'] ?? 0);
                $qty       = max(1, (int)($body['qty'] ?? 1));
                $desc      = trim($body['desc']   ?? '');
                $methodPay = trim($body['method'] ?? 'Наличные');

                if ($amount <= 0)  { $res->badRequest('Сумма должна быть > 0'); return; }
                if (!in_array($type, ['income','expense'])) { $res->badRequest('Неверный тип'); return; }

                $finalAmount = round($amount * $qty, 2);
                $finalDesc   = $qty > 1 ? $desc . ' × ' . $qty : $desc;

                $op = [
                    'id'     => CQLite::uid('op'),
                    'type'   => $type,
                    'amount' => $finalAmount,
                    'qty'    => $qty,
                    'price'  => $amount,
                    'desc'   => $finalDesc,
                    'method' => $methodPay,
                    'time'   => date('Y-m-d H:i:s'),
                ];
                $operations[] = $op;

                $upd = [];
                if ($has('operations')) $upd['operations'] = json_encode($operations, JSON_UNESCAPED_UNICODE);

                if ($type === 'income') {
                    if ($has('cash'))          $upd['cash']          = (float)($shift['cash']          ?? 0) + $finalAmount;
                    if ($has('total_income'))  $upd['total_income']  = (float)($shift['total_income']  ?? 0) + $finalAmount;
                    if ($has('accrued_bonus')) $upd['accrued_bonus'] = (float)($shift['accrued_bonus'] ?? 0) + round($finalAmount * $bonusPct, 2);
                    if ($has('income'))        $upd['income']        = (float)($shift['income']        ?? 0) + $finalAmount;
                } else {
                    if ($has('cash'))          $upd['cash']          = (float)($shift['cash']          ?? 0) - $finalAmount;
                    if ($has('total_expense')) $upd['total_expense'] = (float)($shift['total_expense'] ?? 0) + $finalAmount;
                    if ($has('expense'))       $upd['expense']       = (float)($shift['expense']       ?? 0) + $finalAmount;
                }

                if (!empty($upd)) $db->update('shifts', $upd, ['id' => $shift['id']]);

                // Пишем в finance
                $db->insert('finance', [
                    'id'          => CQLite::uid('fin'),
                    'type'        => $type,
                    'category'    => $type === 'income' ? 'Выручка кассы' : 'Прочие расходы',
                    'description' => '💳 Смена [' . ($shift['manager'] ?? $shift['staff_name'] ?? '') . '] — ' . $finalDesc,
                    'amount'      => $finalAmount,
                    'method'      => $methodPay,
                    'client'      => $shift['manager'] ?? $shift['staff_name'] ?? '',
                    'date'        => date('Y-m-d H:i:s'),
                ]);

                $updated = $db->selectOne('shifts', ['id' => $shift['id']]);
                $updated['operations'] = $operations;
                $updated['manager']    = $updated['manager'] ?? $updated['staff_name'] ?? '';
                $res->ok(['data' => $updated, 'operation' => $op]);

            // ── Удалить операцию ─────────────────────────
            } elseif ($action === 'deleteOperation') {
                $opId   = $body['opId'] ?? null;
                $found  = null;
                $newOps = [];
                foreach ($operations as $op) {
                    if ((string)$op['id'] === (string)$opId) { $found = $op; continue; }
                    $newOps[] = $op;
                }
                if (!$found) { $res->notFound('Операция ' . $opId); return; }

                $upd = [];
                if ($has('operations')) $upd['operations'] = json_encode($newOps, JSON_UNESCAPED_UNICODE);

                if ($found['type'] === 'income') {
                    if ($has('cash'))          $upd['cash']          = max(0, (float)($shift['cash']          ?? 0) - $found['amount']);
                    if ($has('total_income'))  $upd['total_income']  = max(0, (float)($shift['total_income']  ?? 0) - $found['amount']);
                    if ($has('accrued_bonus')) $upd['accrued_bonus'] = max(0, (float)($shift['accrued_bonus'] ?? 0) - round($found['amount'] * $bonusPct, 2));
                    if ($has('income'))        $upd['income']        = max(0, (float)($shift['income']        ?? 0) - $found['amount']);
                } else {
                    if ($has('cash'))          $upd['cash']          = (float)($shift['cash']          ?? 0) + $found['amount'];
                    if ($has('total_expense')) $upd['total_expense'] = max(0, (float)($shift['total_expense'] ?? 0) - $found['amount']);
                    if ($has('expense'))       $upd['expense']       = max(0, (float)($shift['expense']       ?? 0) - $found['amount']);
                }

                if (!empty($upd)) $db->update('shifts', $upd, ['id' => $shift['id']]);

                $updated = $db->selectOne('shifts', ['id' => $shift['id']]);
                $updated['operations'] = $newOps;
                $updated['manager']    = $updated['manager'] ?? $updated['staff_name'] ?? '';
                $res->ok(['data' => $updated]);

            // ── Закрыть смену ────────────────────────────
            } elseif ($action === 'close') {
                $endCash    = (float)($body['endCash']    ?? $shift['cash'] ?? 0);
                $baseSalary = (float)($body['baseSalary'] ?? 0);
                $note       = trim($body['note'] ?? '');
                $accBonus   = (float)($shift['accrued_bonus'] ?? 0);
                $totalSal   = round($baseSalary + $accBonus, 2);
                $calcCash   = (float)($shift['cash'] ?? 0);
                $cashDiff   = round($endCash - $calcCash, 2);
                $closeTime  = date('Y-m-d H:i:s');
                $openedAt   = $shift['opened_at'] ?? $shift['open_time'] ?? $shift['created_at'] ?? $closeTime;
                $shiftDate  = date('d.m.Y', strtotime($openedAt));
                $manager    = $shift['manager'] ?? $shift['staff_name'] ?? '';

                $upd = [];
                if ($has('status'))        $upd['status']        = 'closed';
                if ($has('completed'))     $upd['completed']     = 1;
                if ($has('closed_at'))     $upd['closed_at']     = $closeTime;
                if ($has('close_time'))    $upd['close_time']    = $closeTime;
                if ($has('end_cash'))      $upd['end_cash']      = $endCash;
                if ($has('cash_diff'))     $upd['cash_diff']     = $cashDiff;
                if ($has('base_salary'))   $upd['base_salary']   = $baseSalary;
                if ($has('total_salary'))  $upd['total_salary']  = $totalSal;
                if ($has('notes'))         $upd['notes']         = $note;
                if ($has('income'))        $upd['income']        = (float)($shift['total_income']  ?? $shift['income']  ?? 0);
                if ($has('expense'))       $upd['expense']       = (float)($shift['total_expense'] ?? $shift['expense'] ?? 0);
                if ($has('orders_count'))  $upd['orders_count']  = count($operations);

                if (!empty($upd)) $db->update('shifts', $upd, ['id' => $shift['id']]);

                // Зарплата → salary
                if ($totalSal > 0) {
                    $staffId = $shift['staff_id'] ?? $shift['emp_id'] ?? null;
                    $db->insert('salary', [
                        'staff_id'   => $staffId,
                        'staff_name' => $manager,
                        'type'       => 'salary',
                        'amount'     => $totalSal,
                        'period'     => date('Y-m'),
                        'comment'    => 'Смена ' . $shiftDate . ' | оклад: ' . $baseSalary . '₽ | бонус: ' . $accBonus . '₽',
                    ]);
                }

                // Расхождение → finance
                if (abs($cashDiff) >= 0.01) {
                    $db->insert('finance', [
                        'id'          => CQLite::uid('fin'),
                        'type'        => $cashDiff < 0 ? 'expense' : 'income',
                        'category'    => $cashDiff < 0 ? 'Недостача кассы' : 'Излишек кассы',
                        'description' => ($cashDiff < 0 ? '⚠️ Недостача' : '✅ Излишек') . ' | Смена ' . $shiftDate . ' [' . $manager . ']',
                        'amount'      => abs($cashDiff),
                        'method'      => 'Наличные',
                        'client'      => $manager,
                        'date'        => $closeTime,
                    ]);
                }

                // Разбивка по методам
                $methodTotals = [];
                foreach ($operations as $op) {
                    $m = $op['method'] ?? 'Наличные';
                    if (!isset($methodTotals[$m])) $methodTotals[$m] = ['income' => 0, 'expense' => 0];
                    $methodTotals[$m][$op['type']] += $op['amount'];
                }

                $report = [
                    'id'              => 'report_' . $shift['id'],
                    'shiftId'         => $shift['id'],
                    'manager'         => $manager,
                    'shiftDate'       => $shiftDate,
                    'openTime'        => $openedAt,
                    'closeTime'       => $closeTime,
                    'startCash'       => (float)($shift['start_cash'] ?? 0),
                    'endCash'         => $endCash,
                    'calcCash'        => $calcCash,
                    'cashDiff'        => $cashDiff,
                    'totalIncome'     => (float)($shift['total_income']  ?? $shift['income']  ?? 0),
                    'totalExpense'    => (float)($shift['total_expense'] ?? $shift['expense'] ?? 0),
                    'baseSalary'      => $baseSalary,
                    'bonusPct'        => $bonusPct,
                    'accruedBonus'    => $accBonus,
                    'totalSalary'     => $totalSal,
                    'operationsCount' => count($operations),
                    'operations'      => $operations,
                    'methodTotals'    => $methodTotals,
                    'note'            => $note,
                ];

                $log->action('SHIFT_CLOSE',
                    $manager . ' | доход:' . ($shift['total_income'] ?? 0) .
                    ' | расход:' . ($shift['total_expense'] ?? 0) .
                    ' | зп:' . $totalSal . ' | расхождение:' . $cashDiff
                );
                $res->ok(['data' => $shift, 'report' => $report]);

            } else {
                $res->badRequest('Неверный action: ' . $action);
            }
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete('shifts', ['id' => $id]);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

// ════════════════════════════════════════════════════════════
// HANDLERS — ОТКРЫТИЕ / ЗАКРЫТИЕ СМЕНЫ (заглушки-делегаторы)
// ════════════════════════════════════════════════════════════
function handleShiftOpen(array $body, CQLite $db, Response $res, Logger $log): void
{
    // Делегируем в handleShifts POST
    handleShifts('POST', array_merge($body, ['action' => 'open']), [], null, $db, $res, $log);
}

function handleShiftClose(array $body, CQLite $db, Response $res, Logger $log): void
{
    // Делегируем в handleShifts PUT
    handleShifts('PUT', array_merge($body, ['action' => 'close']), [], null, $db, $res, $log);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — ДОЛГИ
// ════════════════════════════════════════════════════════════
function handleDebts(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {
    switch ($method) {

        case 'GET':
            $where = [];
            if (!empty($params['status'])) $where['status'] = $params['status'];
            $rows = $db->select('debts', $where, ['order' => 'created_at DESC']);
            $res->ok(['data' => $rows]);
            break;

        case 'POST':
            $v = new Validator($body);
            $v->required('client')->required('amount');
            if ($v->fails()) { $res->badRequest($v->errors()); return; }

            $row = [
                'id'          => CQLite::uid('dbt'),
                'client'      => $body['client'],
                'phone'       => $body['phone']       ?? '',
                'amount'      => (float)$body['amount'],
                'description' => $body['description'] ?? '',
                'order_id'    => $body['order_id']    ?? null,
                'status'      => 'open',
                'paid'        => 0,
            ];
            $db->insert('debts', $row);
            $log->action('DEBT_ADD', $row['client'] . ' ' . $row['amount'] . '₽');
            $res->ok(['data' => $row, 'id' => $row['id']]);
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete('debts', ['id' => $id]);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

function handleDebtPay(array $body, CQLite $db, Response $res, Logger $log): void
{
    $id     = $body['id']     ?? null;
    $amount = (float)($body['amount'] ?? 0);

    if (!$id || $amount <= 0) { $res->badRequest('Нет ID или суммы'); return; }

    $debt = $db->selectOne('debts', ['id' => $id]);
    if (!$debt) { $res->notFound('Долг ' . $id); return; }

    $newPaid = (float)$debt['paid'] + $amount;
    $status  = $newPaid >= (float)$debt['amount'] ? 'closed' : 'partial';

    $db->update('debts', ['paid' => $newPaid, 'status' => $status], ['id' => $id]);
    $db->insert('finance', [
        'id'          => CQLite::uid('fin'),
        'type'        => 'income',
        'category'    => 'Погашение долга',
        'description' => 'Долг от ' . $debt['client'],
        'amount'      => $amount,
        'method'      => $body['method'] ?? 'Наличные',
        'client'      => $debt['client'],
        'date'        => date('Y-m-d H:i:s'),
    ]);

    $log->action('DEBT_PAY', $debt['client'] . ' +' . $amount . '₽');
    $res->ok(['status' => $status, 'paid' => $newPaid]);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — ЗАГРУЗКА ФАЙЛОВ
// ════════════════════════════════════════════════════════════
function handleUpload(CQLite $db, Response $res, Logger $log): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $res->methodNotAllowed(); return;
    }
    if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0755, true);
    if (empty($_FILES['file'])) { $res->badRequest('Файл не передан'); return; }

    $file     = $_FILES['file'];
    $origName = basename($file['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed  = ['jpg','jpeg','png','gif','pdf','ai','cdr','eps','tif','tiff','psd',
                 'doc','docx','xlsx','zip','svg','webp'];

    if (!in_array($ext, $allowed)) { $res->badRequest('Недопустимый тип файла: ' . $ext); return; }
    if ($file['size'] > 50 * 1024 * 1024) { $res->badRequest('Файл больше 50МБ'); return; }

    $orderId  = $_POST['order_id'] ?? '';
    $prefix   = $orderId ? $orderId . '_' : '';
    $newName  = $prefix . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $destPath = UPLOADS_DIR . '/' . $newName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        $res->serverError('Ошибка сохранения файла'); return;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? '';
    $fileUrl  = $protocol . '://' . $host . '/data/uploads/' . $newName;

    $log->action('FILE_UPLOAD', $origName . ' → ' . $newName);
    $res->ok([
        'filename' => $newName,
        'origName' => $origName,
        'url'      => $fileUrl,
        'size'     => $file['size'],
        'ext'      => $ext,
    ]);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — УВЕДОМЛЕНИЯ
// ════════════════════════════════════════════════════════════
function handleNotify(array $body, CQLite $db, Response $res, Logger $log): void
{
    $event    = $body['event'] ?? 'unknown';
    $data     = $body['data']  ?? [];
    $results  = [];
    $settings = $db->getSettings();

    $tgToken  = $settings['tgToken']  ?? '';
    $tgBossId = $settings['tgBossId'] ?? '';

    if ($tgToken && $tgBossId) {
        $text               = buildNotifyText($event, $data, $settings);
        $tgResult           = sendTelegram($tgToken, $tgBossId, $text);
        $results['telegram'] = $tgResult;
        $db->insert('notifications_log', [
            'channel'   => 'telegram',
            'event'     => $event,
            'recipient' => $tgBossId,
            'payload'   => json_encode($data, JSON_UNESCAPED_UNICODE),
            'status'    => $tgResult ? 'sent' : 'failed',
        ]);
    }

    if (!empty($settings['maxEnabled'])) {
        $results['max'] = dispatchToMax($event, $data, $settings, $db);
    }
    if (!empty($settings['vkEnabled'])) {
        $results['vk'] = dispatchToVk($event, $data, $settings, $db);
    }

    $log->action('NOTIFY', $event);
    $res->ok(['event' => $event, 'results' => $results]);
}

function buildNotifyText(string $event, array $data, array $settings): string
{
    $company = $settings['company'] ?? 'PrintCRM';

    return match ($event) {
        'order_new' => sprintf(
            "📋 <b>Новый заказ %s</b>\n👤 %s\n📞 %s\n🖨 %s\n💰 %s₽\n🏢 %s",
            $data['num'] ?? '', $data['client'] ?? '', $data['phone'] ?? '—',
            $data['service_label'] ?? '', $data['total'] ?? 0, $company
        ),
        'order_status' => sprintf(
            "🔄 <b>Заказ %s — статус изменён</b>\n📌 %s\n👤 %s",
            $data['num'] ?? '',
            ['new'=>'🆕 Новый','work'=>'⚙️ В работе','ready'=>'✅ Готов','done'=>'📦 Выдан','cancel'=>'❌ Отменён'][$data['status'] ?? ''] ?? ($data['status'] ?? ''),
            $data['client'] ?? ''
        ),
        'order_done' => sprintf(
            "📦 <b>Заказ %s выдан!</b>\n👤 %s\n💰 %s₽",
            $data['num'] ?? '', $data['client'] ?? '', $data['total'] ?? 0
        ),
        'finance_income' => sprintf(
            "💰 <b>Доход: %s₽</b>\n📂 %s\n💳 %s",
            $data['amount'] ?? 0, $data['category'] ?? '', $data['method'] ?? ''
        ),
        'finance_expense' => sprintf(
            "📤 <b>Расход: %s₽</b>\n📂 %s",
            $data['amount'] ?? 0, $data['category'] ?? ''
        ),
        'warehouse_low' => sprintf(
            "⚠️ <b>Заканчивается на складе</b>\n📦 %s\n📉 Остаток: %s %s",
            $data['name'] ?? '', $data['qty'] ?? 0, $data['unit'] ?? ''
        ),
        'day_summary' => sprintf(
            "📊 <b>Итог дня — %s</b>\n📋 Заказов: %s (выдано: %s)\n💰 Доход: %s₽\n📤 Расход: %s₽\n📈 Прибыль: %s₽",
            date('d.m.Y'),
            $data['orders_count'] ?? 0, $data['orders_done'] ?? 0,
            $data['income'] ?? 0, $data['expense'] ?? 0,
            ($data['income'] ?? 0) - ($data['expense'] ?? 0)
        ),
        'test'    => "🔔 <b>Тест уведомление</b>\nPrintCRM v" . APP_VERSION . " работает! ✅",
        default   => "📢 <b>Событие: {$event}</b>\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    };
}

function sendTelegram(string $token, string $chatId, string $text): bool
{
    $url  = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = http_build_query([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ]);
    $ctx    = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => $data,
        'timeout' => 5,
    ]]);
    $result = @file_get_contents($url, false, $ctx);
    return $result !== false;
}

function dispatchToMax(string $event, array $data, array $settings, CQLite $db): bool
{
    $url = $settings['maxWebhookUrl'] ?? '';
    if (!$url) return false;

    $payload = json_encode(['event' => $event, 'data' => $data], JSON_UNESCAPED_UNICODE);
    $ctx     = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-Api-Key: " . ($settings['maxApiKey'] ?? ''),
        'content' => $payload,
        'timeout' => 5,
    ]]);
    $result  = @file_get_contents($url, false, $ctx);
    $db->insert('notifications_log', [
        'channel'   => 'max',
        'event'     => $event,
        'recipient' => $data['phone'] ?? '',
        'payload'   => $payload,
        'status'    => $result !== false ? 'sent' : 'failed',
    ]);
    return $result !== false;
}

function dispatchToVk(string $event, array $data, array $settings, CQLite $db): bool
{
    $url = $settings['vkWebhookUrl'] ?? '';
    if (!$url) return false;

    $payload = json_encode(['event' => $event, 'data' => $data], JSON_UNESCAPED_UNICODE);
    $ctx     = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-Api-Key: " . ($settings['vkApiKey'] ?? ''),
        'content' => $payload,
        'timeout' => 5,
    ]]);
    $result  = @file_get_contents($url, false, $ctx);
    $db->insert('notifications_log', [
        'channel'   => 'vk',
        'event'     => $event,
        'recipient' => $data['phone'] ?? '',
        'payload'   => $payload,
        'status'    => $result !== false ? 'sent' : 'failed',
    ]);
    return $result !== false;
}

// ════════════════════════════════════════════════════════════
// HANDLERS — ВЕБХУКИ (входящие)
// ════════════════════════════════════════════════════════════
function handleWebhook(
    array $path, string $method, array $body,
    CQLite $db, Response $res, Logger $log
): void {
    $channel = $path[1] ?? '';
    $log->action('WEBHOOK_IN', $channel . ' ' . json_encode($body, JSON_UNESCAPED_UNICODE));

    match ($channel) {

        'telegram' => (function () use ($body, $db, $res, $log) {
            $msg    = $body['message'] ?? $body['callback_query']['message'] ?? null;
            $text   = $msg['text']      ?? '';
            $chatId = $msg['chat']['id'] ?? null;

            if ($text === '/status') {
                $settings = $db->getSettings();
                $orders   = $db->count('orders', ['status IN' => ['new','work']]);
                $today    = date('Y-m-d');
                $income   = $db->sum('finance', 'amount', ['type' => 'income', 'date LIKE' => $today . '%']);
                sendTelegram(
                    $settings['tgToken'] ?? '', (string)$chatId,
                    "📊 <b>Сводка</b>\n⚙️ Активных заказов: {$orders}\n💰 Доход сегодня: {$income}₽"
                );
            }
            $res->ok(['ok' => true]);
        })(),

        'vk' => (function () use ($body, $db, $res, $log) {
            if (($body['type'] ?? '') === 'confirmation') {
                $settings = $db->getSettings();
                echo $settings['vkConfirmCode'] ?? 'ok';
                exit();
            }
            if (($body['type'] ?? '') === 'message_new') {
                $msg    = $body['object']['message'] ?? [];
                $text   = $msg['text']     ?? '';
                $peerId = $msg['peer_id']  ?? $msg['from_id'] ?? null;
                $log->action('VK_MSG', "от {$peerId}: {$text}");
            }
            $res->raw('ok');
        })(),

        'max' => (function () use ($body, $db, $res, $log) {
            $event = $body['event'] ?? '';
            $data  = $body['data']  ?? [];
            $log->action('MAX_EVENT', $event);
            if ($event === 'new_order') {
                $db->insert('weborders', [
                    'id'      => CQLite::uid('wo'),
                    'source'  => 'max',
                    'client'  => $data['client']  ?? '',
                    'phone'   => $data['phone']   ?? '',
                    'message' => $data['message'] ?? '',
                    'status'  => 'new',
                    'raw'     => json_encode($data, JSON_UNESCAPED_UNICODE),
                ]);
            }
            $res->ok(['ok' => true]);
        })(),

        'ozon_acquiring' => (function () use ($body, $db, $res, $log) {
            $orderId = $body['order_id'] ?? null;
            $status  = $body['status']   ?? '';
            $amount  = (float)($body['amount'] ?? 0);
            if ($orderId && ($status === 'succeeded' || $status === 'paid')) {
                $db->update('orders', ['payment_status' => 'paid'], ['id' => $orderId]);
                $db->insert('finance', [
                    'id'          => CQLite::uid('fin'),
                    'type'        => 'income',
                    'category'    => 'Эквайринг Ozon',
                    'description' => 'Оплата по заказу #' . $orderId,
                    'amount'      => $amount,
                    'method'      => 'Карта (эквайринг)',
                    'order_id'    => $orderId,
                    'date'        => date('Y-m-d H:i:s'),
                ]);
                $log->action('OZON_PAYMENT', 'order:' . $orderId . ' +' . $amount . '₽');
            }
            $res->ok(['ok' => true]);
        })(),

        'weborders' => (function () use ($body, $db, $res, $log) {
            $row = [
                'id'      => CQLite::uid('wo'),
                'source'  => $body['source']  ?? 'website',
                'client'  => $body['client']  ?? $body['name']    ?? '',
                'phone'   => $body['phone']   ?? '',
                'email'   => $body['email']   ?? '',
                'message' => $body['message'] ?? $body['comment'] ?? '',
                'service' => $body['service'] ?? '',
                'status'  => 'new',
                'raw'     => json_encode($body, JSON_UNESCAPED_UNICODE),
            ];
            $db->insert('weborders', $row);
            $log->action('WEBORDER_IN', $row['client'] . ' — ' . $row['service']);
            $settings = $db->getSettings();
            if (!empty($settings['tgToken']) && !empty($settings['tgBossId'])) {
                sendTelegram(
                    $settings['tgToken'], $settings['tgBossId'],
                    "🌐 <b>Новый заказ с сайта</b>\n👤 {$row['client']}\n📞 {$row['phone']}\n💬 {$row['message']}"
                );
            }
            $res->ok(['ok' => true, 'id' => $row['id']]);
        })(),

        default => $res->notFound('webhook/' . $channel),
    };
}

// ════════════════════════════════════════════════════════════
// HANDLERS — POS ТЕРМИНАЛЫ
// ════════════════════════════════════════════════════════════
function handlePosPayment(array $body, CQLite $db, Response $res, Logger $log): void
{
    $provider = $body['provider'] ?? 'sberbank';
    $amount   = (float)($body['amount'] ?? 0);
    $orderId  = $body['order_id'] ?? null;

    if ($amount <= 0) { $res->badRequest('Нет суммы'); return; }

    $settings = $db->getSettings();

    $result = match ($provider) {
        'sberbank' => [
            'status'      => 'pending',
            'provider'    => 'sberbank',
            'description' => 'Интеграция Сбербанк — настройте в разделе Интеграции',
            'endpoint'    => '/api/pos/callback',
        ],
        'tinkoff' => [
            'status'      => 'pending',
            'provider'    => 'tinkoff',
            'description' => 'Интеграция Тинькофф — настройте в разделе Интеграции',
            'endpoint'    => '/api/pos/callback',
        ],
        'ozon' => (function () use ($body, $amount, $orderId, $settings, $db, $log) {
            $apiUrl    = $settings['acquiring_api_url']    ?? 'https://payapi.ozon.ru';
            $accessKey = $settings['acquiring_access_key'] ?? '';
            $secretKey = $settings['acquiring_secret_key'] ?? '';

            if (!$accessKey || !$secretKey) {
                return ['status' => 'error', 'error' => 'Не настроены ключи Ozon Acquiring'];
            }
            $payload   = json_encode([
                'amount'       => (int)($amount * 100),
                'currency'     => 'RUB',
                'order_id'     => $orderId ?? CQLite::uid('pay'),
                'description'  => $body['description'] ?? 'Оплата заказа',
                'success_url'  => $settings['acquiring_success_url'] ?? '',
                'fail_url'     => $settings['acquiring_fail_url']    ?? '',
                'notify_url'   => $settings['acquiring_notify_url']  ?? '',
            ]);
            $signature = hash_hmac('sha256', $payload, $secretKey);
            $ctx       = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nX-Access-Key: {$accessKey}\r\nX-Signature: {$signature}",
                'content' => $payload,
                'timeout' => 10,
            ]]);
            $result    = @file_get_contents("{$apiUrl}/v1/payment/create", false, $ctx);
            if (!$result) return ['status' => 'error', 'error' => 'Ozon API недоступен'];
            $data = json_decode($result, true);
            $log->action('POS_OZON', 'order:' . $orderId . ' amount:' . $amount);
            return $data ?? ['status' => 'error', 'error' => 'Неверный ответ'];
        })(),
        'sbp' => [
            'status'      => 'pending',
            'provider'    => 'sbp',
            'qr_type'     => 'static',
            'description' => 'Интеграция СБП — настройте в разделе Интеграции',
            'endpoint'    => '/api/pos/callback',
        ],
        default => ['status' => 'error', 'error' => 'Неизвестный провайдер: ' . $provider],
    };

    $res->ok($result);
}

function handlePosStatus(array $params, CQLite $db, Response $res): void
{
    $paymentId = $params['payment_id'] ?? null;
    if (!$paymentId) { $res->badRequest('Нет payment_id'); return; }

    $res->ok([
        'payment_id' => $paymentId,
        'status'     => 'pending',
        'message'    => 'Проверьте статус через панель провайдера',
    ]);
}

function handlePosCallback(array $body, CQLite $db, Response $res, Logger $log): void
{
    $provider = $body['provider'] ?? 'unknown';
    $orderId  = $body['order_id'] ?? null;
    $status   = $body['status']   ?? '';
    $amount   = (float)($body['amount'] ?? 0);

    $log->action('POS_CALLBACK', "{$provider} order:{$orderId} status:{$status}");

    if ($status === 'succeeded' || $status === 'paid') {
        if ($orderId) {
            $db->update('orders', ['payment_status' => 'paid'], ['id' => $orderId]);
        }
        $db->insert('finance', [
            'id'          => CQLite::uid('fin'),
            'type'        => 'income',
            'category'    => 'POS ' . $provider,
            'description' => "Оплата через {$provider}" . ($orderId ? " #{$orderId}" : ''),
            'amount'      => $amount,
            'method'      => 'Карта (POS)',
            'order_id'    => $orderId,
            'date'        => date('Y-m-d H:i:s'),
        ]);
    }
    $res->ok(['ok' => true]);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — ИНТЕГРАЦИИ
// ════════════════════════════════════════════════════════════
function handleIntegrations(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {
    switch ($method) {

        case 'GET':
            $rows = $db->select('integrations', [], ['order' => 'name ASC']);
            $rows = array_map(function ($r) {
                if (isset($r['config'])) {
                    $r['config'] = json_decode($r['config'], true) ?? [];
                }
                return $r;
            }, $rows);
            $res->ok(['data' => $rows]);
            break;

        case 'POST':
            if ($id) {
                $update = [];
                if (isset($body['config']))    $update['config']    = json_encode($body['config'], JSON_UNESCAPED_UNICODE);
                if (isset($body['is_active'])) $update['is_active'] = (int)$body['is_active'];
                if (isset($body['name']))      $update['name']      = $body['name'];
                $db->update('integrations', $update, ['id' => $id]);
                $log->action('INTEGRATION_UPDATE', $id);
                $res->ok(['updated' => $id]);
            } else {
                $row = [
                    'id'        => CQLite::uid('int'),
                    'name'      => $body['name']      ?? '',
                    'type'      => $body['type']      ?? '',
                    'config'    => json_encode($body['config'] ?? [], JSON_UNESCAPED_UNICODE),
                    'is_active' => (int)($body['is_active'] ?? 0),
                ];
                $db->insert('integrations', $row);
                $log->action('INTEGRATION_ADD', $row['name']);
                $res->ok(['data' => $row, 'id' => $row['id']]);
            }
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete('integrations', ['id' => $id]);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

function handleIntegrationTest(array $body, CQLite $db, Response $res, Logger $log): void
{
    $id   = $body['id']   ?? null;
    $type = $body['type'] ?? null;

    if (!$id && !$type) { $res->badRequest('Нет ID или type'); return; }

    $integration = $id ? $db->selectOne('integrations', ['id' => $id]) : null;
    $type        = $type ?? ($integration['type'] ?? '');
    $config      = $integration ? json_decode($integration['config'] ?? '{}', true) : [];

    $result = match ($type) {
        'telegram' => (function () use ($db) {
            $s  = $db->getSettings();
            $ok = sendTelegram($s['tgToken'] ?? '', $s['tgBossId'] ?? '', '🧪 Тест соединения PrintCRM v' . APP_VERSION);
            return ['ok' => $ok, 'message' => $ok ? 'Telegram работает' : 'Ошибка Telegram'];
        })(),
        'ping'    => ['ok' => true, 'message' => 'Сервер отвечает', 'time' => date('Y-m-d H:i:s')],
        default   => ['ok' => false, 'message' => 'Тест для ' . $type . ' не реализован'],
    };

    $log->action('INTEGRATION_TEST', $type . ' ok:' . ($result['ok'] ? '1' : '0'));
    $res->ok($result);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — МОДУЛИ (динамические PHP)
// ════════════════════════════════════════════════════════════
function handleModule(
    string $method, array $body, array $params,
    CQLite $db, Response $res, Logger $log
): void {
    $moduleId = preg_replace('/[^a-z0-9_]/', '', strtolower($params['module'] ?? $body['module'] ?? ''));
    $action   = $params['action'] ?? $body['action'] ?? '';

    if (!$moduleId) { $res->badRequest('Нет module'); return; }

    if ($action === '__getjs__') {
        $file = MODULES_DIR . '/' . $moduleId . '.php';
        if (!file_exists($file)) { http_response_code(404); echo ''; exit(); }
        $content = file_get_contents($file);
        $pos     = strpos($content, '</php>');
        header('Content-Type: text/html; charset=utf-8');
        echo $pos !== false ? substr($content, $pos) : '';
        exit();
    }

    $moduleFile = MODULES_DIR . '/' . $moduleId . '.php';
    if (!file_exists($moduleFile)) {
        $res->notFound('Модуль ' . $moduleId);
        return;
    }

    $moduleDB     = $db;
    $moduleAction = $action;
    $moduleBody   = $body;
    $moduleParams = $params;

    ob_start();
    require $moduleFile;
    $output = ob_get_clean();

    $jsMarker  = strpos($output, '</php>');
    $jsonPart  = trim($jsMarker !== false ? substr($output, 0, $jsMarker) : $output);

    echo $jsonPart ?: json_encode(['ok' => true, 'data' => null]);
}

function handleRegistry(Response $res): void
{
    $modules = [];
    $files   = glob(MODULES_DIR . '/*.php') ?: [];

    foreach ($files as $file) {
        $lines = array_slice(file($file), 0, 30);
        $meta  = [
            'id'          => str_replace('.php', '', basename($file)),
            'name'        => '',
            'icon'        => '🧩',
            'description' => '',
            'version'     => '1.0',
            'sidebar'     => true,
            'color'       => '#7c3aed',
        ];
        foreach ($lines as $line) {
            if (preg_match('/@name\s+(.+)/',        $line, $m)) $meta['name']        = trim($m[1]);
            if (preg_match('/@icon\s+(.+)/',        $line, $m)) $meta['icon']        = trim($m[1]);
            if (preg_match('/@description\s+(.+)/', $line, $m)) $meta['description'] = trim($m[1]);
            if (preg_match('/@version\s+(.+)/',     $line, $m)) $meta['version']     = trim($m[1]);
            if (preg_match('/@sidebar\s+(.+)/',     $line, $m)) $meta['sidebar']     = trim($m[1]) === 'true';
            if (preg_match('/@color\s+(.+)/',       $line, $m)) $meta['color']       = trim($m[1]);
        }
        if (!$meta['name']) $meta['name'] = ucfirst($meta['id']);
        $modules[] = $meta;
    }
    $res->ok(['modules' => $modules]);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — ВЕБ-ЗАКАЗЫ
// ════════════════════════════════════════════════════════════
function handleWebOrders(
    string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log
): void {
    switch ($method) {

        case 'GET':
            $where = [];
            if (!empty($params['status'])) $where['status'] = $params['status'];
            $rows = $db->select('weborders', $where, ['order' => 'created_at DESC', 'limit' => 100]);
            $res->ok(['data' => $rows]);
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete('weborders', ['id' => $id]);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

function handleWebOrderAccept(array $body, CQLite $db, Response $res, Logger $log): void
{
    $id = $body['id'] ?? null;
    if (!$id) { $res->badRequest('Нет ID'); return; }

    $wo = $db->selectOne('weborders', ['id' => $id]);
    if (!$wo) { $res->notFound('Веб-заказ ' . $id); return; }

    $orderRow = [
        'id'            => CQLite::uid('ord'),
        'num'           => autoOrderNum($db),
        'client'        => $wo['client'],
        'phone'         => $wo['phone']   ?? '',
        'service'       => $wo['service'] ?? 'other',
        'service_label' => $wo['service'] ?? 'Прочее',
        'comment'       => $wo['message'] ?? '',
        'status'        => 'new',
        'payment'       => 'Наличные',
        'total'         => 0,
        'prepay'        => 0,
        'created_at'    => date('Y-m-d H:i:s'),
        'files'         => '[]',
        'options'       => '[]',
        'extra'         => json_encode(['source' => $wo['source'] ?? 'website']),
    ];
    $db->insert('orders', $orderRow);
    $db->update('weborders', ['status' => 'accepted'], ['id' => $id]);
    autoCreateClient($wo['client'], $wo['phone'] ?? '', $db);

    $log->action('WEBORDER_ACCEPT', $wo['client'] . ' → ' . $orderRow['num']);
    $res->ok(['data' => decodeOrderRow($orderRow), 'order_num' => $orderRow['num']]);
}

function handleWebOrderReject(array $body, CQLite $db, Response $res, Logger $log): void
{
    $id = $body['id'] ?? null;
    if (!$id) { $res->badRequest('Нет ID'); return; }
    $db->update('weborders', ['status' => 'rejected'], ['id' => $id]);
    $log->action('WEBORDER_REJECT', 'ID: ' . $id);
    $res->ok(['rejected' => $id]);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — АНАЛИТИКА
// ════════════════════════════════════════════════════════════
function handleAnalytics(array $params, CQLite $db, Response $res): void
{
    $from = $params['from'] ?? date('Y-m-01');
    $to   = $params['to']   ?? date('Y-m-d');

    $dailyIncome = $db->query(
        'SELECT DATE(date) as day, SUM(amount) as total
         FROM finance WHERE type = "income" AND DATE(date) BETWEEN ? AND ?
         GROUP BY day ORDER BY day',
        [$from, $to]
    );
    $byService = $db->query(
        'SELECT service_label, COUNT(*) as cnt, SUM(total) as revenue
         FROM orders WHERE DATE(created_at) BETWEEN ? AND ?
         GROUP BY service_label ORDER BY revenue DESC',
        [$from, $to]
    );
    $statusStats = $db->query(
        'SELECT status, COUNT(*) as cnt
         FROM orders WHERE DATE(created_at) BETWEEN ? AND ?
         GROUP BY status',
        [$from, $to]
    );
    $topClients = $db->query(
        'SELECT client, COUNT(*) as orders, SUM(total) as revenue
         FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND client != ""
         GROUP BY client ORDER BY revenue DESC LIMIT 10',
        [$from, $to]
    );

    $res->ok([
        'period'       => ['from' => $from, 'to' => $to],
        'daily_income' => $dailyIncome,
        'by_service'   => $byService,
        'by_status'    => $statusStats,
        'top_clients'  => $topClients,
    ]);
}

function handleAnalyticsExport(array $params, CQLite $db, Response $res): void
{
    $format  = $params['format'] ?? 'json';
    $from    = $params['from']   ?? date('Y-m-01');
    $to      = $params['to']     ?? date('Y-m-d');
    $orders  = $db->query('SELECT * FROM orders  WHERE DATE(created_at) BETWEEN ? AND ?', [$from, $to]);
    $finance = $db->query('SELECT * FROM finance WHERE DATE(date)       BETWEEN ? AND ?', [$from, $to]);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="printcrm_export_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['=== ЗАКАЗЫ ===']);
        if ($orders) {
            fputcsv($out, array_keys($orders[0]));
            foreach ($orders as $row) fputcsv($out, $row);
        }
        fputcsv($out, []);
        fputcsv($out, ['=== ФИНАНСЫ ===']);
        if ($finance) {
            fputcsv($out, array_keys($finance[0]));
            foreach ($finance as $row) fputcsv($out, $row);
        }
        fclose($out);
        exit();
    }

    $res->ok([
        'orders'  => array_map('decodeOrderRow', $orders),
        'finance' => $finance,
        'period'  => ['from' => $from, 'to' => $to],
    ]);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — ПРОЧИЕ МОДУЛИ
// ════════════════════════════════════════════════════════════
function handleBriefs(string $m, array $b, array $p, ?string $id, CQLite $db, Response $res, Logger $log): void
{
    handleGenericCRUD('briefs', $m, $b, $p, $id, $db, $res, $log,
        ['client','staff','service','content','status'],
        ['id' => CQLite::uid('br'), 'status' => 'draft']
    );
}

function handleChecklists(string $m, array $b, array $p, ?string $id, CQLite $db, Response $res): void
{
    handleGenericCRUD('checklists', $m, $b, $p, $id, $db, $res, new Logger($db),
        ['name','items','order_id','completed','staff'],
        ['id' => CQLite::uid('cl')]
    );
}

// FIX: таблица checklist_templates может не существовать — fallback на пустой массив
function handleChecklistTemplates(string $m, array $b, array $p, ?string $id, CQLite $db, Response $res): void
{
    try {
        $rows = $db->select('checklist_templates', [], ['order' => 'name ASC']);
    } catch (Throwable) {
        $rows = [];
    }
    $res->ok(['data' => $rows]);
}

function handlePricelist(string $m, array $b, array $p, ?string $id, CQLite $db, Response $res): void
{
    handleGenericCRUD('pricelist', $m, $b, $p, $id, $db, $res, new Logger($db),
        ['service','name','unit','price_from','price_to','description'],
        ['id' => CQLite::uid('pr')]
    );
}

function handleTemplates(string $m, array $b, array $p, ?string $id, CQLite $db, Response $res): void
{
    handleGenericCRUD('doc_templates', $m, $b, $p, $id, $db, $res, new Logger($db),
        ['name','type','content','variables'],
        ['id' => CQLite::uid('tpl')]
    );
}

function handleSchedule(string $m, array $b, array $p, ?string $id, CQLite $db, Response $res): void
{
    handleGenericCRUD('schedule', $m, $b, $p, $id, $db, $res, new Logger($db),
        ['staff_id','staff_name','date','shift_start','shift_end','notes'],
        ['id' => CQLite::uid('sch')]
    );
}

function handleLayouts(string $m, array $b, array $p, ?string $id, CQLite $db, Response $res): void
{
    handleGenericCRUD('layouts', $m, $b, $p, $id, $db, $res, new Logger($db),
        ['name','order_id','file_url','status','comment'],
        ['id' => CQLite::uid('lay'), 'status' => 'pending']
    );
}

function handleTimer(string $m, array $b, array $p, CQLite $db, Response $res): void
{
    if ($m === 'GET') {
        try {
            $rows = $db->select('timers', [], ['order' => 'created_at DESC', 'limit' => 20]);
        } catch (Throwable) {
            $rows = [];
        }
        $res->ok(['data' => $rows]);
    } else {
        $res->methodNotAllowed();
    }
}

function handleTimerStart(array $b, CQLite $db, Response $res): void
{
    $row = [
        'id'         => CQLite::uid('tmr'),
        'name'       => $b['name']     ?? '',
        'order_id'   => $b['order_id'] ?? null,
        'started_at' => date('Y-m-d H:i:s'),
        'status'     => 'running',
    ];
    $db->insert('timers', $row);
    $res->ok(['data' => $row]);
}

function handleTimerStop(array $b, CQLite $db, Response $res): void
{
    $id = $b['id'] ?? null;
    if (!$id) { $res->badRequest('Нет ID'); return; }
    $timer = $db->selectOne('timers', ['id' => $id]);
    if (!$timer) { $res->notFound('Таймер'); return; }
    $seconds = time() - strtotime($timer['started_at']);
    $db->update('timers', [
        'status'       => 'stopped',
        'stopped_at'   => date('Y-m-d H:i:s'),
        'duration_sec' => $seconds,
    ], ['id' => $id]);
    $res->ok(['duration_sec' => $seconds, 'duration' => gmdate('H:i:s', $seconds)]);
}

function handleQueue(string $m, array $b, array $p, ?string $id, CQLite $db, Response $res): void
{
    handleGenericCRUD('queue', $m, $b, $p, $id, $db, $res, new Logger($db),
        ['client','phone','service','status','position','comment'],
        ['id' => CQLite::uid('q'), 'status' => 'waiting']
    );
}

// FIX: selectOne без третьего аргумента
function handleQueueNext(CQLite $db, Response $res): void
{
    $next = $db->query(
        'SELECT * FROM queue WHERE status = "waiting" ORDER BY created_at ASC LIMIT 1'
    );
    $next = $next[0] ?? null;
    if (!$next) { $res->ok(['data' => null, 'message' => 'Очередь пуста']); return; }
    $db->update('queue', ['status' => 'serving'], ['id' => $next['id']]);
    $res->ok(['data' => $next]);
}

function handleSavings(string $m, array $b, array $p, ?string $id, CQLite $db, Response $res): void
{
    handleGenericCRUD('savings', $m, $b, $p, $id, $db, $res, new Logger($db),
        ['name','amount','goal','description'],
        ['id' => CQLite::uid('sav')]
    );
}

function handleDelivery(string $m, array $b, array $p, ?string $id, CQLite $db, Response $res, Logger $log): void
{
    handleGenericCRUD('delivery', $m, $b, $p, $id, $db, $res, $log,
        ['order_id','client','phone','address','status','courier','scheduled_at','notes'],
        ['id' => CQLite::uid('del'), 'status' => 'pending']
    );
}

function handleSizeguide(string $m, array $b, array $p, CQLite $db, Response $res): void
{
    if ($m === 'GET') {
        try {
            $rows = $db->select('sizeguide', [], ['order' => 'service ASC, name ASC']);
        } catch (Throwable) {
            $rows = [];
        }
        $res->ok(['data' => $rows]);
    } elseif ($m === 'POST') {
        $row = [
            'id'      => CQLite::uid('sz'),
            'service' => $b['service'] ?? '',
            'name'    => $b['name']    ?? '',
            'width'   => (float)($b['width']  ?? 0),
            'height'  => (float)($b['height'] ?? 0),
            'unit'    => $b['unit']    ?? 'мм',
            'notes'   => $b['notes']   ?? '',
        ];
        $db->insert('sizeguide', $row);
        $res->ok(['data' => $row]);
    } else {
        $res->methodNotAllowed();
    }
}

function handleStamps(string $m, array $b, array $p, ?string $id, CQLite $db, Response $res): void
{
    handleGenericCRUD('stamps', $m, $b, $p, $id, $db, $res, new Logger($db),
        ['name','type','order_id','file_url','parameters'],
        ['id' => CQLite::uid('stmp')]
    );
}

function handleDocParse(Response $res): void
{
    $res->ok([
        'status'    => 'stub',
        'message'   => 'Парсер документов будет доступен на VPS',
        'supported' => ['pdf','docx','xlsx','jpg','png'],
        'features'  => [
            'pdf_info'    => 'Метаданные PDF (страниц, размер, DPI)',
            'image_info'  => 'Размер и DPI изображения',
            'price_parse' => 'Парсинг Excel прайс-листа',
            'ocr'         => 'OCR текста (будет на VPS)',
        ],
    ]);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — БД
// ════════════════════════════════════════════════════════════
function handleDbInfo(CQLite $db, Response $res): void
{
    $size   = $db->getDbSize();
    $tables = $db->getTables();
    $counts = [];
    foreach ($tables as $table) {
        try { $counts[$table] = $db->count($table); } catch (Throwable) { $counts[$table] = 0; }
    }
    $res->ok([
        'version'    => APP_VERSION,
        'size_kb'    => $size['kb'],
        'size_mb'    => $size['mb'],
        'tables'     => $counts,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function handleDbClear(CQLite $db, Response $res, Logger $log): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $res->methodNotAllowed(); return; }

    $tables = ['orders','finance','clients','notes','warehouse','warehouse_movements',
               'cal_events','shifts','salary','debts','notifications_log','api_log',
               'weborders','staff_log'];
    $db->transaction(function () use ($tables, $db) {
        foreach ($tables as $table) {
            try { $db->execute("DELETE FROM {$table}"); } catch (Throwable) {}
        }
    });
    $log->action('DB_CLEAR', 'Все таблицы очищены');
    $res->ok(['message' => 'База данных очищена']);
}

function handleDbAlter(array $body, CQLite $db, Response $res): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $res->methodNotAllowed(); return; }
    $sql = trim($body['sql'] ?? '');
    // Только ALTER TABLE ADD COLUMN — безопасно
    if (!preg_match('/^ALTER TABLE \w+ ADD COLUMN /i', $sql)) {
        $res->badRequest('Только ALTER TABLE ADD COLUMN разрешён'); return;
    }
    try {
        $db->execute($sql);
        $res->ok(['sql' => $sql, 'done' => true]);
    } catch (\Throwable $e) {
        // Колонка уже есть — не страшно
        $res->ok(['sql' => $sql, 'done' => false, 'info' => $e->getMessage()]);
    }
}

function handleImport(array $body, CQLite $db, Response $res, Logger $log): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $res->methodNotAllowed(); return; }

    $imported = ['orders' => 0, 'finance' => 0, 'clients' => 0];

    $db->transaction(function () use ($body, $db, &$imported) {

        if (!empty($body['orders']) && is_array($body['orders'])) {
            foreach ($body['orders'] as $o) {
                if (empty($o['id'])) continue;
                if (!$db->selectOne('orders', ['id' => (string)$o['id']])) {
                    $db->insert('orders', [
                        'id'            => (string)$o['id'],
                        'num'           => $o['num']           ?? '',
                        'client'        => $o['client']        ?? '',
                        'phone'         => $o['phone']         ?? '',
                        'manager'       => $o['manager']       ?? '',
                        'service'       => $o['service']       ?? 'other',
                        'service_label' => $o['serviceLabel']  ?? $o['service_label'] ?? '',
                        'size'          => $o['size']          ?? '',
                        'status'        => $o['status']        ?? 'done',
                        'payment'       => $o['payment']       ?? 'Наличные',
                        'total'         => (float)($o['total']  ?? 0),
                        'prepay'        => (float)($o['prepay'] ?? 0),
                        'bizcat'        => $o['bizcat']        ?? '',
                        'deadline'      => $o['deadline']      ?? null,
                        'comment'       => $o['comment']       ?? '',
                        'files'         => is_array($o['files'] ?? null)
                            ? json_encode($o['files'])
                            : ($o['files'] ?? '[]'),
                        'options'       => is_array($o['options'] ?? $o['checkedItems'] ?? null)
                            ? json_encode($o['options'] ?? $o['checkedItems'])
                            : '[]',
                        'extra'         => '{}',
                        'created_at'    => $o['date'] ?? $o['createdAt'] ?? date('Y-m-d H:i:s'),
                    ]);
                    $imported['orders']++;
                }
            }
        }

        if (!empty($body['finance']) && is_array($body['finance'])) {
            foreach ($body['finance'] as $f) {
                if (empty($f['id'])) continue;
                if (!$db->selectOne('finance', ['id' => (string)$f['id']])) {
                    $db->insert('finance', [
                        'id'          => (string)$f['id'],
                        'type'        => $f['type']        ?? 'income',
                        'category'    => $f['category']    ?? '',
                        'description' => $f['desc']        ?? $f['description'] ?? '',
                        'amount'      => (float)($f['amount'] ?? 0),
                        'method'      => $f['method']      ?? '',
                        'client'      => $f['client']      ?? '',
                        'date'        => $f['date']        ?? date('Y-m-d H:i:s'),
                    ]);
                    $imported['finance']++;
                }
            }
        }

        if (!empty($body['clients']) && is_array($body['clients'])) {
            foreach ($body['clients'] as $c) {
                if (empty($c['name'])) continue;
                if (!$db->selectOne('clients', ['name' => $c['name']])) {
                    $db->insert('clients', [
                        'id'       => (string)($c['id'] ?? CQLite::uid('cli')),
                        'name'     => $c['name'],
                        'type'     => $c['type']     ?? '',
                        'phone'    => $c['phone']    ?? '',
                        'email'    => $c['email']    ?? '',
                        'address'  => $c['address']  ?? '',
                        'inn'      => $c['inn']       ?? '',
                        'discount' => (float)($c['discount'] ?? 0),
                        'notes'    => $c['notes']    ?? '',
                    ]);
                    $imported['clients']++;
                }
            }
        }

        if (!empty($body['settings']) && is_array($body['settings'])) {
            $db->setSettings($body['settings']);
        }
    });

    $log->action('IMPORT', "orders:{$imported['orders']} finance:{$imported['finance']} clients:{$imported['clients']}");
    $res->ok(['imported' => $imported]);
}

// ════════════════════════════════════════════════════════════
// HANDLERS — ЛОГ
// ════════════════════════════════════════════════════════════
function handleLog(array $params, CQLite $db, Response $res): void
{
    $limit = min(500, (int)($params['limit'] ?? 100));
    $type  = $params['type'] ?? '';
    $where = $type ? ['action LIKE' => $type . '%'] : [];
    $rows  = $db->select('api_log', $where, ['order' => 'created_at DESC', 'limit' => $limit]);
    $res->ok(['data' => $rows]);
}

// ════════════════════════════════════════════════════════════
// GENERIC CRUD
// ════════════════════════════════════════════════════════════
function handleGenericCRUD(
    string $table, string $method, array $body, array $params,
    ?string $id, CQLite $db, Response $res, Logger $log,
    array $allowedFields, array $defaults = []
): void {
    switch ($method) {

        case 'GET':
            if ($id) {
                $row = $db->selectOne($table, ['id' => $id]);
                if (!$row) { $res->notFound("{$table} {$id}"); return; }
                $res->ok(['data' => $row]);
                return;
            }
            $page   = max(1, (int)($params['page'] ?? 1));
            $result = $db->paginate($table, [], $page, 100, ['order' => 'created_at DESC']);
            $res->ok($result);
            break;

        case 'POST':
            $row = $defaults;
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $body)) $row[$field] = $body[$field];
            }
            if (!isset($row['id'])) $row['id'] = CQLite::uid($table);
            $newId      = $db->insert($table, $row);
            $row['id']  = $row['id'] ?? $newId;
            $log->action(strtoupper($table) . '_ADD', $row['id']);
            $res->ok(['data' => $row, 'id' => $row['id']]);
            break;

        case 'PUT':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $update = [];
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $body)) $update[$field] = $body[$field];
            }
            if (!empty($update)) $db->update($table, $update, ['id' => $id]);
            $res->ok(['data' => $db->selectOne($table, ['id' => $id])]);
            break;

        case 'DELETE':
            if (!$id) { $res->badRequest('Нет ID'); return; }
            $db->delete($table, ['id' => $id]);
            $log->action(strtoupper($table) . '_DELETE', 'ID: ' . $id);
            $res->ok(['deleted' => $id]);
            break;

        default:
            $res->methodNotAllowed();
    }
}

// ════════════════════════════════════════════════════════════
// BRIDGE — модуль Кассы/Смены
// ════════════════════════════════════════════════════════════
function handleShiftModule(string $action, array $body, array $params, CQLite $db, Response $res): void
{
    $request = new Request();
    $body['action'] = $action;
    $shiftFile = MODULES_DIR . '/shift.php';
    if (!file_exists($shiftFile)) {
        $res->serverError('Модуль shift.php не найден в ' . MODULES_DIR);
        return;
    }
    require $shiftFile;
}