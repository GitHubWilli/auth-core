<?php
declare(strict_types=1);

requireAdmin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo(authRoute('users'));
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');
    redirectTo(authRoute('users') . '?edit=' . rawurlencode(normalizeUsername((string) ($_POST['current_username'] ?? ''))));
}

$currentUsername = normalizeUsername((string) ($_POST['current_username'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$newPassword = (string) ($_POST['new_password'] ?? '');
$newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');
$isAdmin = ($_POST['is_admin'] ?? null) === '1';

$usernameError = validateUsername($username);
if ($usernameError !== null) {
    setFlash('error', $usernameError);
    redirectTo(authRoute('users') . '?edit=' . rawurlencode($currentUsername));
}

if ($currentUsername === '') {
    setFlash('error', 'Der zu bearbeitende Benutzer wurde nicht uebergeben.');
    redirectTo(authRoute('users'));
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    setFlash('error', 'Bitte eine gültige E-Mail-Adresse eingeben.');
    redirectTo(authRoute('users') . '?edit=' . rawurlencode($currentUsername));
}

$passwordError = null;
$passwordConfirmError = null;
if ($newPassword !== '' || $newPasswordConfirm !== '') {
    $passwordError = validatePassword($newPassword);
    $passwordConfirmError = validatePasswordConfirmation($newPassword, $newPasswordConfirm);
}

if ($passwordError !== null) {
    setFlash('error', $passwordError);
    redirectTo(authRoute('users') . '?edit=' . rawurlencode($currentUsername));
}

if ($passwordConfirmError !== null) {
    setFlash('error', $passwordConfirmError);
    redirectTo(authRoute('users') . '?edit=' . rawurlencode($currentUsername));
}

$sessionUser = currentUser();
$editRedirectUsername = $currentUsername;

try {
    $updatedUser = updateUserAccountRecord($currentUsername, $username, $isAdmin);
    $editRedirectUsername = (string) $updatedUser['username_normalized'];
    $updatedUser = updateUserEmail($editRedirectUsername, $email !== '' ? $email : null);

    if ($newPassword !== '') {
        updateUserPassword($editRedirectUsername, $newPassword);
    }
} catch (RuntimeException $runtimeException) {
    setFlash('error', $runtimeException->getMessage());
    redirectTo(authRoute('users') . '?edit=' . rawurlencode($editRedirectUsername));
}

if ($sessionUser !== null && ($sessionUser['username_normalized'] ?? '') === $currentUsername) {
    $_SESSION['auth_user'] = $updatedUser['username_normalized'];

    if (empty($updatedUser['is_admin'])) {
        setFlash('success', 'Dein Konto wurde aktualisiert.');
        redirectTo(authRoute('home'));
    }
}

setFlash('success', 'Benutzer ' . ($updatedUser['username'] ?? $username) . ' wurde aktualisiert.');
redirectTo(authRoute('users'));
