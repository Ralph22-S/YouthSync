<?php
declare(strict_types=1);

namespace YouthSync\Http;

final class Json
{
    public static function readBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
        }

        return $decoded;
    }

    public static function success(mixed $data = null, int $status = 200): never
    {
        if ($data === null) {
            $data = new \stdClass();
        }
        self::send($status, [
            'success' => true,
            'data' => $data,
        ]);
    }

    public static function error(string $code, string $message, int $status, array $extra = []): never
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        foreach ($extra as $key => $value) {
            $error[$key] = $value;
        }
        self::send($status, [
            'success' => false,
            'error' => $error,
        ]);
    }

    public static function validation(array $fields): never
    {
        $first = '';
        foreach ($fields as $message) {
            if (is_string($message) && $message !== '') {
                $first = $message;
                break;
            }
        }
        if ($first === '') {
            $first = 'Please correct the highlighted fields.';
        }
        self::error('VALIDATION_ERROR', $first, 422, ['fields' => $fields]);
    }

    public static function send(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
