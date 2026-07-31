<?php
declare(strict_types=1);

requireGuest();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo(authRoute('login'));
}

$normalizedUsername = normalizeUsername((string) ($_POST['username'] ?? ''));
$token = (string) ($_POST['token'] ?? '');

$redirectBack = authRoute('reset_password') . '?u=' . rawurlencode($normalizedUsername) . '&token=' . rawurlencode($token);

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');
    redirectTo($redirectBack);
}

if ($normalizedUsername === '' || $token === '') {
    setFlash('error', 'Der Link zum Zuruecksetzen des Passworts ist ungueltig.');
    redirectTo(authRoute('login'));
}

if (verifyPasswordResetToken($normalizedUsername, $token) === null) {
    setFlash('error', 'Der Link zum Zuruecksetzen des Passworts ist ungueltig oder abgelaufen. Bitte fordere einen neuen an.');
    redirectTo(authRoute('forgot_password'));
}

$newPassword = (string) ($_POST['new_password'] ?? '');
$newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

$passwordError = validatePassword($newPassword);
if ($passwordError !== null) {
    setFlash('error', $passwordError);
    redirectTo($redirectBack);
}

$passwordConfirmError = validatePasswordConfirmation($newPassword, $newPasswordConfirm);
if ($passwordConfirmError !== null) {
    setFlash('error', $passwordConfirmError);
    redirectTo($redirectBack);
}

if (!consumePasswordResetToken($normalizedUsername, $token, $newPassword)) {
    setFlash('error', 'Der Link zum Zuruecksetzen des Passworts ist ungueltig oder abgelaufen. Bitte fordere einen neuen an.');
    redirectTo(authRoute('forgot_password'));
}

setFlash('success', 'Dein Passwort wurde geaendert. Du kannst dich jetzt anmelden.');
redirectTo(authRoute('login'));
