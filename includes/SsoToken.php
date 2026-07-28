<?php

/**
 * Shared HMAC SSO token helper (members area ↔ product apps).
 * Keep in sync with savvy-cfo-portfolio/api/src/SsoToken.php
 */
final class SsoToken
{
    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $encoded)
    {
        $remainder = strlen($encoded) % 4;
        if ($remainder) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }

    public static function mint(array $claims, string $secret): string
    {
        $payload = self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $sig = self::base64UrlEncode(hash_hmac('sha256', $payload, $secret, true));
        return $payload . '.' . $sig;
    }

    public static function verify(string $token, string $secret, $expectedAud = null): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$payloadB64, $sigB64] = $parts;
        $expectedSig = self::base64UrlEncode(hash_hmac('sha256', $payloadB64, $secret, true));
        if (!hash_equals($expectedSig, $sigB64)) {
            return null;
        }
        $json = self::base64UrlDecode($payloadB64);
        if ($json === false) {
            return null;
        }
        $claims = json_decode($json, true);
        if (!is_array($claims)) {
            return null;
        }
        foreach (['email', 'aud', 'exp', 'jti', 'iat'] as $key) {
            if (!array_key_exists($key, $claims)) {
                return null;
            }
        }
        if ((int) $claims['exp'] < time()) {
            return null;
        }
        if ($expectedAud !== null && (string) $claims['aud'] !== $expectedAud) {
            return null;
        }
        $email = strtolower(trim((string) $claims['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return [
            'email' => $email,
            'aud' => (string) $claims['aud'],
            'exp' => (int) $claims['exp'],
            'jti' => (string) $claims['jti'],
            'iat' => (int) $claims['iat'],
        ];
    }
}
