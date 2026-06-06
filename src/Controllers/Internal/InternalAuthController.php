<?php

declare(strict_types=1);

namespace SinclearChat\Controllers\Internal;

use SinclearChat\Middleware\InternalAuthMiddleware;
use SinclearChat\Models\AuthCode;
use SinclearChat\Models\UserProfile;
use SinclearChat\Response;

final class InternalAuthController
{
    public static function issueCode(): Response
    {
        InternalAuthMiddleware::verify();

        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $userId = trim((string) ($input['user_id'] ?? ''));
        $codeChallenge = trim((string) ($input['code_challenge'] ?? ''));
        $codeChallengeMethod = (string) ($input['code_challenge_method'] ?? 'S256');
        $redirectUri = isset($input['redirect_uri']) ? (string) $input['redirect_uri'] : null;

        if ($userId === '' || $codeChallenge === '') {
            return Response::error('Missing user_id or code_challenge');
        }
        if ($codeChallengeMethod !== 'S256') {
            return Response::error('Only S256 supported');
        }
        if (strlen($codeChallenge) < 43 || strlen($codeChallenge) > 128) {
            return Response::error('Invalid code_challenge length');
        }

        try {
            $code = AuthCode::create($userId, $codeChallenge, $codeChallengeMethod, $redirectUri);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to create auth code: ' . $e->getMessage());
            return Response::error('Failed to create auth code: ' . $e->getMessage(), 500);
        }

        return Response::created(['code' => $code]);
    }
}
