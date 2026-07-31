<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hooks.php';
require_once __DIR__ . '/format.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/persistent-login.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/password-reset.php';
require_once __DIR__ . '/mailer.php';

/**
 * Von jeder App aus dem eigenen www/auth/bootstrap.php aufzurufen, nachdem
 * dieses Verzeichnis (auth-core/src/bootstrap.php) requiret wurde.
 * $webRoot ist der Pfad zum www/-Verzeichnis der jeweiligen App (i.d.R. dirname(__DIR__)
 * relativ zur app-eigenen www/auth/bootstrap.php).
 * $appConfigOverrides sind fest einkompilierte App-Defaults (app_name, session_name, routes, ...) -
 * zusaetzliche Ueberschreibungen kommen weiterhin wie bisher aus www/auth-site-config.php.
 */
function authCoreBootstrap(string $webRoot, array $appConfigOverrides = []): void
{
    authInitConfig($webRoot, $appConfigOverrides);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $config = authConfig();
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
        }

        session_name((string) $config['session_name']);
        session_start();
    }

    initializeAuthStorage();

    $currentUser = currentUser();
    if ($currentUser === null) {
        restorePersistentLogin();
    } else {
        maintainPersistentLogin($currentUser);
    }
}
