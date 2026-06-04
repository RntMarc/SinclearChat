<?php

declare(strict_types=1);

namespace SinclearChat;

final class Response
{
    private int $statusCode;
    private array $data;
    private array $headers;

    public function __construct(array $data = [], int $statusCode = 200, array $headers = [])
    {
        $this->data = $data;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public static function success(array $data = [], int $status = 200): self
    {
        return new self($data, $status);
    }

    public static function created(array $data = []): self
    {
        return new self($data, 201);
    }

    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        $data = array_merge(['error' => $message], $extra);
        return new self($data, $status);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(['error' => $message], 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(['error' => $message], 401);
    }

    public static function methodNotAllowed(): self
    {
        return new self(['error' => 'Method not allowed'], 405);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $key => $value) {
            header("{$key}: {$value}");
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
