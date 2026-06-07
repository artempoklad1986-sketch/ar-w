<?php
// api/core/Auth.php
// ============================================================
declare(strict_types=1);

class Auth
{
    public static function check(Request $req): bool
    {
        $key = '';

        // 1. Header X-Api-Key
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === 'x-api-key') {
                    $key = $value;
                    break;
                }
            }
        }

        // 2. SERVER HTTP_X_API_KEY
        if (!$key) $key = $_SERVER['HTTP_X_API_KEY'] ?? '';

        // 3. GET параметр key
        if (!$key) $key = $_GET['key'] ?? '';

        // 4. Body
        if (!$key) $key = $req->body()['key'] ?? '';

        if (trim($key) !== API_KEY) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
            exit();
        }

        return true;
    }
}