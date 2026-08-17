<?php
declare(strict_types=1);

function redirectTo(string $path)
{
    header('Location: ' . $path);
    exit;
}

function currentRequestPath(): string
{
    return safeRedirectPath($_SERVER['REQUEST_URI'] ?? authRoute('home'), authRoute('home'));
}

function wantsJsonResponse(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return strpos($accept, 'application/json') !== false || $requestedWith === 'xmlhttprequest';
}

function respondJson(array $payload, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function currentUser()
{
    $normalizedUsername = $_SESSION['auth_user'] ?? null;

    if (!is_string($normalizedUsername) || $normalizedUsername === '') {
        return null;
    }

    $user = findUserByNormalizedUsername($normalizedUsername);
    if ($user === null || empty($user['is_active'])) {
        unset($_SESSION['auth_user'], $_SESSION['auth_login_at']);
        return null;
    }

    return $user;
}

function isLoggedIn(): bool
{
    return currentUser() !== null;
}

function currentUserIsAdmin(): bool
{
    $user = currentUser();

    return $user !== null && !empty($user['is_admin']);
}

function loginUser(string $username, string $password): bool
{
    $user = findUser($username);

    if ($user === null || empty($user['is_active'])) {
        return false;
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        return false;
    }

    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        updateUserPassword((string) $user['username_normalized'], $password);
        $user = findUserByNormalizedUsername((string) $user['username_normalized']) ?? $user;
    }

    establishAuthenticatedSession($user);
    rememberUser($user, currentPersistentLoginCookie());

    return true;
}

function logoutUser()
{
    revokePersistentLoginCookieToken(currentPersistentLoginCookie());
    clearPersistentLoginCookie();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            !empty($params['secure']),
            !empty($params['httponly'])
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function requireAuth()
{
    if (currentUser() !== null) {
        return;
    }

    setFlash('error', 'Bitte melde dich an, um diese Seite zu verwenden.');
    redirectTo(authRoute('login') . '?redirect=' . rawurlencode(currentRequestPath()));
}

function requireAdmin()
{
    requireAuth();

    if (currentUserIsAdmin()) {
        return;
    }

    setFlash('error', 'Nur Administratoren duerfen die Benutzerverwaltung aufrufen.');
    redirectTo(authRoute('home'));
}

function currentUserCanChangeOwnPassword(): bool
{
    if (currentUserIsAdmin()) {
        return true;
    }

    return nonAdminPasswordChangeAllowed();
}

function currentUserCanDeleteOwnAccount(): bool
{
    if (currentUserIsAdmin()) {
        return true;
    }

    return nonAdminAccountDeletionAllowed();
}

function requireOwnPasswordChangeAllowed()
{
    requireAuth();

    if (currentUserCanChangeOwnPassword()) {
        return;
    }

    setFlash('error', 'Nur Administratoren duerfen ihr Passwort selbst aendern.');
    redirectTo(authRoute('profile'));
}

function requireOwnAccountDeletionAllowed()
{
    requireAuth();

    if (currentUserCanDeleteOwnAccount()) {
        return;
    }

    setFlash('error', 'Nur Administratoren duerfen ihr Konto selbst loeschen.');
    redirectTo(authRoute('profile'));
}

function requireGuest()
{
    if (currentUser() !== null) {
        redirectTo(authRoute('home'));
    }
}

function requireApiAuth()
{
    if (currentUser() !== null) {
        return;
    }

    respondJson([
        'success' => false,
        'error' => 'Nicht angemeldet.',
        'redirect' => authRoute('login') . '?redirect=' . rawurlencode(currentRequestPath()),
    ], 401);
}

function requireApiAdmin()
{
    requireApiAuth();

    if (currentUserIsAdmin()) {
        return;
    }

    respondJson([
        'success' => false,
        'error' => 'Nur Administratoren duerfen diese Aktion ausfuehren.',
    ], 403);
}

function requireTextAuth()
{
    if (currentUser() !== null) {
        return;
    }

    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nicht angemeldet.';
    exit;
}
