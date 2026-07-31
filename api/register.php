<?php
declare(strict_types=1);

requireGuest();

if (!selfRegistrationAllowed()) {
    setFlash('error', 'Die Selbstregistrierung ist derzeit deaktiviert.');
    redirectTo(authRoute('login'));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo(authRoute('register'));
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');
    redirectTo(authRoute('register'));
}

$username = trim((string) ($_POST['username'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

$usernameError = validateUsername($username);
if ($usernameError !== null) {
    setFlash('error', $usernameError);
    redirectTo(authRoute('register'));
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    setFlash('error', 'Bitte eine gültige E-Mail-Adresse eingeben.');
    redirectTo(authRoute('register'));
}

$passwordError = validatePassword($password);
if ($passwordError !== null) {
    setFlash('error', $passwordError);
    redirectTo(authRoute('register'));
}

$passwordConfirmError = validatePasswordConfirmation($password, $passwordConfirm);
if ($passwordConfirmError !== null) {
    setFlash('error', $passwordConfirmError);
    redirectTo(authRoute('register'));
}

try {
    $createdUser = createUserAccount($username, $password, false, $email);
} catch (RuntimeException $runtimeException) {
    setFlash('error', $runtimeException->getMessage());
    redirectTo(authRoute('register'));
}

if (empty($createdUser['is_active'])) {
    setFlash('success', 'Dein Konto wurde erstellt und wartet auf die Freischaltung durch einen Administrator.');
    redirectTo(authRoute('login'));
}

loginUser($username, $password);
setFlash('success', 'Dein Konto wurde erstellt.');
redirectTo(authRoute('after_register'));
