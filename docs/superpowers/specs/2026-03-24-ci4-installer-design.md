# CI4 Installer — Design Specification

**Date:** 2026-03-24
**Project:** CI4 Installer by EnlivenApp
**License:** Creative Commons — "Original script by EnlivenApp" attribution required
**Status:** Approved

---

## 1. Overview

A generic, reusable web-based installer for applications built on CodeIgniter 4. The installer is distributed as a single self-extracting PHP file (`install.php`) paired with a developer-defined configuration file (`installer-config.php`). It handles the full installation lifecycle — from environment detection through app download, configuration, database setup, and admin user creation — with zero command-line interaction required.

### Design Philosophy

- **WordPress-level compatibility.** If WordPress can install on a host, this installer should too.
- **"Try it and see" over "check and assume."** Validate by attempting real operations (connect to DB, write a file), not by checking preconditions.
- **Graduated fallback chains.** Every operation has a preferred automated path and a manual escape hatch. Never dead-end.
- **Idempotent operations.** The installer can run multiple times safely without corrupting state.
- **Never require shell access.** Everything works through PHP functions and HTTP. `exec()` is a nice-to-have enhancement, not a requirement.
- **Each step validates independently.** No step trusts that a previous step succeeded — it re-checks its own preconditions.

### Target Audience

End users who may have no terminal/CLI experience. The "upload via FTP and go" audience. Shared hosting, managed VPS, dedicated servers — the installer adapts to whatever is available.

---

## 2. Distribution

### What Ships to the End User

Two files:

| File | Purpose |
|------|---------|
| `install.php` | Self-extracting installer — contains all code, templates, CSS, JS packed as a base64-encoded zip |
| `installer-config.php` | Developer-defined configuration — app name, source URL, requirements, env vars, auth setup |

The user uploads both files to their web root (or target directory) and navigates to `install.php` in a browser. That's it.

### What the Developer Works With

A full modular PHP codebase in a Git repository. A build script (`php build/pack.php`) compiles everything into the single `install.php`.

---

## 3. Architecture

### Build-Time: Modular Source

```
ci4-installer/
├── src/
│   ├── Installer.php              # Main orchestrator
│   ├── Environment/
│   │   ├── Detector.php           # Server capability detection
│   │   └── Requirements.php       # Validate against config requirements
│   ├── Filesystem/
│   │   ├── FilesystemInterface.php
│   │   ├── FilesystemFactory.php  # Auto-detect and instantiate best driver
│   │   ├── DirectDriver.php       # Native PHP file operations
│   │   ├── FtpDriver.php          # PHP ftp_* functions
│   │   ├── FtpsDriver.php         # PHP ftp_ssl_connect
│   │   └── Ssh2Driver.php         # PHP ssh2_* PECL extension
│   ├── Database/
│   │   ├── ConnectionTester.php   # Test DB credentials by connecting
│   │   └── MigrationRunner.php    # Bootstrap CI4 and run migrations in PHP
│   ├── Source/
│   │   ├── SourceFactory.php      # Select best download strategy
│   │   ├── ComposerSource.php     # composer create-project via exec()
│   │   ├── GitSource.php          # git clone via exec()
│   │   ├── CurlSource.php         # Download zip via cURL
│   │   ├── StreamSource.php       # Download zip via file_get_contents
│   │   ├── FtpSource.php          # Download via FTP transport
│   │   └── ManualSource.php       # Show instructions for manual upload
│   ├── Auth/
│   │   ├── AuthAdapterInterface.php
│   │   ├── ShieldAdapter.php      # CodeIgniter Shield
│   │   ├── IonAuthAdapter.php     # IonAuth
│   │   ├── MythAuthAdapter.php    # Myth:Auth
│   │   ├── GenericAdapter.php     # Direct DB insert (configurable)
│   │   └── NoneAdapter.php        # Skip admin creation
│   ├── Config/
│   │   ├── EnvWriter.php          # Parse template, merge values, write .env
│   │   └── ConfigValidator.php    # Validate installer-config.php structure
│   └── UI/
│       ├── templates/             # DaisyUI/Alpine step templates
│       │   ├── layout.php         # Main layout wrapper
│       │   ├── step-welcome.php
│       │   ├── step-system-check.php
│       │   ├── step-filesystem.php
│       │   ├── step-database.php
│       │   ├── step-configuration.php
│       │   ├── step-app-settings.php
│       │   ├── step-admin.php
│       │   ├── step-install.php
│       │   └── step-complete.php
│       ├── assets/
│       │   ├── daisyui.min.css    # Purged/compiled, only used classes
│       │   └── alpine.min.js      # ~15KB minified
│       └── WizardRenderer.php     # Template engine and step router
├── build/
│   └── pack.php                   # Build script: source → install.php
├── installer-config.php           # Example/default configuration
├── tests/
├── LICENSE
└── README.md
```

### Runtime: Self-Extracting Installer

When a user hits `install.php` in a browser:

1. Check for `install.lock` — if exists, refuse to run
2. Decode the base64 constant containing the zip archive
3. Extract to `sys_get_temp_dir() . '/ci4-installer-' . md5(__DIR__)`
4. `require` the extracted `Installer.php`
5. Instantiate and run: `(new Installer(__DIR__))->run()`

The extracted temp directory is cleaned up in the final phase.

---

## 4. Installation Phases

### Phase 1: Environment Detection

Runs first. Informs every subsequent decision. All detection is empirical — try the operation, observe the result.

| Category | What | How |
|----------|------|-----|
| PHP | Version | `phpversion()` |
| PHP | Loaded extensions | `extension_loaded()` for each |
| PHP | Disabled functions | Parse `ini_get('disable_functions')` |
| PHP | Memory limit | `ini_get('memory_limit')` |
| PHP | Max execution time | `ini_get('max_execution_time')` |
| PHP | Upload max filesize | `ini_get('upload_max_filesize')` |
| Server | Software | `$_SERVER['SERVER_SOFTWARE']` — Apache, Nginx, LiteSpeed, IIS |
| Server | Document root | `$_SERVER['DOCUMENT_ROOT']` |
| Server | HTTPS | `$_SERVER['HTTPS']`, `X-Forwarded-Proto`, port 443 |
| Server | mod_rewrite (Apache) | Write test `.htaccess`, self-request, check result |
| Capabilities | `exec()` available | `function_exists()` AND not in `disable_functions` AND actually try it |
| Capabilities | `shell_exec()` | Same approach |
| Capabilities | Composer | `exec('composer --version')` if exec available |
| Capabilities | Git | `exec('git --version')` if exec available |
| Capabilities | Filesystem method | Ownership test (write temp file, compare `fileowner` vs `getmyuid`) |
| Database | Available drivers | `extension_loaded()` for mysqli, pgsql, sqlite3, sqlsrv |
| Permissions | Target dir writable | Actually try writing a temp file |
| Network | Outbound HTTP | Try a lightweight request to the download source |
| Protocol | HTTPS active | If yes, enable secure cookies for session state |

Results stored in a `ServerEnvironment` object. Each property has three states: `passed`, `failed`, `unknown`.

### Phase 2: Requirements Check

Compares the `ServerEnvironment` against the developer's `installer-config.php`. Produces a pass/fail/warning report.

- **Hard failures** block installation (e.g., PHP version too low, required extension missing, no usable DB driver)
- **Warnings** allow proceeding with a note (e.g., optional extension missing, low memory limit)
- User sees a clear green/red/yellow checklist

### Phase 3: User Configuration

Collects all values needed to configure the app. Multi-step wizard.

**Database credentials:**
- Driver selection (only detected/available drivers shown, filtered by config's `requirements.databases`)
- Hostname, port, database name, username, password
- "Test Connection" button — actually connects, reports specific failures
- If database doesn't exist — offer to create it. If create fails, show the SQL for manual execution.

**App configuration:**
- Base URL (auto-detected from request, user confirms)
- CI environment (production/development/testing)
- Encryption key (auto-generated via `random_bytes()` or best available CSPRNG)

**Developer-defined env vars:**
- Rendered from config's `env_vars` array
- Grouped by the `group` field under headings
- Each var has: key, label, type (text/password/email/url/select/boolean), required flag, help text, default value, validation pattern

**Admin user (if configured):**
- Username, email, password fields
- Only shown if auth system is not `none`

**Filesystem credentials (if needed):**
- Only shown if direct PHP file operations fail the ownership test
- FTP/FTPS/SSH2 hostname, username, password
- Credentials held in session memory only — never written to disk

### Phase 4: Installation

The actual work. Each substep reports progress to the UI.

**4a. Download the application:**

Fallback chain in priority order:

| Priority | Strategy | Condition |
|----------|----------|-----------|
| 1 | `composer create-project` | `exec()` available + composer found |
| 2 | `git clone` | `exec()` available + git found |
| 3 | cURL zip download | `extension_loaded('curl')` |
| 4 | `file_get_contents` zip download | `allow_url_fopen` enabled |
| 5 | FTP/SSH download from mirror | All above fail but FTP/SSH transport works |
| 6 | Manual upload | Everything fails — show URL and instructions |

For zip downloads, extraction fallback chain:
1. `ZipArchive` class
2. `exec('unzip ...')` if exec available
3. `PharData` (handles zip and tar)
4. Manual fallback — show instructions

**4b. Validate the download:**
- Check for `vendor/autoload.php`, `spark`, `app/Config/App.php`
- If `vendor/` missing + composer available → run `composer install --no-dev`
- If `vendor/` missing + no composer → fail with message: release zip must include `vendor/`

**4c. Write `.env` file:**
1. Read the app's `env` template
2. Merge with auto-detected values (baseURL, DB credentials, encryption key)
3. Merge with user-provided values from the wizard
4. Write via filesystem abstraction
5. Fallback: display full `.env` contents in a textarea with copy button and instructions
6. If manual fallback used — poll/check for file existence before allowing next step

**4d. Set directory permissions:**
- Create and chmod writable directories defined in config
- Via filesystem abstraction (direct/FTP/SSH)
- Fallback: show the exact directories and permissions needed

### Phase 5: Database Setup

Bootstrap CI4 programmatically — no shell required.

**Migration runner:**
```
1. require vendor/autoload.php
2. require app/Config/Paths.php
3. Boot CI4's Services container
4. Get the MigrationRunner service
5. Call $runner->latest()
```

This is exactly what `php spark migrate` does internally — same classes, called directly.

**Fallback chain:**
1. Programmatic CI4 bootstrap (above)
2. `exec('php spark migrate')` if exec available
3. Show error with guidance: "Run `php spark migrate` via SSH, or contact your hosting provider"

**Seeders** — same pattern: bootstrap CI4, `$seeder->call('ClassName')`.

**Admin user creation — auth adapter system:**

Config specifies the auth system:
```php
'auth' => [
    'system'  => 'shield',
    'collect' => ['username', 'email', 'password'],
    'group'   => 'superadmin',
],
```

| Adapter | How it creates the admin |
|---------|-------------------------|
| ShieldAdapter | Bootstrap CI4 → Shield's `UserModel`, `$user->addGroup()` |
| IonAuthAdapter | Bootstrap CI4 → IonAuth `register()` + group assignment |
| MythAuthAdapter | Bootstrap CI4 → Myth:Auth user entity + permissions |
| GenericAdapter | Direct DB insert into dev-specified table/fields with `password_hash()` |
| NoneAdapter | Skip — app handles first-run user creation |

Adapters use CI4's own classes after bootstrapping, respecting the auth library's hashing, validation, and event triggers. `GenericAdapter` is the escape hatch — dev specifies table, fields, and hashing method in config.

### Phase 6: Cleanup

1. **Self-delete** `install.php` via filesystem abstraction
2. **Fallback:** create `install.lock` file if delete fails
3. Remove temp extraction directory
4. Show success screen with link to the app
5. If lock file was created instead of delete — show security warning: "Delete install.php from your server for security"

---

## 5. Filesystem Abstraction

Four drivers implementing a common interface. Auto-detected by empirical ownership test.

### Interface

```
write($path, $content) → Result
read($path) → Result
mkdir($path, $recursive) → Result
delete($path) → Result
exists($path) → bool
isWritable($path) → bool
chmod($path, $mode) → Result
copy($source, $dest) → Result
move($source, $dest) → Result
listDir($path) → Result
extractZip($zipPath, $destPath) → Result
```

Every method returns a consistent result object: `success` (bool), `error_message` (string), and for reads, `content`.

### Drivers

| Priority | Driver | Mechanism | Detection |
|----------|--------|-----------|-----------|
| 1 | DirectDriver | `file_put_contents`, `mkdir`, `unlink`, etc. | Write temp file, `fileowner(temp) === getmyuid()` |
| 2 | FtpDriver | PHP `ftp_*` functions | Direct fails + `extension_loaded('ftp')` |
| 3 | FtpsDriver | `ftp_ssl_connect` | Direct fails + `function_exists('ftp_ssl_connect')` |
| 4 | Ssh2Driver | `ssh2_*` PECL extension | Direct fails + `extension_loaded('ssh2')` |

### Detection Flow

1. Try writing a temp file in the target directory using direct PHP
2. If success — check file ownership matches PHP process → **DirectDriver**
3. If ownership mismatch or write fails → probe for FTP/FTPS/SSH2 extensions
4. Present credentials form for best available transport
5. If nothing works → manual instructions for every file operation

The factory detects once, caches the result. FTP/SSH credentials collected in wizard UI and held in memory only.

### Session State

- HTTPS detected → encrypted secure cookies for state between steps
- No HTTPS → PHP session with server-side file storage
- Sensitive values (DB password, FTP credentials) held in session only, never to disk

---

## 6. Database Support

All four CI4-supported drivers:

| Driver | Extension | Typical Environment |
|--------|-----------|-------------------|
| MySQLi | `ext-mysqli` | Shared hosting, most common |
| PostgreSQL | `ext-pgsql` | VPS, managed DB services |
| SQLite3 | `ext-sqlite3` | Simple apps, no DB server needed |
| SQL Server | `ext-sqlsrv` | Windows/enterprise environments |

The installer detects which drivers are available. The developer config can restrict to a subset:

```php
'requirements' => [
    'databases' => ['MySQLi', 'Postgre'],  // only show these as options
],
```

If the config doesn't restrict, all detected drivers are offered.

### Connection Testing

Test by actually connecting — not by validating credential format.

- **MySQLi:** `mysqli_connect()` → `mysqli_select_db()`
- **PostgreSQL:** `pg_connect()` with connection string
- **SQLite3:** `new SQLite3($path)` — test file creation/write
- **SQL Server:** `sqlsrv_connect()`

Specific error reporting: "Server unreachable" vs "Bad credentials" vs "Database doesn't exist" — never generic "connection failed."

If database doesn't exist, offer to create it. If create fails, show the exact SQL for manual execution in phpMyAdmin or host's DB tool.

---

## 7. Source Download

### Download Strategies

| Priority | Strategy | Condition |
|----------|----------|-----------|
| 1 | `composer create-project` | `exec()` + composer found |
| 2 | `git clone` | `exec()` + git found |
| 3 | cURL download | `extension_loaded('curl')` |
| 4 | `file_get_contents` | `allow_url_fopen` enabled |
| 5 | FTP/SSH download | FTP/SSH transport available |
| 6 | Manual | Everything fails — show instructions |

### Zip Extraction Strategies

| Priority | Strategy | Condition |
|----------|----------|-----------|
| 1 | `ZipArchive` | `class_exists('ZipArchive')` |
| 2 | `exec('unzip')` | `exec()` available |
| 3 | `PharData` | Always available (built into PHP) |
| 4 | Manual | Show instructions |

### Post-Download Validation

- Check for `vendor/autoload.php`, `spark`, `app/Config/App.php`
- Missing `vendor/` + composer available → run `composer install --no-dev`
- Missing `vendor/` + no composer → fail with clear message about release zip requirements

### Developer Config

```php
'source' => [
    'composer' => 'pubvana/pubvana',
    'zip'      => 'https://github.com/.../releases/latest/download/pubvana-full.zip',
],
```

Both values provided. Installer picks the best strategy based on server capabilities.

---

## 8. UI/Wizard System

### Technology

- **DaisyUI** — CSS component library, compiled/purged, embedded in archive
- **Alpine.js** — ~15KB, embedded in archive. Progressive enhancement for inline validation, AJAX transitions, progress polling
- **Progressive enhancement** — base experience is plain HTML forms with full page reloads. JS enhances, never required.

### Wizard Steps

| Step | Title | Content |
|------|-------|---------|
| 1 | Welcome | App name, logo, version. "Let's get started." |
| 2 | System Check | Green/red/yellow requirement checklist. Hard failures block. |
| 3 | Filesystem | Auto-detected method shown. FTP/SSH credentials form if needed. |
| 4 | Database | Driver select, credentials form, "Test Connection" button. |
| 5 | Configuration | Base URL (pre-filled), environment select, encryption key (auto-generated). |
| 6 | App Settings | Developer-defined env vars, grouped. Only shows if config defines custom vars. |
| 7 | Admin Account | Username, email, password. Only shows if auth configured. |
| 8 | Install | Progress display — downloading, extracting, writing config, migrating, creating admin. |
| 9 | Complete | Success message, app link, security note about installer deletion. |

### Branding

Configured in `installer-config.php`:

```php
'branding' => [
    'name'  => 'Pubvana',
    'logo'  => 'logo.png',
    'colors' => [
        'primary'   => '#4F46E5',
        'secondary' => '#7C3AED',
        'accent'    => '#F59E0B',
        'neutral'   => '#1F2937',
        'base-100'  => '#FFFFFF',
        'base-200'  => '#F9FAFB',
        'base-300'  => '#E5E7EB',
    ],
],
```

Colors injected as CSS custom properties at runtime. Logo base64-encoded in the archive at build time, or referenced as a URL. Default colors ship with the installer and look good out of the box.

### Error Handling in UI

- Inline errors with specific guidance on the failing step
- "Try again" stays on same step with form pre-filled
- Manual fallback instructions appear below the error
- No generic "something went wrong" — always specific, always actionable

---

## 9. Auth Adapter System

### Interface

Every adapter implements:

```
canHandle() → bool          // Can this adapter work in the current environment?
getFields() → array         // What fields to collect from the user
createAdmin($data) → Result // Create the admin user with provided data
```

### Adapters

| Adapter | Auth Library | Creation Method |
|---------|-------------|-----------------|
| ShieldAdapter | CodeIgniter Shield | Bootstrap CI4 → `UserModel` → `addGroup()` |
| IonAuthAdapter | IonAuth | Bootstrap CI4 → `register()` → group assignment |
| MythAuthAdapter | Myth:Auth | Bootstrap CI4 → user entity → permission model |
| GenericAdapter | Any/custom | Direct DB insert, `password_hash()`, dev-configured table/fields |
| NoneAdapter | N/A | Skip — app handles first-run registration |

### Config

```php
'auth' => [
    'system'  => 'shield',
    'collect' => ['username', 'email', 'password'],
    'group'   => 'superadmin',
],
```

For `GenericAdapter`, additional config:

```php
'auth' => [
    'system'         => 'custom',
    'collect'        => ['email', 'password'],
    'table'          => 'users',
    'fields'         => [
        'email'      => 'email',
        'password'   => 'password_hash',
    ],
    'hash_method'    => 'PASSWORD_DEFAULT',
    'extra_inserts'  => [
        'role' => 'admin',
        'active' => 1,
    ],
],
```

---

## 10. Configuration File Reference

The full `installer-config.php` structure:

```php
<?php
return [
    // --- Branding ---
    'branding' => [
        'name'         => 'My CI4 App',
        'version'      => '1.0.0',
        'logo'         => 'logo.png',
        'welcome_text' => 'Welcome to the installer for My CI4 App.',
        'colors'       => [
            'primary'   => '#4F46E5',
            'secondary' => '#7C3AED',
            'accent'    => '#F59E0B',
            'neutral'   => '#1F2937',
            'base-100'  => '#FFFFFF',
            'base-200'  => '#F9FAFB',
            'base-300'  => '#E5E7EB',
        ],
    ],

    // --- Download Source ---
    'source' => [
        'composer' => 'vendor/package-name',
        'zip'      => 'https://example.com/releases/latest/app.zip',
    ],

    // --- Server Requirements ---
    'requirements' => [
        'php'        => '8.2',
        'extensions' => ['curl', 'mbstring', 'intl', 'json', 'fileinfo', 'openssl'],
        'databases'  => ['MySQLi'],  // restrict from: MySQLi, Postgre, SQLite3, SQLSRV
    ],

    // --- Writable Directories ---
    'writable_dirs' => [
        'writable/cache',
        'writable/logs',
        'writable/session',
        'writable/uploads',
    ],

    // --- Custom ENV Variables ---
    'env_vars' => [
        [
            'key'      => 'stripe.secretKey',
            'label'    => 'Stripe Secret Key',
            'type'     => 'password',
            'required' => true,
            'group'    => 'Stripe',
            'help'     => 'Find this in your Stripe dashboard under API keys.',
            'default'  => '',
            'validate' => 'regex:/^sk_/',
        ],
        [
            'key'      => 'email.fromEmail',
            'label'    => 'From Email Address',
            'type'     => 'email',
            'required' => false,
            'group'    => 'Email',
            'help'     => 'The email address that outgoing mail will be sent from.',
            'default'  => '',
            'validate' => '',
        ],
    ],

    // --- Post-Install Actions ---
    'post_install' => [
        'migrate'       => true,
        'seed'          => false,
        'seeder_class'  => '',
        'create_admin'  => true,
    ],

    // --- Authentication ---
    'auth' => [
        'system'  => 'shield',
        'collect' => ['username', 'email', 'password'],
        'group'   => 'superadmin',
    ],

    // --- Post-Install ---
    'post_install_url' => '/',
];
```

---

## 11. Build System

### Build Process

`php build/pack.php` performs:

1. Compile Tailwind/DaisyUI against templates → `daisyui.min.css` (purged, minified)
2. Collect all files under `src/` into a zip archive in memory
3. Base64-encode the zip
4. Generate `install.php`:
   - Lock file check
   - Self-extraction logic (~30 lines)
   - Base64 constant at bottom

### Generated install.php Structure

```php
<?php
// CI4 Installer by EnlivenApp
// Creative Commons License — Original script by EnlivenApp
if (file_exists(__DIR__ . '/install.lock')) {
    die('Installation already completed.');
}
$tmp = sys_get_temp_dir() . '/ci4-installer-' . md5(__DIR__);
if (!is_dir($tmp)) {
    $zip = base64_decode(INSTALLER_ARCHIVE);
    file_put_contents($tmp . '.zip', $zip);
    $za = new ZipArchive();
    $za->open($tmp . '.zip');
    $za->extractTo($tmp);
    $za->close();
    unlink($tmp . '.zip');
}
require $tmp . '/Installer.php';
(new Installer(__DIR__))->run();

const INSTALLER_ARCHIVE = '...base64 encoded zip...';
```

### Developer Workflow

1. Work on source in `src/`, test with PHPUnit
2. Run `php build/pack.php` → produces `install.php`
3. Upload `install.php` + customized `installer-config.php` to test server
4. Hit it in a browser, test the wizard
5. Ship both files to end users

---

## 12. Error Handling & Manual Fallbacks

Every automated operation has a fallback. The pattern:

| Operation | Automated | Fallback |
|-----------|-----------|----------|
| Write `.env` | Filesystem abstraction | Show contents in textarea + copy button |
| Create directories | `mkdir()` via abstraction | Show exact paths and permissions needed |
| Download app | Best available HTTP method | Show URL and upload instructions |
| Extract zip | ZipArchive/exec/PharData | Show extraction instructions |
| Run migrations | Bootstrap CI4 programmatically | Show `php spark migrate` command |
| Create admin | Auth adapter | Show SQL or command to run manually |
| Delete installer | `unlink()` via abstraction | Create `install.lock` + show warning |
| DB doesn't exist | `CREATE DATABASE` attempt | Show exact SQL for phpMyAdmin |
| Set permissions | `chmod()` via abstraction | Show exact chmod commands |

Errors are always specific and actionable. Never "something went wrong."

---

## 13. Security Considerations

- FTP/SSH credentials held in session memory only, never written to disk
- HTTPS detection enables secure cookies for session state
- Encryption key generated server-side, never transmitted from external source
- `.env` written with `0600` permissions where possible
- Installer self-deletes after completion; lock file fallback prevents re-execution
- No sensitive values stored in the installer archive itself
- CSRF protection on all wizard form submissions
- Rate limiting on database connection test attempts (prevent credential brute-force if installer is left exposed)

---

## 14. Scope Boundaries

### In Scope
- Environment detection and requirements validation
- App download and extraction
- `.env` configuration and writing
- Database connection, creation, migrations, seeding
- Admin user creation via auth adapters
- Filesystem abstraction with FTP/FTPS/SSH2 support
- Web-based wizard UI with DaisyUI/Alpine.js
- Self-cleanup after installation
- Manual fallbacks for every automated operation

### Out of Scope
- Version updates/upgrades after initial install
- Server-level configuration (Apache/Nginx vhost setup, PHP installation)
- SSL certificate provisioning
- DNS configuration
- Backup/restore
- Multi-site installation
