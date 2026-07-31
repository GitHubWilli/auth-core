<?php
declare(strict_types=1);


requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo(authRoute('profile'));
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');
    redirectTo(authRoute('profile'));
}

$user = currentUser();
$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

if ($user === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
    setFlash('error', 'Das aktuelle Passwort ist nicht korrekt.');
    redirectTo(authRoute('profile'));
}

$passwordError = validatePassword($newPassword);
if ($passwordError !== null) {
    setFlash('error', $passwordError);
    redirectTo(authRoute('profile'));
}

$passwordConfirmError = validatePasswordConfirmation($newPassword, $newPasswordConfirm);
if ($passwordConfirmError !== null) {
    setFlash('error', $passwordConfirmError);
    redirectTo(authRoute('profile'));
}

if ($currentPassword === $newPassword) {
    setFlash('error', 'Bitte wähle ein neues Passwort.');
    redirectTo(authRoute('profile'));
}

updateUserPassword((string) $user['username_normalized'], $newPassword);
setFlash('success', 'Dein Passwort wurde geändert.');
redirectTo(authRoute('profile'));
