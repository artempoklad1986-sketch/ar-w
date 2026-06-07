<?php
// api/core/Response.php
// ============================================================
declare(strict_types=1);

class Response
{
    public function ok(array $data = []): never
    {
        http_response_code(200);
        echo json_encode(
            array_merge(['ok' => true], $data),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit();
    }

    public function raw(string $text): never
    {
        echo $text;
        exit();
    }

    public function badRequest(string|array $error): never
    {
        http_response_code(400);
        echo json_encode(
            ['ok' => false, 'error' => $error],
            JSON_UNESCAPED_UNICODE
        );
        exit();
    }

    public function unauthorized(): never
    {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit();
    }

    public function notFound(string $what = ''): never
    {
        http_response_code(404);
        echo json_encode(
            ['ok' => false, 'error' => 'Не найдено: ' . $what],
            JSON_UNESCAPED_UNICODE
        );
        exit();
    }

    public function methodNotAllowed(): never
    {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Метод не разрешён']);
        exit();
    }

    public function serverError(string $message = ''): never
    {
        http_response_code(500);
        echo json_encode(
            ['ok' => false, 'error' => 'Внутренняя ошибка', 'detail' => $message],
            JSON_UNESCAPED_UNICODE
        );
        exit();
    }
}