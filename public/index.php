<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use SinclearChat\Auth;
use SinclearChat\Config;
use SinclearChat\Response;
use SinclearChat\Router;
use SinclearChat\Controllers\MessageController;
use SinclearChat\Controllers\RoomController;
use SinclearChat\Controllers\AuthController;
use SinclearChat\Controllers\MessageV2Controller;
use SinclearChat\Controllers\ChatController;
use SinclearChat\Controllers\DirectController;
use SinclearChat\Controllers\GroupController;
use SinclearChat\Controllers\UserController;
use SinclearChat\Controllers\PresenceController;
use SinclearChat\Controllers\TypingController;
use SinclearChat\Controllers\DeviceController;
use SinclearChat\Controllers\UploadController;
use SinclearChat\Controllers\EventController;

header_remove('X-Powered-By');

$config = Config::getInstance();

$corsOrigin = (string) $config->get('CORS_ORIGIN', '*');
header("Access-Control-Allow-Origin: {$corsOrigin}");
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Hub-Timestamp, X-Hub-Signature, Authorization, Last-Event-ID');
header('Access-Control-Max-Age: 86400');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($uri) ? rtrim($uri, '/') : '/';
if ($uri === '') {
    $uri = '/';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$publicPaths = [
    '/api/health',
    '/api/v2/auth/token',
    '/api/v2/auth/refresh',
];

if (in_array($uri, $publicPaths, true)) {
    AuthenticatePublic($uri, $method);
} elseif (str_starts_with($uri, '/api/v2/')) {
    // v2 JWT-Bearer; nur /events bekommt Sonderbehandlung (kein JSON-Body, stream)
    if ($uri === '/api/v2/events') {
        // SSE: requireBearer wird in Controller aufgerufen, kein Body-Parsing hier
    } else {
        // nothing — bearer wird im Controller validiert
    }
} else {
    // v1: HMAC
    $body = file_get_contents('php://input') ?: '';
    $headers = getRequestHeaders();

    if (!Auth::verify($method, $uri, $body, $headers)) {
        Response::unauthorized('Invalid or missing HMAC signature')->send();
        exit;
    }
}

$router = new Router();

$router->get('/api/health', static function () {
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
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
});

// ========== v1 (HMAC, unverändert) ==========
$router->post('/api/messages', static fn() => MessageController::push());
$router->get('/api/messages', static fn() => MessageController::pull());
$router->get('/api/rooms', static fn() => RoomController::list());
$router->get('/api/rooms/{id}', static fn(array $p) => RoomController::show($p));
$router->get('/api/rooms/{id}/members', static fn(array $p) => RoomController::members($p));

// ========== Internal (HMAC mit AUTH_INTERNAL_SECRET) ==========
$router->post('/api/internal/issue-code', static fn() => \SinclearChat\Controllers\Internal\InternalAuthController::issueCode());

// ========== v2 (JWT Bearer) ==========
// Auth
$router->post('/api/v2/auth/token', static fn() => AuthController::token());
$router->post('/api/v2/auth/refresh', static fn() => AuthController::refresh());
$router->post('/api/v2/auth/logout', static fn() => AuthController::logout());
$router->post('/api/v2/auth/logout-all', static fn() => AuthController::logoutAll());
$router->get('/api/v2/auth/me', static fn() => AuthController::me());

// SSE
$router->get('/api/v2/events', static function () {
    EventController::stream();
    return null;
});

// Chats
$router->get('/api/v2/chats', static fn() => ChatController::list());
$router->get('/api/v2/chats/unread', static fn() => MessageV2Controller::unread());
$router->get('/api/v2/chats/{chatId}', static fn(array $p) => ChatController::show($p));
$router->get('/api/v2/chats/{chatId}/messages', static fn(array $p) => ChatController::messages($p));
$router->get('/api/v2/chats/{chatId}/members', static fn(array $p) => ChatController::members($p));
$router->post('/api/v2/chats/{chatId}/read', static fn(array $p) => MessageV2Controller::read());
$router->delete('/api/v2/chats/{chatId}', static fn(array $p) => ChatController::delete($p));

// Direct
$router->get('/api/v2/direct', static fn() => DirectController::list());
$router->post('/api/v2/direct', static fn() => DirectController::openOrCreate());
$router->get('/api/v2/direct/{chatId}', static fn(array $p) => DirectController::show($p));

// Groups
$router->post('/api/v2/groups', static fn() => GroupController::create());
$router->get('/api/v2/groups/{id}', static fn(array $p) => GroupController::show($p));
$router->patch('/api/v2/groups/{id}', static fn(array $p) => GroupController::update($p));
$router->delete('/api/v2/groups/{id}', static fn(array $p) => GroupController::delete($p));
$router->post('/api/v2/groups/{id}/leave', static fn(array $p) => GroupController::leave($p));
$router->post('/api/v2/groups/{id}/members', static fn(array $p) => GroupController::addMember($p));
$router->delete('/api/v2/groups/{id}/members/{userId}', static fn(array $p) => GroupController::removeMember($p));

// Messages
$router->post('/api/v2/messages', static fn() => MessageV2Controller::push());
$router->get('/api/v2/messages', static fn() => MessageV2Controller::pull());
$router->post('/api/v2/messages/read', static fn() => MessageV2Controller::read());

// Uploads
$router->post('/api/v2/uploads', static fn() => UploadController::upload());
$router->get('/api/v2/uploads/{id}', static fn(array $p) => UploadController::download($p));

// Users
$router->get('/api/v2/users/me', static fn() => UserController::me());
$router->patch('/api/v2/users/me', static fn() => UserController::updateMe());
$router->get('/api/v2/users/{id}', static fn(array $p) => UserController::show($p));

// Presence
$router->post('/api/v2/presence', static fn() => PresenceController::set());
$router->get('/api/v2/presence/{userId}', static fn(array $p) => PresenceController::get($p));

// Typing
$router->post('/api/v2/typing', static fn() => TypingController::set());

// Devices
$router->get('/api/v2/devices', static fn() => DeviceController::list());
$router->post('/api/v2/devices', static fn() => DeviceController::register());
$router->delete('/api/v2/devices/{id}', static fn(array $p) => DeviceController::delete($p));

try {
    $response = $router->dispatch($method, $_SERVER['REQUEST_URI'] ?? '/');
    if ($response !== null) {
        $response->send();
    }
} catch (\Throwable $e) {
    error_log(sprintf(
        "[SinclearChat] Uncaught Exception: %s in %s:%d\nStack trace:\n%s",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));
    if (!headers_sent()) {
        Response::error('Internal server error: ' . $e->getMessage(), 500)->send();
    }
}

/**
 * Public path validation hook (currently no-op since both /api/v2/auth/token
 * and /api/v2/auth/refresh are PKCE/grace-token flows with no auth).
 */
function AuthenticatePublic(string $uri, string $method): void
{
    // no auth required for these paths
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
