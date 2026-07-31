<?php
declare(strict_types=1);

function passwordResetTokenLifetime(): int
{
    return (int) (authConfig()['password_reset']['token_lifetime'] ?? 2400);
}

/**
 * Erzeugt einen neuen Passwort-Reset-Token fuer den Benutzer und gibt den
 * KLARTEXT-Token zurueck (nur fuer den Reset-Link, wird selbst nicht gespeichert -
 * nur sein Hash landet im Nutzerspeicher).
 */
function createPasswordResetToken(string $normalizedUsername): string
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = time() + passwordResetTokenLifetime();

    setUserPasswordResetToken($normalizedUsername, $tokenHash, $expiresAt);
    authFireHook('authOnPasswordResetTokenCreated', $normalizedUsername, $token, $expiresAt);

    return $token;
}

/**
 * Prueft einen Klartext-Token gegen den gespeicherten Hash + Ablaufzeit.
 * Gibt den zugehoerigen Nutzer zurueck oder null bei ungueltigem/abgelaufenem Token.
 */
function verifyPasswordResetToken(string $normalizedUsername, string $token)
{
    $user = findUserByNormalizedUsername($normalizedUsername);
    if ($user === null || empty($user['is_active'])) {
        return null;
    }

    $tokenHash = $user['password_reset_token_hash'] ?? null;
    $expiresAt = $user['password_reset_expires_at'] ?? null;

    if (!is_string($tokenHash) || $tokenHash === '' || !is_int($expiresAt) || $expiresAt < time()) {
        return null;
    }

    if (!hash_equals($tokenHash, hash('sha256', $token))) {
        return null;
    }

    return $user;
}

function consumePasswordResetToken(string $normalizedUsername, string $token, string $newPassword): bool
{
    $user = verifyPasswordResetToken($normalizedUsername, $token);
    if ($user === null) {
        return false;
    }

    updateUserPassword($normalizedUsername, $newPassword);

    return true;
}
