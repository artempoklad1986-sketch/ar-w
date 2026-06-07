<?php
// ============================================================
// CQLite.php — Database Engine v1.0
// Обёртка над PDO SQLite для PrintCRM
// PHP 8.2+ | Shared hosting (Beget)
// ============================================================

declare(strict_types=1);

class CQLite
{
    private static ?CQLite $instance = null;
    private PDO    $pdo;
    private string $dbPath;
    private int    $queryCount = 0;
    private array  $queryLog   = [];
    private bool   $debug;

    public const BUSY_TIMEOUT = 5000;
    public const CACHE_SIZE   = 4000;
    public const PAGE_SIZE    = 4096;

    // ── Конструктор ─────────────────────────────────────────
    private function __construct(string $dbPath, bool $debug = false)
    {
        $this->dbPath = $dbPath;
        $this->debug  = $debug;
        $this->connect();
        $this->applyPragmas();
    }

    // ── Singleton ───────────────────────────────────────────
    public static function getInstance(string $dbPath = '', bool $debug = false): self
    {
        if (self::$instance === null) {
            if (!$dbPath) {
                throw new \RuntimeException('CQLite: путь к БД не указан');
            }
            self::$instance = new self($dbPath, $debug);
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    // ── Подключение ─────────────────────────────────────────
    private function connect(): void
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        try {
            $this->pdo = new PDO(
                'sqlite:' . $this->dbPath,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 10,
                ]
            );
        } catch (\PDOException $e) {
            throw new \RuntimeException('CQLite: ошибка подключения — ' . $e->getMessage());
        }
    }

    // ── PRAGMA ──────────────────────────────────────────────
    private function applyPragmas(): void
    {
        $pragmas = [
            'journal_mode = WAL',
            'synchronous  = NORMAL',
            'busy_timeout = ' . self::BUSY_TIMEOUT,
            'cache_size   = ' . self::CACHE_SIZE,
            'page_size    = ' . self::PAGE_SIZE,
            'foreign_keys = ON',
            'temp_store   = MEMORY',
        ];
        foreach ($pragmas as $p) {
            $this->pdo->exec('PRAGMA ' . $p);
        }
    }

    // ════════════════════════════════════════════════════════
    // QUERY BUILDER
    // ════════════════════════════════════════════════════════

    public function select(string $table, array $where = [], array $options = []): array
    {
        [$sql, $params] = $this->buildSelect($table, $where, $options);
        return $this->query($sql, $params);
    }

    public function selectOne(string $table, array $where = [], array $options = []): ?array
    {
        $options['limit'] = 1;
        $rows = $this->select($table, $where, $options);
        return $rows[0] ?? null;
    }

    public function insert(string $table, array $data): string|int
    {
        if (empty($data)) throw new \InvalidArgumentException('CQLite::insert — пустые данные');
        $data = $this->prepareData($data);
        $cols = array_keys($data);
        $phs  = array_map(fn($c) => ':' . $c, $cols);
        $sql  = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteTable($table),
            implode(', ', $cols),
            implode(', ', $phs)
        );
        $this->execute($sql, $data);
        return $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        if (empty($data))  throw new \InvalidArgumentException('CQLite::update — пустые данные');
        if (empty($where)) throw new \InvalidArgumentException('CQLite::update — пустой WHERE');

        $data = $this->prepareData($data);

        // auto updated_at если колонка есть
        $cols = array_column($this->query("PRAGMA table_info(\"{$table}\")"), 'name');
        if (in_array('updated_at', $cols)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        $setParts   = [];
        $bindParams = [];
        foreach ($data as $col => $val) {
            $setParts[]              = $col . ' = :set_' . $col;
            $bindParams['set_' . $col] = $val;
        }

        [$whereSql, $whereParams] = $this->buildWhere($where, 'w_');
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteTable($table),
            implode(', ', $setParts),
            $whereSql
        );
        $this->execute($sql, array_merge($bindParams, $whereParams));
        return (int)$this->pdo->query('SELECT changes()')->fetchColumn();
    }

    public function delete(string $table, array $where): int
    {
        if (empty($where)) throw new \InvalidArgumentException('CQLite::delete — пустой WHERE');
        [$whereSql, $params] = $this->buildWhere($where);
        $sql = sprintf('DELETE FROM %s WHERE %s', $this->quoteTable($table), $whereSql);
        $this->execute($sql, $params);
        return (int)$this->pdo->query('SELECT changes()')->fetchColumn();
    }

    public function upsert(string $table, array $data): void
    {
        if (empty($data)) throw new \InvalidArgumentException('CQLite::upsert — пустые данные');
        $data = $this->prepareData($data);
        $cols = array_keys($data);
        $phs  = array_map(fn($c) => ':' . $c, $cols);
        $sql  = sprintf(
            'INSERT OR REPLACE INTO %s (%s) VALUES (%s)',
            $this->quoteTable($table),
            implode(', ', $cols),
            implode(', ', $phs)
        );
        $this->execute($sql, $data);
    }

    public function count(string $table, array $where = []): int
    {
        [$whereSql, $params] = $this->buildWhere($where);
        $sql = 'SELECT COUNT(*) FROM ' . $this->quoteTable($table);
        if ($whereSql) $sql .= ' WHERE ' . $whereSql;
        return (int)$this->raw($sql, $params)->fetchColumn();
    }

    public function sum(string $table, string $column, array $where = []): float
    {
        [$whereSql, $params] = $this->buildWhere($where);
        $sql = 'SELECT COALESCE(SUM(' . $column . '), 0) FROM ' . $this->quoteTable($table);
        if ($whereSql) $sql .= ' WHERE ' . $whereSql;
        return (float)$this->raw($sql, $params)->fetchColumn();
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->raw($sql, $params)->fetchAll();
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->raw($sql, $params);
    }

    public function raw(string $sql, array $params = []): \PDOStatement
    {
        $start = microtime(true);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                'CQLite::raw SQL Error: ' . $e->getMessage() . ' | SQL: ' . $sql
            );
        }
        $this->queryCount++;
        if ($this->debug) {
            $this->queryLog[] = [
                'sql'    => $sql,
                'params' => $params,
                'ms'     => round((microtime(true) - $start) * 1000, 2),
            ];
        }
        return $stmt;
    }

    // ════════════════════════════════════════════════════════
    // ТРАНЗАКЦИИ
    // ════════════════════════════════════════════════════════

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // ════════════════════════════════════════════════════════
    // PAGINATION
    // ════════════════════════════════════════════════════════

    public function paginate(
        string $table,
        array  $where   = [],
        int    $page    = 1,
        int    $perPage = 20,
        array  $options = []
    ): array {
        $page    = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $total   = $this->count($table, $where);
        $pages   = (int)ceil($total / $perPage);

        $options['limit']  = $perPage;
        $options['offset'] = ($page - 1) * $perPage;

        return [
            'data'     => $this->select($table, $where, $options),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $pages,
        ];
    }

    // ════════════════════════════════════════════════════════
    // SETTINGS HELPERS
    // ════════════════════════════════════════════════════════

    public function getSetting(string $key, mixed $default = null): mixed
    {
        $row = $this->selectOne('settings', ['key' => $key]);
        if (!$row) return $default;
        $decoded = json_decode($row['value'], true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $row['value'];
    }

    public function setSetting(string $key, mixed $value): void
    {
        $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        $this->upsert('settings', [
            'key'        => $key,
            'value'      => $encoded,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getSettings(array $keys = []): array
    {
        $rows = empty($keys)
            ? $this->select('settings')
            : $this->query(
                'SELECT * FROM settings WHERE key IN (' .
                implode(',', array_fill(0, count($keys), '?')) . ')',
                $keys
            );
        $result = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row['value'], true);
            $result[$row['key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row['value'];
        }
        return $result;
    }

    public function setSettings(array $map): void
    {
        $this->transaction(function () use ($map) {
            foreach ($map as $k => $v) {
                $this->setSetting($k, $v);
            }
        });
    }

    // ════════════════════════════════════════════════════════
    // УТИЛИТЫ
    // ════════════════════════════════════════════════════════

    public static function uid(string $prefix = ''): string
    {
        return $prefix . date('Ymd') . '_' . substr(bin2hex(random_bytes(6)), 0, 12);
    }

    public function getDbSize(): array
    {
        $size = file_exists($this->dbPath) ? filesize($this->dbPath) : 0;
        return [
            'bytes' => $size,
            'kb'    => round($size / 1024, 1),
            'mb'    => round($size / 1048576, 2),
        ];
    }

    public function optimize(): void
    {
        $this->pdo->exec('PRAGMA incremental_vacuum');
        $this->pdo->exec('PRAGMA optimize');
        $this->pdo->exec('ANALYZE');
    }

    public function getQueryLog(): array
    {
        return ['count' => $this->queryCount, 'queries' => $this->queryLog];
    }

    public function ping(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getTables(): array
    {
        return array_column(
            $this->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"),
            'name'
        );
    }

    // ════════════════════════════════════════════════════════
    // ПРИВАТНЫЕ ХЕЛПЕРЫ
    // ════════════════════════════════════════════════════════

    private function buildSelect(string $table, array $where, array $options): array
    {
        $cols    = $options['columns'] ?? ['*'];
        $colsSql = is_array($cols) ? implode(', ', $cols) : $cols;
        $sql     = 'SELECT ' . $colsSql . ' FROM ' . $this->quoteTable($table);
        $params  = [];

        [$whereSql, $whereParams] = $this->buildWhere($where);
        if ($whereSql) {
            $sql    .= ' WHERE ' . $whereSql;
            $params  = $whereParams;
        }
        if (!empty($options['order']))  $sql .= ' ORDER BY ' . $options['order'];
        if (!empty($options['limit']))  $sql .= ' LIMIT '    . (int)$options['limit'];
        if (!empty($options['offset'])) $sql .= ' OFFSET '   . (int)$options['offset'];

        return [$sql, $params];
    }

    private function buildWhere(array $where, string $prefix = ''): array
    {
        if (empty($where)) return ['', []];

        $parts  = [];
        $params = [];

        foreach ($where as $col => $val) {
            if (preg_match('/^(\w+)\s*(>=|<=|!=|>|<|LIKE|IN)$/i', $col, $m)) {
                $field    = $m[1];
                $operator = strtoupper($m[2]);

                if ($operator === 'IN' && is_array($val)) {
                    $phs = [];
                    foreach ($val as $i => $v) {
                        $ph        = $prefix . $field . '_in' . $i;
                        $phs[]     = ':' . $ph;
                        $params[$ph] = $v;
                    }
                    $parts[] = $field . ' IN (' . implode(',', $phs) . ')';
                } else {
                    $ph          = $prefix . $field;
                    $parts[]     = $field . ' ' . $operator . ' :' . $ph;
                    $params[$ph] = $val;
                }
            } elseif ($val === null) {
                $parts[] = $col . ' IS NULL';
            } else {
                $ph          = $prefix . preg_replace('/\W/', '_', $col);
                $parts[]     = $col . ' = :' . $ph;
                $params[$ph] = $val;
            }
        }

        return [implode(' AND ', $parts), $params];
    }

    private function prepareData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $val) {
            $result[$key] = (is_array($val) || is_object($val))
                ? json_encode($val, JSON_UNESCAPED_UNICODE)
                : $val;
        }
        return $result;
    }

    private function quoteTable(string $table): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException('CQLite: недопустимое имя таблицы: ' . $table);
        }
        return '"' . $table . '"';
    }
}