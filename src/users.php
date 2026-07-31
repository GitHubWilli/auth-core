<?php
declare(strict_types=1);

function defaultUsersStore(): array
{
    return ['users' => []];
}

function canonicalizeUserRecord(array $user, int $index, bool $hasAdmin): array
{
    $username = trim((string) ($user['username'] ?? ''));
    $normalizedUsername = normalizeUsername((string) ($user['username_normalized'] ?? $username));
    $createdAt = (string) ($user['created_at'] ?? date(DATE_ATOM));
    $updatedAt = (string) ($user['updated_at'] ?? $createdAt);
    $email = trim((string) ($user['email'] ?? ''));

    return [
        'username' => $username,
        'username_normalized' => $normalizedUsername,
        'email' => $email !== '' ? $email : null,
        'password_hash' => (string) ($user['password_hash'] ?? ''),
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
        'is_active' => !array_key_exists('is_active', $user) || (bool) $user['is_active'],
        'is_admin' => $hasAdmin ? !empty($user['is_admin']) : $index === 0,
        'password_reset_token_hash' => isset($user['password_reset_token_hash']) && is_string($user['password_reset_token_hash']) && $user['password_reset_token_hash'] !== ''
            ? $user['password_reset_token_hash']
            : null,
        'password_reset_expires_at' => isset($user['password_reset_expires_at']) && is_int($user['password_reset_expires_at'])
            ? $user['password_reset_expires_at']
            : null,
    ];
}

function canonicalizeUsersStore(array $store): array
{
    $users = isset($store['users']) && is_array($store['users']) ? $store['users'] : [];
    $hasAdmin = false;

    foreach ($users as $user) {
        if (is_array($user) && !empty($user['is_admin'])) {
            $hasAdmin = true;
            break;
        }
    }

    $normalizedUsers = [];
    foreach ($users as $index => $user) {
        if (!is_array($user)) {
            continue;
        }

        $normalizedUsers[] = canonicalizeUserRecord($user, $index, $hasAdmin);
    }

    return ['users' => $normalizedUsers];
}

function countAdministrators(array $users): int
{
    $count = 0;

    foreach ($users as $user) {
        if (!empty($user['is_admin'])) {
            $count++;
        }
    }

    return $count;
}

function countActiveAdministrators(array $users): int
{
    $count = 0;

    foreach ($users as $user) {
        if (!empty($user['is_admin']) && !empty($user['is_active'])) {
            $count++;
        }
    }

    return $count;
}

function ensureAdministratorRemains(array $users)
{
    if ($users !== [] && countAdministrators($users) === 0) {
        throw new RuntimeException('Es muss immer mindestens ein Administrator vorhanden sein.');
    }
}

function ensureActiveAdministratorRemains(array $users)
{
    if ($users !== [] && countActiveAdministrators($users) === 0) {
        throw new RuntimeException('Es muss immer mindestens ein aktiver Administrator vorhanden sein.');
    }
}

function initializeAuthStorage()
{
    $config = authConfig();
    $dataDir = dirname(dirname((string) $config['users_file']));
    $usersRootDir = (string) $config['users_root_dir'];
    $legacyUsersFile = $dataDir . DIRECTORY_SEPARATOR . 'users.json';
    $legacyUsersFileInUsersDir = $usersRootDir . DIRECTORY_SEPARATOR . 'users.json';
    $legacyAccountsFileInUsersDir = $usersRootDir . DIRECTORY_SEPARATOR . 'accounts.json';

    if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
        throw new RuntimeException('Datenverzeichnis konnte nicht erstellt werden.');
    }

    if (!is_dir($usersRootDir) && !mkdir($usersRootDir, 0755, true) && !is_dir($usersRootDir)) {
        throw new RuntimeException('Benutzerdaten-Verzeichnis konnte nicht erstellt werden.');
    }

    $usersFile = (string) $config['users_file'];
    if (!file_exists($usersFile) && file_exists($legacyUsersFile)) {
        rename($legacyUsersFile, $usersFile);
    } elseif (!file_exists($usersFile) && file_exists($legacyUsersFileInUsersDir)) {
        rename($legacyUsersFileInUsersDir, $usersFile);
    } elseif (!file_exists($usersFile) && file_exists($legacyAccountsFileInUsersDir)) {
        rename($legacyAccountsFileInUsersDir, $usersFile);
    }

    if (!file_exists($usersFile)) {
        $encoded = json_encode(defaultUsersStore(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Benutzerspeicher konnte nicht initialisiert werden.');
        }

        if (file_put_contents($usersFile, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Benutzerspeicher konnte nicht angelegt werden.');
        }
    }
}

function readUsersStore(): array
{
    initializeAuthStorage();
    $usersFile = (string) authConfig()['users_file'];
    $handle = fopen($usersFile, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Benutzerspeicher konnte nicht geöffnet werden.');
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('Benutzerspeicher konnte nicht gesperrt werden.');
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    if (!is_string($raw) || trim($raw) === '') {
        return defaultUsersStore();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return defaultUsersStore();
    }

    return canonicalizeUsersStore($data);
}

function mutateUsersStore(callable $callback)
{
    initializeAuthStorage();
    $usersFile = (string) authConfig()['users_file'];
    $handle = fopen($usersFile, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Benutzerspeicher konnte nicht geöffnet werden.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Benutzerspeicher konnte nicht exklusiv gesperrt werden.');
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $store = json_decode((string) $raw, true);
        $store = is_array($store) ? canonicalizeUsersStore($store) : defaultUsersStore();

        $result = $callback($store);
        $store = canonicalizeUsersStore($store);
        ensureAdministratorRemains($store['users']);

        $encoded = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Benutzerspeicher konnte nicht serialisiert werden.');
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $encoded);
        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function userProfilePath(string $normalizedUsername): string
{
    return userDirectoryPath($normalizedUsername) . DIRECTORY_SEPARATOR . 'profile.json';
}

function userPersistentLoginTokensPath(string $normalizedUsername): string
{
    return userDirectoryPath($normalizedUsername) . DIRECTORY_SEPARATOR . 'persistent-login-tokens.json';
}

function defaultPersistentLoginTokensStore(): array
{
    return ['tokens' => []];
}

function canonicalizePersistentLoginTokensStore(array $store): array
{
    $tokens = isset($store['tokens']) && is_array($store['tokens']) ? $store['tokens'] : [];
    $normalizedTokens = [];
    $now = time();

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }

        $selector = strtolower(trim((string) ($token['selector'] ?? '')));
        $validatorHash = strtolower(trim((string) ($token['validator_hash'] ?? '')));
        $expiresAt = (int) ($token['expires_at'] ?? 0);
        $createdAt = (string) ($token['created_at'] ?? date(DATE_ATOM));
        $lastUsedAt = (string) ($token['last_used_at'] ?? $createdAt);

        if ($selector === '' || $validatorHash === '' || $expiresAt <= $now) {
            continue;
        }

        $normalizedTokens[] = [
            'selector' => $selector,
            'validator_hash' => $validatorHash,
            'expires_at' => $expiresAt,
            'created_at' => $createdAt,
            'last_used_at' => $lastUsedAt,
        ];
    }

    return ['tokens' => $normalizedTokens];
}

function readUserPersistentLoginTokensStore(string $normalizedUsername): array
{
    $tokensFile = userPersistentLoginTokensPath($normalizedUsername);
    if (!file_exists($tokensFile)) {
        return defaultPersistentLoginTokensStore();
    }

    $handle = fopen($tokensFile, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Persistente Login-Tokens konnten nicht geöffnet werden.');
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('Persistente Login-Tokens konnten nicht gesperrt werden.');
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    if (!is_string($raw) || trim($raw) === '') {
        return defaultPersistentLoginTokensStore();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return defaultPersistentLoginTokensStore();
    }

    return canonicalizePersistentLoginTokensStore($data);
}

function mutateUserPersistentLoginTokensStore(string $normalizedUsername, callable $callback)
{
    ensureUserDirectory($normalizedUsername);
    $tokensFile = userPersistentLoginTokensPath($normalizedUsername);
    $handle = fopen($tokensFile, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Persistente Login-Tokens konnten nicht geöffnet werden.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Persistente Login-Tokens konnten nicht exklusiv gesperrt werden.');
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $store = json_decode((string) $raw, true);
        $store = is_array($store) ? canonicalizePersistentLoginTokensStore($store) : defaultPersistentLoginTokensStore();

        $result = $callback($store);
        $store = canonicalizePersistentLoginTokensStore($store);
        $encoded = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Persistente Login-Tokens konnten nicht serialisiert werden.');
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $encoded);
        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function findUserPersistentLoginToken(string $normalizedUsername, string $selector)
{
    foreach (readUserPersistentLoginTokensStore($normalizedUsername)['tokens'] as $token) {
        if (($token['selector'] ?? '') === $selector) {
            return $token;
        }
    }

    return null;
}

function storeUserPersistentLoginToken(string $normalizedUsername, array $token)
{
    mutateUserPersistentLoginTokensStore($normalizedUsername, function (array &$store) use ($token) {
        $replaced = false;

        foreach ($store['tokens'] as $index => $existingToken) {
            if (($existingToken['selector'] ?? '') !== ($token['selector'] ?? '')) {
                continue;
            }

            $store['tokens'][$index] = $token;
            $replaced = true;
            break;
        }

        if (!$replaced) {
            $store['tokens'][] = $token;
        }

        return null;
    });
}

function deleteUserPersistentLoginToken(string $normalizedUsername, string $selector)
{
    $tokensFile = userPersistentLoginTokensPath($normalizedUsername);
    if (!file_exists($tokensFile)) {
        return;
    }

    mutateUserPersistentLoginTokensStore($normalizedUsername, function (array &$store) use ($selector) {
        $store['tokens'] = array_values(array_filter($store['tokens'], function (array $token) use ($selector): bool {
            return ($token['selector'] ?? '') !== $selector;
        }));

        return null;
    });
}

function userDataRootDir(): string
{
    return (string) authConfig()['users_root_dir'];
}

function userDirectoryPath(string $normalizedUsername): string
{
    return userDataRootDir() . DIRECTORY_SEPARATOR . $normalizedUsername;
}

function ensureUserDirectory(string $normalizedUsername)
{
    $userDir = userDirectoryPath($normalizedUsername);
    if (!is_dir($userDir) && !mkdir($userDir, 0755, true) && !is_dir($userDir)) {
        throw new RuntimeException('Benutzerverzeichnis konnte nicht erstellt werden: ' . $userDir);
    }
}

function migrateLegacyUserStorage(string $normalizedUsername)
{
    $legacyProfile = userDataRootDir() . DIRECTORY_SEPARATOR . $normalizedUsername . '.json';

    if (file_exists($legacyProfile) && !file_exists(userProfilePath($normalizedUsername))) {
        ensureUserDirectory($normalizedUsername);
        rename($legacyProfile, userProfilePath($normalizedUsername));
    }
}

function persistUserProfile(array $user)
{
    ensureUserDirectory((string) $user['username_normalized']);
    $profile = [
        'username' => $user['username'],
        'username_normalized' => $user['username_normalized'],
        'created_at' => $user['created_at'],
        'updated_at' => $user['updated_at'],
        'is_admin' => !empty($user['is_admin']),
    ];

    $encoded = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        throw new RuntimeException('Benutzerprofil konnte nicht serialisiert werden.');
    }

    if (file_put_contents(userProfilePath((string) $user['username_normalized']), $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Benutzerprofil konnte nicht gespeichert werden.');
    }
}

function deleteDirectoryRecursive(string $dir)
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        throw new RuntimeException('Verzeichnis konnte nicht gelesen werden: ' . $dir);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            deleteDirectoryRecursive($path);
            continue;
        }

        if (!unlink($path)) {
            throw new RuntimeException('Datei konnte nicht gelöscht werden: ' . $path);
        }
    }

    if (!rmdir($dir)) {
        throw new RuntimeException('Verzeichnis konnte nicht gelöscht werden: ' . $dir);
    }
}

function deleteUserArtifacts(string $normalizedUsername)
{
    $userDir = userDirectoryPath($normalizedUsername);

    if (is_dir($userDir)) {
        deleteDirectoryRecursive($userDir);
    }
}

function renameUserArtifacts(string $fromNormalizedUsername, string $toNormalizedUsername)
{
    if ($fromNormalizedUsername === $toNormalizedUsername) {
        return;
    }

    $fromDir = userDirectoryPath($fromNormalizedUsername);
    $toDir = userDirectoryPath($toNormalizedUsername);

    if (is_dir($toDir)) {
        throw new RuntimeException('Das Zielverzeichnis des Benutzers existiert bereits.');
    }

    if (!is_dir($fromDir)) {
        return;
    }

    if (!rename($fromDir, $toDir)) {
        throw new RuntimeException('Benutzerverzeichnis konnte nicht umbenannt werden.');
    }
}

function listUsers(): array
{
    return readUsersStore()['users'];
}

function findUser(string $username)
{
    $normalized = normalizeUsername($username);
    return findUserByNormalizedUsername($normalized);
}

function findUserByNormalizedUsername(string $normalizedUsername)
{
    migrateLegacyUserStorage($normalizedUsername);

    foreach (listUsers() as $user) {
        if (($user['username_normalized'] ?? '') === $normalizedUsername) {
            return $user;
        }
    }

    return null;
}

function findUserByEmail(string $email)
{
    $email = trim($email);
    if ($email === '') {
        return null;
    }

    foreach (listUsers() as $user) {
        if (is_string($user['email'] ?? null) && strcasecmp((string) $user['email'], $email) === 0) {
            return $user;
        }
    }

    return null;
}

function createUserAccount(string $username, string $password, bool $isAdmin = false, ?string $email = null, ?bool $isActive = null): array
{
    $normalized = normalizeUsername($username);
    $now = date(DATE_ATOM);

    $user = mutateUsersStore(function (array &$store) use ($normalized, $username, $password, $now, $isAdmin, $email, $isActive): array {
        foreach ($store['users'] as $existingUser) {
            if (($existingUser['username_normalized'] ?? '') === $normalized) {
                throw new RuntimeException('Der Benutzername ist bereits vergeben.');
            }
        }

        $isFirstUser = $store['users'] === [];
        $resolvedIsActive = $isActive;
        if ($resolvedIsActive === null) {
            $resolvedIsActive = $isFirstUser ? true : !requireAdminApprovalForRegistration();
        }

        $user = [
            'username' => trim($username),
            'username_normalized' => $normalized,
            'email' => $email !== null && trim($email) !== '' ? trim($email) : null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => $now,
            'updated_at' => $now,
            'is_active' => $isFirstUser ? true : $resolvedIsActive,
            'is_admin' => $isFirstUser ? true : $isAdmin,
        ];

        $store['users'][] = $user;
        return $user;
    });

    persistUserProfile($user);
    authFireHook('authOnUserCreated', $user);

    return $user;
}

function updateUserPassword(string $normalizedUsername, string $newPassword)
{
    $updatedUser = mutateUsersStore(function (array &$store) use ($normalizedUsername, $newPassword) {
        foreach ($store['users'] as &$user) {
            if (($user['username_normalized'] ?? '') !== $normalizedUsername) {
                continue;
            }

            $user['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            $user['password_reset_token_hash'] = null;
            $user['password_reset_expires_at'] = null;
            $user['updated_at'] = date(DATE_ATOM);
            return $user;
        }

        throw new RuntimeException('Benutzer nicht gefunden.');
    });

    if (is_array($updatedUser)) {
        persistUserProfile($updatedUser);
    }
}

function updateUserEmail(string $normalizedUsername, ?string $email)
{
    $updatedUser = mutateUsersStore(function (array &$store) use ($normalizedUsername, $email) {
        foreach ($store['users'] as &$user) {
            if (($user['username_normalized'] ?? '') !== $normalizedUsername) {
                continue;
            }

            $user['email'] = $email !== null && trim($email) !== '' ? trim($email) : null;
            $user['updated_at'] = date(DATE_ATOM);
            return $user;
        }

        throw new RuntimeException('Benutzer nicht gefunden.');
    });

    if (is_array($updatedUser)) {
        persistUserProfile($updatedUser);
    }

    return $updatedUser;
}

function setUserPasswordResetToken(string $normalizedUsername, string $tokenHash, int $expiresAt)
{
    return mutateUsersStore(function (array &$store) use ($normalizedUsername, $tokenHash, $expiresAt) {
        foreach ($store['users'] as &$user) {
            if (($user['username_normalized'] ?? '') !== $normalizedUsername) {
                continue;
            }

            $user['password_reset_token_hash'] = $tokenHash;
            $user['password_reset_expires_at'] = $expiresAt;
            return $user;
        }

        throw new RuntimeException('Benutzer nicht gefunden.');
    });
}

function clearUserPasswordResetToken(string $normalizedUsername)
{
    mutateUsersStore(function (array &$store) use ($normalizedUsername) {
        foreach ($store['users'] as &$user) {
            if (($user['username_normalized'] ?? '') !== $normalizedUsername) {
                continue;
            }

            $user['password_reset_token_hash'] = null;
            $user['password_reset_expires_at'] = null;
            return $user;
        }

        return null;
    });
}

function updateUserActiveStatus(string $normalizedUsername, bool $isActive): array
{
    $updatedUser = mutateUsersStore(function (array &$store) use ($normalizedUsername, $isActive) {
        $updatedUsers = [];
        $updatedUser = null;

        foreach ($store['users'] as $user) {
            if (($user['username_normalized'] ?? '') !== $normalizedUsername) {
                $updatedUsers[] = $user;
                continue;
            }

            $user['is_active'] = $isActive;
            $user['updated_at'] = date(DATE_ATOM);
            $updatedUser = $user;
            $updatedUsers[] = $user;
        }

        if ($updatedUser === null) {
            throw new RuntimeException('Benutzer nicht gefunden.');
        }

        if (!$isActive) {
            ensureActiveAdministratorRemains($updatedUsers);
        }

        $store['users'] = $updatedUsers;

        return $updatedUser;
    });

    persistUserProfile($updatedUser);

    return $updatedUser;
}

function updateUserAccountRecord(string $normalizedUsername, string $newUsername, bool $isAdmin): array
{
    $newUsername = trim($newUsername);
    $newNormalizedUsername = normalizeUsername($newUsername);

    $result = mutateUsersStore(function (array &$store) use ($normalizedUsername, $newUsername, $newNormalizedUsername, $isAdmin) {
        $updatedUser = null;
        $previousUser = null;
        $updatedUsers = [];

        foreach ($store['users'] as $user) {
            $currentNormalized = (string) ($user['username_normalized'] ?? '');

            if ($currentNormalized !== $normalizedUsername && $currentNormalized === $newNormalizedUsername) {
                throw new RuntimeException('Der Benutzername ist bereits vergeben.');
            }

            if ($currentNormalized !== $normalizedUsername) {
                $updatedUsers[] = $user;
                continue;
            }

            $previousUser = $user;
            $updatedUser = $user;
            $updatedUser['username'] = $newUsername;
            $updatedUser['username_normalized'] = $newNormalizedUsername;
            $updatedUser['updated_at'] = date(DATE_ATOM);
            $updatedUser['is_admin'] = $isAdmin;
            $updatedUsers[] = $updatedUser;
        }

        if ($updatedUser === null || $previousUser === null) {
            throw new RuntimeException('Benutzer nicht gefunden.');
        }

        ensureAdministratorRemains($updatedUsers);
        $store['users'] = $updatedUsers;

        return [
            'previous' => $previousUser,
            'current' => $updatedUser,
        ];
    });

    $previousUser = $result['previous'];
    $updatedUser = $result['current'];
    $previousNormalizedUsername = (string) $previousUser['username_normalized'];
    $updatedNormalizedUsername = (string) $updatedUser['username_normalized'];
    $directoryRenamed = false;
    $hookFired = false;

    try {
        renameUserArtifacts($previousNormalizedUsername, $updatedNormalizedUsername);
        $directoryRenamed = $previousNormalizedUsername !== $updatedNormalizedUsername;
        authFireHook('authOnUserRenamed', $previousNormalizedUsername, $updatedNormalizedUsername);
        $hookFired = $previousNormalizedUsername !== $updatedNormalizedUsername;
        persistUserProfile($updatedUser);
    } catch (RuntimeException $runtimeException) {
        if ($hookFired) {
            authFireHook('authOnUserRenamed', $updatedNormalizedUsername, $previousNormalizedUsername);
        }

        if ($directoryRenamed) {
            renameUserArtifacts($updatedNormalizedUsername, $previousNormalizedUsername);
        }

        mutateUsersStore(function (array &$store) use ($previousUser, $updatedNormalizedUsername) {
            foreach ($store['users'] as $index => $user) {
                if (($user['username_normalized'] ?? '') === $updatedNormalizedUsername) {
                    $store['users'][$index] = $previousUser;
                    return null;
                }
            }

            $store['users'][] = $previousUser;
            return null;
        });

        persistUserProfile($previousUser);
        throw $runtimeException;
    }

    return $updatedUser;
}

function deleteUserAccountRecord(string $normalizedUsername)
{
    $deletedUser = mutateUsersStore(function (array &$store) use ($normalizedUsername) {
        $updatedUsers = [];
        $deletedUser = null;

        foreach ($store['users'] as $user) {
            if (($user['username_normalized'] ?? '') === $normalizedUsername) {
                $deletedUser = $user;
                continue;
            }

            $updatedUsers[] = $user;
        }

        if ($deletedUser === null) {
            throw new RuntimeException('Benutzer nicht gefunden.');
        }

        ensureAdministratorRemains($updatedUsers);
        $store['users'] = $updatedUsers;
        return $deletedUser;
    });

    try {
        deleteUserArtifacts($normalizedUsername);
        authFireHook('authOnUserDeleted', $normalizedUsername);
    } catch (RuntimeException $runtimeException) {
        mutateUsersStore(function (array &$store) use ($deletedUser, $normalizedUsername) {
            foreach ($store['users'] as $user) {
                if (($user['username_normalized'] ?? '') === $normalizedUsername) {
                    return null;
                }
            }

            $store['users'][] = $deletedUser;
            return null;
        });

        if (is_array($deletedUser)) {
            persistUserProfile($deletedUser);
        }

        throw $runtimeException;
    }

    return $deletedUser;
}
