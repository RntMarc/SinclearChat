<?php

declare(strict_types=1);

namespace SinclearChat\Controllers;

use SinclearChat\Config;
use SinclearChat\Middleware\TokenMiddleware;
use SinclearChat\Models\SseEvent;
use SinclearChat\Response;

final class EventController
{
    public static function stream(): void
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $config = Config::getInstance();
        $heartbeatInterval = max(5, $config->getInt('SSE_HEARTBEAT_INTERVAL', 25));
        $maxRuntime = 300;

        $lastEventId = null;
        $lastEventIdHeader = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
        if ($lastEventIdHeader !== null && ctype_digit((string) $lastEventIdHeader)) {
            $lastEventId = (int) $lastEventIdHeader;
        }

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header('Access-Control-Allow-Origin: ' . (string) $config->get('CORS_ORIGIN', '*'));
        header('Access-Control-Allow-Credentials: true');

        $startTime = time();
        $lastHeartbeat = time();
        $lastMaxId = $lastEventId;

        echo ": connected user_id={$userId}\n\n";
        flush();

        while (true) {
            if (connection_aborted() === 1) {
                break;
            }
            if (time() - $startTime > $maxRuntime) {
                echo "event: timeout\ndata: {\"reason\":\"max_runtime\"}\n\n";
                flush();
                break;
            }

            $events = SseEvent::fetchForUser($userId, $lastMaxId, 200);
            foreach ($events as $event) {
                if ($event['id'] <= $lastMaxId) {
                    continue;
                }
                $lastMaxId = $event['id'];
                self::writeEvent($event);
            }

            if (time() - $lastHeartbeat >= $heartbeatInterval) {
                $lastHeartbeat = time();
                echo ': heartbeat t=' . $lastHeartbeat . "\n\n";
                flush();
            }

            usleep(500_000);
        }
    }

    private static function writeEvent(array $event): void
    {
        echo 'id: ' . $event['id'] . "\n";
        echo 'event: ' . $event['event_type'] . "\n";
        echo 'data: ' . json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        echo "\n";
        flush();
    }
}
