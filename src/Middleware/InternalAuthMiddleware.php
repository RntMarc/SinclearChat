<?php

declare(strict_types=1);

namespace SinclearChat\Middleware;

use SinclearChat\Auth;
use SinclearChat\Config;
use SinclearChat\Response;

final class InternalAuthMiddleware
{
    public static function verify(): void
    {
        $config = Config::getInstance();
        $secret = $config->get('AUTH_INTERNAL_SECRET');

        if (!is_string($secret) || $secret === '') {
            error_log('[SinclearChat] AUTH_INTERNAL_SECRET is not configured');
            Response::unauthorized('Internal auth not configured')->send();
            exit;
        }

        if (!Auth::verifyWithSecret(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            file_get_contents('php://input') ?: '',
            self::getRequestHeaders(),
            $secret,
        )) {
            Response::unauthorized('Invalid or missing internal HMAC signature')->send();
            exit;
        }
    }

    private static function getRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }
}
