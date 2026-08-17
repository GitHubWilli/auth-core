# auth-core

Gemeinsame, **rein backend-seitige** Benutzerverwaltungs-Logik (Session, Login, Registrierung, Passwort-Reset
per E-Mail, Admin-CRUD auf Nutzerkonten) für die PHP/Docker-Apps im `C:\Projekte`-Workspace
(HTML-Startseite, checklisten, systeminfo-tool, wochendisposition, ...).

Zweck: jede App verwaltet ihre eigenen Benutzerkonten **unabhängig** in einem eigenen lokalen
`storage/auth/users/@accounts.json` — es gibt **keine** gemeinsame Identität über Apps hinweg (kein SSO).
Um trotzdem keinen Code doppelt zu pflegen, ist die dafür nötige Logik hier zentral gebündelt.

Diese Komponente kennt **kein** HTML/CSS und kein App-Branding — Login-/Registrierungs-Formulare, E-Mail-
Layout-Wünsche etc. bleiben bewusst in jeder App lokal (`www/auth/view.php`), analog dazu, dass jede App auch
ihre eigene visuelle Identität behält. `auth-core` liefert nur PHP-Funktionen und dünne API-Endpunkte.

## Einbindung (Git-Submodule)

```bash
git submodule add https://github.com/GitHubWilli/auth-core.git www/auth-core
git config -f .gitmodules submodule.www/auth-core.branch main
git add .gitmodules www/auth-core
git commit -m "chore: gemeinsame Benutzerverwaltung als Submodule einbinden"
```

Beim Klonen des einbindenden Projekts:

```bash
git clone --recurse-submodules <projekt-repo-url>
# oder nachträglich:
git submodule update --init --recursive
```

`www/auth-core` bewusst **nicht** `www/auth/` genannt, da jede App weiterhin ihr eigenes `www/auth/`-Verzeichnis
mit den unten beschriebenen dünnen, app-spezifischen Dateien behält.

## Struktur

```
auth-core/
  src/
    bootstrap.php         # requiret alle anderen src/*.php, stellt authCoreBootstrap() bereit
    config.php             # authConfig()/authRoute()/authUrl()/... - Default-Werte + Merge mit auth-site-config.php
    hooks.php               # authFireHook() - optionaler Erweiterungspunkt fuer App-eigene Nutzer-Lifecycle-Hooks
    users.php               # Nutzerspeicher (@accounts.json), CRUD, Aktivierung/Sperrung
    auth.php                 # Login/Session/requireAuth()/requireAdmin()/...
    settings.php             # Laufzeit-Einstellungen (Selbstregistrierung, Admin-Freischaltung-Pflicht)
    #                          (Policies fuer Selbst-Passwortaenderung/-Kontoloeschung liegen dagegen in
    #                          config.php - siehe 'policies' unten, statisch je App per auth-site-config.php)
    persistent-login.php     # "Angemeldet bleiben"-Cookie
    password-reset.php       # Token-Erzeugung/-Pruefung fuer Passwort-vergessen
    mailer.php                # SMTP-Mailversand (PHPMailer, siehe vendor/)
    csrf.php, flash.php, format.php, validation.php   # generische Helfer
  api/                      # duenne Endpunkte, per require in www/api/*.php jeder App eingebunden
    register.php, login.php, logout.php, change-password.php, delete-account.php,
    admin-create-user.php, admin-update-user.php, admin-delete-user.php,
    admin-update-registration.php, admin-update-active-status.php,
    forgot-password.php, reset-password.php
  vendor/                   # PHPMailer (committed, siehe composer.json - kein composer install je App noetig)
```

## Einbindung in einer App

Jede App behält ihr eigenes `www/auth/` mit **nur** noch folgenden lokalen Dateien:

- `www/auth/bootstrap.php` — dünner Wrapper, siehe unten
- `www/auth/view.php` — App-eigenes HTML/CSS-Rendering (Branding bleibt lokal)
- `www/auth-site-config.php` — bestehendes Overrides-Muster, unverändert (app_name, session_name, Routen, ...)
- `www/login.php`, `register.php`, `logout.php`, `profile.php`, `users.php`, `forgot-password.php`,
  `reset-password.php` — Seiten-Templates (nutzen `view.php` + Funktionen aus `auth-core`)
- `www/api/*.php` — dünne Wrapper, siehe unten

### `www/auth/bootstrap.php`

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth-core/src/bootstrap.php';

authCoreBootstrap(dirname(__DIR__), [
    'app_name' => 'HTML-Startseite',
    'session_name' => 'html_startseite_session',
    // weitere Defaults optional, z.B. 'registration' => ['require_admin_approval' => true]
    // Feintuning bleibt wie bisher zusaetzlich per www/auth-site-config.php moeglich.
]);

require_once __DIR__ . '/view.php';
```

### `www/api/register.php` (analog für alle anderen Endpunkte)

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../auth/bootstrap.php';
require __DIR__ . '/../auth-core/api/register.php';
```

### Nutzer-Lifecycle-Hooks (optional)

Falls eine App bei Anlegen/Umbenennen/Löschen eines Nutzers eigene Daten mitpflegen muss (z.B. wie
`adventskalender-2025` es für Türchen-Spielstände tut), definiert sie einfach eine der folgenden Funktionen
*vor* dem Aufruf von `authCoreBootstrap()` — existiert die Funktion nicht, passiert nichts:

```php
function authOnUserCreated(array $user): void { /* ... */ }
function authOnUserRenamed(string $fromNormalizedUsername, string $toNormalizedUsername): void { /* ... */ }
function authOnUserDeleted(string $normalizedUsername): void { /* ... */ }
```

## Nutzerdatenmodell (`storage/auth/users/@accounts.json`)

Jeder Nutzer-Datensatz: `username`, `username_normalized`, `email` (nullable), `password_hash`, `created_at`,
`updated_at`, `is_active`, `is_admin`, `password_reset_token_hash` (nullable), `password_reset_expires_at`
(nullable). Der erste angelegte Nutzer eines Pools wird automatisch aktiver Administrator.

## Konfiguration (`www/auth-site-config.php`, zusätzlich zu bestehenden Schlüsseln)

```php
return [
    // ...bestehende Schluessel (app_name, session_name, routes, ...) unveraendert...
    'email' => [
        'from_address' => 'noreply@example.org',
        'from_name' => 'HTML-Startseite',
    ],
    'registration' => [
        'require_admin_approval' => false, // Startwert; per Admin-UI zur Laufzeit umschaltbar
    ],
    'policies' => [
        // Default true (= heutiges Verhalten). Auf false setzen, um Nicht-Admins die jeweilige
        // Selbstverwaltung zu entziehen; Admins sind davon nie betroffen. Wird zusaetzlich zur
        // UI (profile.php pro App) auch serverseitig in auth-core/api/change-password.php bzw.
        // delete-account.php durchgesetzt (requireOwnPasswordChangeAllowed()/requireOwnAccountDeletionAllowed()).
        'allow_non_admin_password_change' => true,
        'allow_non_admin_account_deletion' => true,
    ],
    'smtp' => [
        'host' => 'smtp.example.org',
        'port' => 587,
        'encryption' => 'tls', // 'tls' | 'ssl' | ''
        'username' => 'noreply@example.org',
        'password' => '...',
    ],
    'public_base_url' => 'https://beispiel.dedyn.io', // fuer absolute Links in E-Mails; leer = aus Request abgeleitet
];
```

## Design-Regeln

1. Reine Backend-Logik, kein HTML/CSS/JS — Rendering bleibt in jeder App lokal (`view.php`).
2. Alle Pfade werden über `authConfig()`/`authRoute()`/`authUrl()` aufgelöst, niemals hartkodiert — jede App
   bekommt ihren eigenen Pfad-Kontext über `authCoreBootstrap($webRoot, ...)`.
3. Kein globaler Zustand über `authInitConfig()`/`authCoreBootstrap()` hinaus — jede App hat ihren eigenen
   Prozess/Request, keine gemeinsame Laufzeit mit anderen Apps.
4. Passwort-Hashes: `password_hash($password, PASSWORD_DEFAULT)` (Bcrypt), `password_needs_rehash()`-Upgrade
   beim Login. Migrierte Alt-Hashes aus central-auth sind 1:1 kompatibel.
5. Passwort-vergessen-Flow: Token nur gehasht gespeichert, Ablaufzeit konfigurierbar
   (`password_reset.token_lifetime`, Sekunden), identische Erfolgsmeldung unabhängig davon ob ein Konto
   gefunden wurde (kein Enumeration-Leak).
6. `vendor/` (PHPMailer) ist committed — keine App muss `composer install` ausführen.

## Update-Prozedur (pro einbindendem Projekt)

```bash
cd <projekt>
git submodule update --remote --merge www/auth-core
git add www/auth-core
git commit -m "chore: auth-core auf <version> aktualisieren"
git push
```

Projekte müssen nicht synchron aktualisiert werden — jedes Projekt pinnt seine eigene Version über den
Submodule-Pointer.
