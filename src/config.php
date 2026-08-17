<?php
declare(strict_types=1);

/**
 * auth-core Konfiguration.
 *
 * Jede einbindende App ruft in ihrem eigenen www/auth/bootstrap.php einmalig
 * authInitConfig(dirname(__DIR__)) auf (d.h. mit dem Pfad zu ihrem eigenen
 * www/-Verzeichnis), bevor authConfig() zum ersten Mal aufgerufen wird.
 * App-spezifische Werte (app_name, session_name, Routen, ...) kommen weiterhin
 * wie bisher über www/auth-site-config.php (per authMergeConfig() gemischt).
 */

function authInitConfig(string $webRoot, array $appOverrides = []): void
{
    global $__authCoreWebRoot, $__authCoreAppOverrides, $__authCoreConfigCache;

    $__authCoreWebRoot = rtrim($webRoot, '/\\');
    $__authCoreAppOverrides = $appOverrides;
    $__authCoreConfigCache = null;
}

function authWebRoot(): string
{
    global $__authCoreWebRoot;

    if (empty($__authCoreWebRoot)) {
        throw new RuntimeException('authInitConfig() wurde nicht aufgerufen.');
    }

    return $__authCoreWebRoot;
}

function authProjectRoot(): string
{
    return dirname(authWebRoot());
}

function authStorageRoot(): string
{
    return authProjectRoot() . DIRECTORY_SEPARATOR . 'storage';
}

function authConfigOverridePath(): string
{
    return authWebRoot() . DIRECTORY_SEPARATOR . 'auth-site-config.php';
}

function authDefaultConfig(): array
{
    $storageRootDir = authStorageRoot();
    $authStorageDir = $storageRootDir . DIRECTORY_SEPARATOR . 'auth';
    $usersRootDir = $authStorageDir . DIRECTORY_SEPARATOR . 'users';

    return [
        'app_name' => 'App',
        'base_path' => '',
        'public_base_url' => '',
        'storage_root' => $storageRootDir,
        'auth_settings_file' => $authStorageDir . DIRECTORY_SEPARATOR . 'auth-settings.json',
        'session_name' => 'auth_session',
        'persistent_login' => [
            'enabled' => true,
            'cookie_name' => '',
            'lifetime' => 157680000,
        ],
        'users_root_dir' => $usersRootDir,
        'users_file' => $usersRootDir . DIRECTORY_SEPARATOR . '@accounts.json',
        'min_password_length' => 8,
        'max_username_length' => 40,
        'email' => [
            'from_address' => '',
            'from_name' => '',
        ],
        'registration' => [
            'require_admin_approval' => false,
        ],
        'policies' => [
            'allow_non_admin_password_change' => true,
            'allow_non_admin_account_deletion' => false,
        ],
        'password_reset' => [
            'token_lifetime' => 2400,
        ],
        'smtp' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
        ],
        'routes' => [
            'home' => 'index.php',
            'login' => 'login.php',
            'register' => 'register.php',
            'logout' => 'logout.php',
            'profile' => 'profile.php',
            'users' => 'users.php',
            'forgot_password' => 'forgot-password.php',
            'reset_password' => 'reset-password.php',
            'after_login' => 'index.php',
            'after_register' => 'index.php',
            'after_logout' => 'login.php?logged_out=1',
            'after_account_delete' => 'register.php?deleted=1',
            'api_login' => 'api/login.php',
            'api_register' => 'api/register.php',
            'api_logout' => 'api/logout.php',
            'api_change_password' => 'api/change-password.php',
            'api_delete_account' => 'api/delete-account.php',
            'api_admin_create_user' => 'api/admin-create-user.php',
            'api_admin_update_user' => 'api/admin-update-user.php',
            'api_admin_delete_user' => 'api/admin-delete-user.php',
            'api_admin_update_registration' => 'api/admin-update-registration.php',
            'api_admin_update_active_status' => 'api/admin-update-active-status.php',
            'api_forgot_password' => 'api/forgot-password.php',
            'api_reset_password' => 'api/reset-password.php',
        ],
        'html_app_rewrites' => [
            'index.html' => 'index.php',
        ],
        'auth_context' => [
            'profile_url' => 'profile.php',
            'users_url' => 'users.php',
            'logout_url' => 'logout.php',
        ],
    ];
}

function authMergeConfig(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = authMergeConfig($base[$key], $value);
            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

function authNormalizedBasePath(): string
{
    $basePath = trim((string) (authConfig()['base_path'] ?? ''));

    if ($basePath === '' || $basePath === '/') {
        return '';
    }

    return '/' . trim($basePath, '/');
}

function authUrl(string $path = ''): string
{
    $basePath = authNormalizedBasePath();
    $path = ltrim($path, '/');

    if ($path === '') {
        return $basePath === '' ? '/' : $basePath . '/';
    }

    return ($basePath === '' ? '' : $basePath) . '/' . $path;
}

/**
 * Absolute URL fuer Links in E-Mails (Browser-Kontext liefert nur relative Pfade).
 * Nutzt 'public_base_url' aus der Konfiguration, falls gesetzt, sonst wird sie
 * aus dem aktuellen Request abgeleitet (nur sinnvoll, wenn tatsaechlich im
 * HTTP-Request-Kontext aufgerufen, z.B. aus forgot-password.php).
 */
function authAbsoluteUrl(string $path): string
{
    $baseUrl = trim((string) (authConfig()['public_base_url'] ?? ''));

    if ($baseUrl !== '') {
        return rtrim($baseUrl, '/') . $path;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '') {
        return $path;
    }

    return ($isHttps ? 'https://' : 'http://') . $host . $path;
}

function authRoute(string $name): string
{
    $routes = authConfig()['routes'] ?? [];
    $path = $routes[$name] ?? '';

    if (!is_string($path) || $path === '') {
        throw new RuntimeException('Unbekannte Auth-Route: ' . $name);
    }

    return authUrl($path);
}

function authContextUrl(string $name): string
{
    $context = authConfig()['auth_context'] ?? [];
    $path = $context[$name] ?? '';

    if (!is_string($path) || $path === '') {
        throw new RuntimeException('Unbekannte Auth-Context-URL: ' . $name);
    }

    return authUrl($path);
}

function authHtmlAppRewrites(): array
{
    $rewrites = authConfig()['html_app_rewrites'] ?? [];
    $normalized = [];

    foreach ($rewrites as $search => $replace) {
        if (!is_string($search) || $search === '' || !is_string($replace) || $replace === '') {
            continue;
        }

        $normalized[$search] = authUrl($replace);
    }

    return $normalized;
}

function authConfig(): array
{
    global $__authCoreAppOverrides, $__authCoreConfigCache;

    if ($__authCoreConfigCache !== null) {
        return $__authCoreConfigCache;
    }

    $config = authDefaultConfig();

    if (!empty($__authCoreAppOverrides) && is_array($__authCoreAppOverrides)) {
        $config = authMergeConfig($config, $__authCoreAppOverrides);
    }

    $overrideFile = authConfigOverridePath();

    if (file_exists($overrideFile)) {
        $override = require $overrideFile;
        if (is_array($override)) {
            $config = authMergeConfig($config, $override);
        }
    }

    $__authCoreConfigCache = $config;

    return $config;
}
