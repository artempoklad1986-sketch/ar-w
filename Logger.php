<?php
// api/core/Logger.php
// ============================================================
declare(strict_types=1);

class Logger
{
    private CQLite $db;
    private string $logFile;

    public function __construct(CQLite $db)
    {
        $this->db      = $db;
        $this->logFile = LOGS_DIR . '/api.log';
        if (!is_dir(LOGS_DIR)) mkdir(LOGS_DIR, 0755, true);
    }

    public function action(string $action, string $details = ''): void
    {
        $this->_write($action, $details);
    }

    public function api(string $method, string $endpoint, string $ip): void
    {
        // В БД — только важные запросы, не каждый GET
        if ($method !== 'GET') {
            try {
                $this->db->insert('api_log', [
                    'method'     => $method,
                    'endpoint'   => $endpoint,
                    'ip'         => $ip,
                    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
                    'status_code'=> 200,
                ]);
            } catch (Throwable) {}
        }
    }

    public function error(string $context, string $message, string $trace = ''): void
    {
        $this->_write('ERROR_' . $context, $message . ($trace ? ' | ' . substr($trace, 0, 300) : ''));
    }

    private function _write(string $action, string $details): void
    {
        $line = date('Y-m-d H:i:s')
            . ' | ' . $action
            . ' | ' . $details
            . ' | ' . ($_SERVER['REMOTE_ADDR'] ?? '')
            . "\n";

        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}