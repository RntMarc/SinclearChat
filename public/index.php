<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use SinclearChat\Config;
use SinclearChat\Auth;
use SinclearChat\Response;
use SinclearChat\Router;
use SinclearChat\Controllers\MessageController;
use SinclearChat\Controllers\RoomController;

header_remove('X-Powered-By');

$config = Config::getInstance();

$corsOrigin = (string) $config->get('CORS_ORIGIN', '*');
header("Access-Control-Allow-Origin: {$corsOrigin}");
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Hub-Timestamp, X-Hub-Signature');
header('Access-Control-Max-Age: 86400');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$publicPaths = ['/api/health'];

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = is_string($uri) ? rtrim($uri, '/') : '/';
if ($uri === '') {
    $uri = '/';
}

if (!in_array($uri, $publicPaths, true)) {
    $body = file_get_contents('php://input');
    $body = $body === false ? '' : $body;
    $headers = getRequestHeaders();

    if (!Auth::verify($_SERVER['REQUEST_METHOD'], $uri, $body, $headers)) {
        Response::unauthorized('Invalid or missing HMAC signature')->send();
        exit;
    }
}

$router = new Router();

$router->get('/api/health', function () {
    $dbStatus = 'unknown';
    try {
        \SinclearChat\Database::getConnection();
        $dbStatus = 'connected';
    } catch (\Throwable $e) {
        $dbStatus = 'error: ' . $e->getMessage();
    }
    return Response::success([
        'status' => 'ok',
        'database' => $dbStatus,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
    ]);
});

$router->post('/api/messages', function () {
    return MessageController::push();
});

$router->get('/api/messages', function () {
    return MessageController::pull();
});

$router->get('/api/rooms', function () {
    return RoomController::list();
});

$router->get('/api/rooms/{id}', function (array $params) {
    return RoomController::show($params);
});

$router->get('/api/rooms/{id}/members', function (array $params) {
    return RoomController::members($params);
});

try {
    $response = $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    $response->send();
} catch (\Throwable $e) {
    error_log(sprintf(
        "[SinclearChat] Uncaught Exception: %s in %s:%d\nStack trace:\n%s",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));
    Response::error('Internal server error: ' . $e->getMessage(), 500)->send();
}

function getRequestHeaders(): array
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
    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
    }
    return $headers;
}
