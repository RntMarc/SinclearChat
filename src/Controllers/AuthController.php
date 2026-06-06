<?php

declare(strict_types=1);

namespace SinclearChat\Controllers;

use SinclearChat\Config;
use SinclearChat\Http\NextJsClient;
use SinclearChat\Models\AuthCode;
use SinclearChat\Models\RefreshToken;
use SinclearChat\Models\UserProfile;
use SinclearChat\Middleware\TokenMiddleware;
use SinclearChat\Response;

final class AuthController
{
    public static function token(): Response
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            return Response::error('Invalid JSON body');
        }

        $grantType = (string) ($input['grant_type'] ?? '');
        return match ($grantType) {
            'authorization_code' => self::exchangeCode($input),
            'refresh_token' => self::refresh($input),
            default => Response::error('Unsupported grant_type'),
        };
    }

    private static function exchangeCode(array $input): Response
    {
        $code = trim((string) ($input['code'] ?? ''));
        $codeVerifier = (string) ($input['code_verifier'] ?? '');

        if ($code === '' || $codeVerifier === '') {
            return Response::error('Missing required fields: code, code_verifier');
        }

        $authCode = AuthCode::findValid($code);
        if ($authCode === null) {
            return Response::error('Invalid or expired code', 400);
        }

        $expectedChallenge = (string) $authCode['code_challenge'];
        $method = (string) $authCode['code_challenge_method'];

        if ($method !== 'S256') {
            AuthCode::markUsed($code);
            return Response::error('Unsupported code_challenge_method', 400);
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (!hash_equals($expectedChallenge, $computed)) {
            AuthCode::markUsed($code);
            return Response::error('Invalid code_verifier', 400);
        }

        AuthCode::markUsed($code);

        $userId = (string) $authCode['user_id'];

        try {
            $tokens = self::issueTokenPair($userId, null);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to issue tokens: ' . $e->getMessage());
            return Response::error('Failed to issue tokens: ' . $e->getMessage(), 500);
        }

        return Response::success($tokens);
    }

    private static function refresh(array $input): Response
    {
        $plaintext = (string) ($input['refresh_token'] ?? '');
        if ($plaintext === '') {
            return Response::error('Missing refresh_token');
        }

        $existing = RefreshToken::findValid($plaintext);
        if ($existing === null) {
            return Response::error('Invalid or expired refresh_token', 401);
        }

        if ($existing['used_at'] !== null) {
            RefreshToken::revokeFamily((string) $existing['family_id'], 'reuse_detected');
            error_log('[SinclearChat] Refresh token reuse detected for user: ' . $existing['user_id']);
            return Response::error('Refresh token reuse detected; family revoked', 401);
        }

        $userId = (string) $existing['user_id'];
        $familyId = (string) $existing['family_id'];
        $parentId = (string) $existing['id'];

        try {
            $tokens = self::issueTokenPair($userId, $familyId, $parentId);
            RefreshToken::markUsed($parentId);
        } catch (\Throwable $e) {
            error_log('[SinclearChat] Failed to rotate refresh token: ' . $e->getMessage());
            return Response::error('Failed to rotate tokens: ' . $e->getMessage(), 500);
        }

        return Response::success($tokens);
    }

    public static function logout(): Response
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $input = json_decode($rawBody, true);
        $plaintext = is_array($input) ? (string) ($input['refresh_token'] ?? '') : '';

        if ($plaintext === '') {
            return Response::error('Missing refresh_token');
        }

        $existing = RefreshToken::findValid($plaintext);
        if ($existing !== null) {
            RefreshToken::revokeFamily((string) $existing['family_id'], 'logout');
        }

        return new Response([], 204);
    }

    public static function logoutAll(): Response
    {
        TokenMiddleware::requireBearer();
        $userId = TokenMiddleware::getUserId();

        RefreshToken::revokeAllFamiliesForUser($userId, 'security');
        \SinclearChat\Models\Device::deleteAllForUser($userId);
        UserProfile::incrementTokenVersion($userId);

        \SinclearChat\Models\SseEvent::emit($userId, 'force_logout', ['user_id' => $userId]);

        return new Response([], 204);
    }

    public static function me(): Response
    {
        $claims = TokenMiddleware::requireBearer();
        $userId = $claims['sub'];

        $profile = UserProfile::findById($userId);
        if ($profile === null) {
            $profile = self::fetchProfileFromNextJs($userId);
        }

        if ($profile === null) {
            return Response::notFound('User profile not found');
        }

        return Response::success([
            'id' => $profile['id'],
            'display_name' => $profile['display_name'],
            'avatar' => $profile['avatar'],
            'status_message' => $profile['status_message'],
            'roles' => self::deriveRoles($userId),
            'created_at' => $profile['created_at'],
            'token' => [
                'sub' => $claims['sub'],
                'iss' => $claims['iss'],
                'aud' => $claims['aud'],
                'iat' => $claims['iat'],
                'exp' => $claims['exp'],
                'jti' => $claims['jti'],
                'token_version' => $claims['token_version'],
            ],
        ]);
    }

    private static function issueTokenPair(string $userId, ?string $familyId, ?string $parentId = null): array
    {
        if ($familyId === null) {
            $familyId = RefreshToken::createFamily($userId);
        }

        $newRefresh = RefreshToken::issue($userId, $familyId, $parentId);

        $accessToken = NextJsClient::mintAccessToken($userId);
        if (isset($accessToken['error'])) {
            throw new \RuntimeException($accessToken['error']);
        }

        $config = Config::getInstance();
        $expiresIn = $config->getInt('JWT_API_ACCESS_TTL', 900);

        return [
            'access_token' => $accessToken['token'],
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'refresh_token' => $newRefresh['plaintext'],
            'family_id' => $familyId,
        ];
    }

    private static function fetchProfileFromNextJs(string $userId): ?array
    {
        $profile = NextJsClient::fetchProfile($userId);
        if ($profile === null) {
            return null;
        }

        UserProfile::upsert(
            $userId,
            $profile['display_name'] ?? null,
            $profile['avatar'] ?? null,
            $profile['status_message'] ?? null,
        );

        return UserProfile::findById($userId);
    }

    private static function deriveRoles(string $userId): array
    {
        $roles = ['user'];
        return $roles;
    }
}
