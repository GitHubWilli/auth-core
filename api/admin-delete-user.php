<?php
declare(strict_types=1);


requireAdmin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo(authRoute('users'));
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');
    redirectTo(authRoute('users'));
}

$normalizedUsername = normalizeUsername((string) ($_POST['username'] ?? ''));

if ($normalizedUsername === '') {
    setFlash('error', 'Der zu loeschende Benutzer wurde nicht uebergeben.');
    redirectTo(authRoute('users'));
}

$sessionUser = currentUser();
$isDeletingCurrentUser = $sessionUser !== null && ($sessionUser['username_normalized'] ?? '') === $normalizedUsername;

try {
    $deletedUser = deleteUserAccountRecord($normalizedUsername);
} catch (RuntimeException $runtimeException) {
    setFlash('error', $runtimeException->getMessage());
    redirectTo(authRoute('users'));
}

if ($isDeletingCurrentUser) {
    logoutUser();
    redirectTo(authRoute('after_account_delete'));
}

setFlash('success', 'Benutzer ' . ($deletedUser['username'] ?? $normalizedUsername) . ' wurde geloescht.');
redirectTo(authRoute('users'));
