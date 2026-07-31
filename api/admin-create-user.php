<?php
declare(strict_types=1);


requireAdmin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo(authRoute('users'));
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');
    redirectTo(authRoute('users') . '?action=create');
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
$isAdmin = ($_POST['is_admin'] ?? null) === '1';

$usernameError = validateUsername($username);
if ($usernameError !== null) {
    setFlash('error', $usernameError);
    redirectTo(authRoute('users') . '?action=create');
}

$passwordError = validatePassword($password);
if ($passwordError !== null) {
    setFlash('error', $passwordError);
    redirectTo(authRoute('users') . '?action=create');
}

$passwordConfirmError = validatePasswordConfirmation($password, $passwordConfirm);
if ($passwordConfirmError !== null) {
    setFlash('error', $passwordConfirmError);
    redirectTo(authRoute('users') . '?action=create');
}

try {
    $createdUser = createUserAccount($username, $password, $isAdmin);
} catch (RuntimeException $runtimeException) {
    setFlash('error', $runtimeException->getMessage());
    redirectTo(authRoute('users') . '?action=create');
}

setFlash('success', 'Benutzer ' . ($createdUser['username'] ?? $username) . ' wurde angelegt.');
redirectTo(authRoute('users'));
