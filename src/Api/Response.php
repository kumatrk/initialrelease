<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

/**
 * JSON response helpers for API v1.
 */
class Response
{
    /** @param array<string, mixed>|list<mixed> $data */
    public static function success(array $data, int $code = 200, ?array $meta = null): never
    {
        self::send([
            'data' => $data,
            'meta' => $meta,
        ], $code);
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public static function list(array $data, int $total, int $page, int $perPage, int $code = 200): never
    {
        self::send([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ], $code);
    }

    /** @param array<string, string|list<string>> $fields */
    public static function validationError(string $message, array $fields, int $code = 422): never
    {
        self::send([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
            ],
            'fields' => $fields,
        ], $code);
    }

    public static function error(string $message, int $code = 400, string $errorCode = 'error'): never
    {
        self::send([
            'error' => [
                'code' => $errorCode,
                'message' => $message,
            ],
        ], $code);
    }

    /** @param array<string, mixed> $payload */
    public static function send(array $payload, int $code = 200): never
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
