<?php
declare(strict_types=1);

/**
 * Anmeldung weiterreichen (SSO) per signiertem Einmal-Token.
 *
 * Aussteller (z.B. HTML-Startseite): kennt fuer jede Ziel-App ein gemeinsames Secret
 * (storage/auth/sso-apps.json: {"<app>": {"url": "...", "secret": "..."}}) und erzeugt
 * mit ssoIssueToken() ein kurzlebiges, HMAC-signiertes Token fuer den angemeldeten Benutzer.
 *
 * Empfaenger (Ziel-App): kennt nur sein eigenes Secret (storage/auth/sso-secret.txt) und
 * seine Kennung (config sso.audience); api/sso.php prueft das Token mit ssoVerifyToken()
 * und meldet den gleichnamigen, aktiven Benutzer der Ziel-App an. Das Passwort wird nie
 * uebertragen; das Token gilt nur fuer eine App, ist kurz gueltig und nur einmal verwendbar.
 */

function ssoConfig(): array
{
    $config = authConfig()['sso'] ?? [];

    return is_array($config) ? $config : [];
}

function ssoBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function ssoBase64UrlDecode(string $data): ?string
{
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);

    return $decoded === false ? null : $decoded;
}

function ssoIssueToken(string $username, string $audience, string $secret): string
{
    $now = time();
    $payload = json_encode([
        'u' => $username,
        'aud' => $audience,
        'iat' => $now,
        'exp' => $now + max(10, (int) (ssoConfig()['token_lifetime'] ?? 60)),
        'nonce' => bin2hex(random_bytes(16)),
    ], JSON_UNESCAPED_UNICODE);

    $encoded = ssoBase64UrlEncode((string) $payload);

    return $encoded . '.' . ssoBase64UrlEncode(hash_hmac('sha256', $encoded, $secret, true));
}

/**
 * Prueft Signatur, Empfaenger, Ablauf und Einmaligkeit. Liefert den Benutzernamen oder null.
 */
function ssoVerifyToken(string $token, string $audience, string $secret): ?string
{
    if ($secret === '' || $audience === '') {
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }

    $signature = ssoBase64UrlDecode($parts[1]);
    if ($signature === null || !hash_equals(hash_hmac('sha256', $parts[0], $secret, true), $signature)) {
        return null;
    }

    $json = ssoBase64UrlDecode($parts[0]);
    $payload = $json === null ? null : json_decode($json, true);
    if (!is_array($payload)) {
        return null;
    }

    $username = $payload['u'] ?? null;
    $nonce = $payload['nonce'] ?? null;
    $expires = $payload['exp'] ?? null;

    if (!is_string($username) || $username === '' || !is_string($nonce) || $nonce === '' || !is_int($expires)) {
        return null;
    }

    if (!hash_equals($audience, (string) ($payload['aud'] ?? '')) || $expires < time()) {
        return null;
    }

    return ssoConsumeNonce($nonce, $expires) ? $username : null;
}

/**
 * Merkt sich die Nonce (einmalige Verwendung). false, wenn sie schon benutzt wurde.
 */
function ssoConsumeNonce(string $nonce, int $expires): bool
{
    $file = (string) (ssoConfig()['nonce_file'] ?? '');
    if ($file === '') {
        return false;
    }

    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        $stored = json_decode((string) stream_get_contents($handle), true);
        $stored = is_array($stored) ? $stored : [];

        $now = time();
        foreach ($stored as $key => $expiresAt) {
            if (!is_int($expiresAt) || $expiresAt < $now) {
                unset($stored[$key]);
            }
        }

        if (isset($stored[$nonce])) {
            return false;
        }

        $stored[$nonce] = $expires;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($stored));
        fflush($handle);

        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Empfaenger: eigenes Secret aus storage/auth/sso-secret.txt ('' = SSO aus). */
function ssoReceiverSecret(): string
{
    $file = (string) (ssoConfig()['secret_file'] ?? '');

    return $file !== '' && is_file($file) ? trim((string) file_get_contents($file)) : '';
}

function ssoAudience(): string
{
    return trim((string) (ssoConfig()['audience'] ?? ''));
}

/**
 * Aussteller: konfigurierte Ziel-Apps aus storage/auth/sso-apps.json
 * ([key => ['url' => ..., 'secret' => ...]]). Nur vollstaendige Eintraege.
 */
function ssoIssuerApps(): array
{
    $file = (string) (ssoConfig()['apps_file'] ?? '');
    $data = $file !== '' && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $apps = [];

    foreach (is_array($data) ? $data : [] as $key => $app) {
        $url = is_array($app) ? rtrim(trim((string) ($app['url'] ?? '')), '/') : '';
        $secret = is_array($app) ? trim((string) ($app['secret'] ?? '')) : '';

        if (is_string($key) && $key !== '' && preg_match('#^https?://[^/\s]+$#i', $url) && $secret !== '') {
            $apps[$key] = ['url' => $url, 'secret' => $secret];
        }
    }

    return $apps;
}
