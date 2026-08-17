<?php
declare(strict_types=1);


requireOwnAccountDeletionAllowed();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo(authRoute('profile'));
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');
    redirectTo(authRoute('profile'));
}

$user = currentUser();
$currentPassword = (string) ($_POST['current_password'] ?? '');
$confirmUsername = trim((string) ($_POST['confirm_username'] ?? ''));

if ($user === null) {
    redirectTo(authRoute('login'));
}

if (!password_verify($currentPassword, (string) $user['password_hash'])) {
    setFlash('error', 'Das Passwort zur Bestätigung ist nicht korrekt.');
    redirectTo(authRoute('profile'));
}

if (normalizeUsername($confirmUsername) !== (string) $user['username_normalized']) {
    setFlash('error', 'Der bestätigte Benutzername passt nicht.');
    redirectTo(authRoute('profile'));
}

try {
    deleteUserAccountRecord((string) $user['username_normalized']);
} catch (RuntimeException $runtimeException) {
    setFlash('error', $runtimeException->getMessage());
    redirectTo(authRoute('profile'));
}

logoutUser();
redirectTo(authRoute('after_account_delete'));
