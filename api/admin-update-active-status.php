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
$isActive = ($_POST['is_active'] ?? null) === '1';

if ($normalizedUsername === '') {
    setFlash('error', 'Der zu aendernde Benutzer wurde nicht uebergeben.');
    redirectTo(authRoute('users'));
}

$sessionUser = currentUser();
if (!$isActive && $sessionUser !== null && ($sessionUser['username_normalized'] ?? '') === $normalizedUsername) {
    setFlash('error', 'Du kannst dein eigenes Konto nicht sperren.');
    redirectTo(authRoute('users'));
}

try {
    $updatedUser = updateUserActiveStatus($normalizedUsername, $isActive);
} catch (RuntimeException $runtimeException) {
    setFlash('error', $runtimeException->getMessage());
    redirectTo(authRoute('users'));
}

setFlash('success', 'Benutzer ' . ($updatedUser['username'] ?? $normalizedUsername) . ' wurde ' . ($isActive ? 'freigeschaltet' : 'gesperrt') . '.');
redirectTo(authRoute('users'));
