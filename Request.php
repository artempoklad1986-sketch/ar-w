<?php
// api/core/Request.php
// ============================================================
declare(strict_types=1);

class Request
{
    private array  $_body   = [];
    private array  $_params = [];
    private array  $_path   = [];
    private string $_method = 'GET';

    public function __construct()
    {
        $this->_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->_params = $_GET;

        // Парсим тело
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->_body = $decoded;
            }
        }

        // Мержим POST
        if (!empty($_POST)) {
            $this->_body = array_merge($this->_body, $_POST);
        }

        // Парсим путь из URL
        // Поддерживаем два формата:
        // 1. /api/orders          → ['orders']
        // 2. /api/?action=orders  → ['orders'] (legacy)
        $this->_parsePath();
    }

    private function _parsePath(): void
    {
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        $path   = parse_url($uri, PHP_URL_PATH) ?? '/';

        // Убираем /api/ из начала
        $path = preg_replace('#^/api/?#', '', $path);
        $path = trim($path, '/');

        if ($path) {
            $this->_path = explode('/', $path);
        } else {
            // Legacy: ?action=xxx или ?module=xxx&action=xxx
            $action = $this->_params['action'] ?? '';
            $module = $this->_params['module'] ?? '';

            if ($module) {
                $this->_path = ['module'];
            } elseif ($action) {
                $this->_path = [$action];
            } else {
                $this->_path = ['ping'];
            }
        }
    }

    public function method():   string { return $this->_method; }
    public function path():     array  { return $this->_path; }
    public function body():     array  { return $this->_body; }
    public function params():   array  { return $this->_params; }
    public function ip():       string { return $_SERVER['REMOTE_ADDR'] ?? ''; }
    public function getEndpoint(): string { return $this->_path[0] ?? ''; }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->_params[$key] ?? $this->_body[$key] ?? $default;
    }
}