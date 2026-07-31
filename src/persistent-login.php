<?php
declare(strict_types=1);

function authPersistentLoginConfig(): array
{
    $config = authConfig();
    $persistentLogin = isset($config['persistent_login']) && is_array($config['persistent_login'])
        ? $config['persistent_login']
        : [];
    $cookieName = trim((string) ($persistentLogin['cookie_name'] ?? ''));
    $lifetime = (int) ($persistentLogin['lifetime'] ?? 157680000);

    if ($cookieName === '') {
        $cookieName = (string) $config['session_name'] . '_remember';
    }

    if ($lifetime < 86400) {
        $lifetime = 86400;
    }

    return [
        'enabled' => !array_key_exists('enabled', $persistentLogin) || !empty($persistentLogin['enabled']),
        'cookie_name' => $cookieName,
        'lifetime' => $lifetime,
    ];
}

function persistentLoginEnabled(): bool
{
    return !empty(authPersistentLoginConfig()['enabled']);
}

function persistentLoginIsSecure(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function persistentLoginCookieName(): string
{
    return (string) authPersistentLoginConfig()['cookie_name'];
}

function persistentLoginCookieValue(array $payload): string
{
    return implode(':', [
        (string) $payload['username_normalized'],
        (string) $payload['selector'],
        (string) $payload['validator'],
    ]);
}

function setPersistentLoginCookie(string $value, int $expiresAt)
{
    $cookieName = persistentLoginCookieName();
    $secure = persistentLoginIsSecure();

    if (PHP_VERSION_ID >= 70300) {
        setcookie($cookieName, $value, [
            'expires' => $expiresAt,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie($cookieName, $value, $expiresAt, '/; samesite=Lax', '', $secure, true);
    }

    $_COOKIE[$cookieName] = $value;
}

function clearPersistentLoginCookie()
{
    $cookieName = persistentLoginCookieName();
    $secure = persistentLoginIsSecure();

    if (PHP_VERSION_ID >= 70300) {
        setcookie($cookieName, '', [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie($cookieName, '', time() - 42000, '/; samesite=Lax', '', $secure, true);
    }

    unset($_COOKIE[$cookieName]);
}

function rawPersistentLoginCookieValue(): string
{
    $cookieValue = $_COOKIE[persistentLoginCookieName()] ?? '';

    return is_string($cookieValue) ? $cookieValue : '';
}

function currentPersistentLoginCookie()
{
    $cookieValue = rawPersistentLoginCookieValue();
    if ($cookieValue === '') {
        return null;
    }

    $parts = explode(':', $cookieValue, 3);
    if (count($parts) !== 3) {
        return null;
    }

    $normalizedUsername = normalizeUsername((string) $parts[0]);
    $selector = strtolower(trim((string) $parts[1]));
    $validator = strtolower(trim((string) $parts[2]));

    if ($normalizedUsername === ''
        || !preg_match('/^[a-f0-9]{32}$/', $selector)
        || !preg_match('/^[a-f0-9]{64}$/', $validator)
    ) {
        return null;
    }

    return [
        'username_normalized' => $normalizedUsername,
        'selector' => $selector,
        'validator' => $validator,
    ];
}

function revokePersistentLoginCookieToken($payload)
{
    if (!is_array($payload)) {
        return;
    }

    $normalizedUsername = (string) ($payload['username_normalized'] ?? '');
    $selector = (string) ($payload['selector'] ?? '');

    if ($normalizedUsername === '' || $selector === '') {
        return;
    }

    deleteUserPersistentLoginToken($normalizedUsername, $selector);
}

function establishAuthenticatedSession(array $user)
{
    session_regenerate_id(true);
    $_SESSION['auth_user'] = $user['username_normalized'];
    $_SESSION['auth_login_at'] = time();
}

function createPersistentLoginPayload(array $user): array
{
    $config = authPersistentLoginConfig();
    $now = time();
    $payload = [
        'username_normalized' => (string) $user['username_normalized'],
        'selector' => bin2hex(random_bytes(16)),
        'validator' => bin2hex(random_bytes(32)),
        'expires_at' => $now + (int) $config['lifetime'],
        'created_at' => date(DATE_ATOM, $now),
        'last_used_at' => date(DATE_ATOM, $now),
    ];

    storeUserPersistentLoginToken($payload['username_normalized'], [
        'selector' => $payload['selector'],
        'validator_hash' => hash('sha256', $payload['validator']),
        'expires_at' => $payload['expires_at'],
        'created_at' => $payload['created_at'],
        'last_used_at' => $payload['last_used_at'],
    ]);

    return $payload;
}

function rememberUser(array $user, $existingPayload = null)
{
    if (!persistentLoginEnabled()) {
        clearPersistentLoginCookie();
        return;
    }

    if (is_array($existingPayload)) {
        $sameUser = (string) ($existingPayload['username_normalized'] ?? '') === (string) $user['username_normalized'];
        $storedToken = $sameUser
            ? findUserPersistentLoginToken((string) $user['username_normalized'], (string) ($existingPayload['selector'] ?? ''))
            : null;

        if ($sameUser
            && $storedToken !== null
            && hash_equals((string) $storedToken['validator_hash'], hash('sha256', (string) ($existingPayload['validator'] ?? '')))
        ) {
            $storedToken['expires_at'] = time() + (int) authPersistentLoginConfig()['lifetime'];
            $storedToken['last_used_at'] = date(DATE_ATOM);
            storeUserPersistentLoginToken((string) $user['username_normalized'], $storedToken);
            setPersistentLoginCookie(persistentLoginCookieValue($existingPayload), (int) $storedToken['expires_at']);
            return;
        }

        revokePersistentLoginCookieToken($existingPayload);
    }

    $payload = createPersistentLoginPayload($user);
    setPersistentLoginCookie(persistentLoginCookieValue($payload), (int) $payload['expires_at']);
}

function restorePersistentLogin()
{
    if (!persistentLoginEnabled()) {
        clearPersistentLoginCookie();
        return;
    }

    $rawCookie = rawPersistentLoginCookieValue();
    $payload = currentPersistentLoginCookie();

    if ($payload === null) {
        if ($rawCookie !== '') {
            clearPersistentLoginCookie();
        }
        return;
    }

    $user = findUserByNormalizedUsername((string) $payload['username_normalized']);
    if ($user === null || empty($user['is_active'])) {
        revokePersistentLoginCookieToken($payload);
        clearPersistentLoginCookie();
        return;
    }

    $storedToken = findUserPersistentLoginToken((string) $payload['username_normalized'], (string) $payload['selector']);
    if ($storedToken === null
        || !hash_equals((string) $storedToken['validator_hash'], hash('sha256', (string) $payload['validator']))
    ) {
        revokePersistentLoginCookieToken($payload);
        clearPersistentLoginCookie();
        return;
    }

    establishAuthenticatedSession($user);
    revokePersistentLoginCookieToken($payload);
    rememberUser($user);
}

function maintainPersistentLogin(array $user)
{
    $rawCookie = rawPersistentLoginCookieValue();
    $payload = currentPersistentLoginCookie();

    if ($payload === null) {
        if ($rawCookie !== '') {
            clearPersistentLoginCookie();
        }

        rememberUser($user);
        return;
    }

    rememberUser($user, $payload);
}
