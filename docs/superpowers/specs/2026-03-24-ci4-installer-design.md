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
- **Idempotent operations.** The installer can run multiple times safely without corrupting state. Each phase detects prior partial completion and resumes or skips as appropriate.
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
| `install.php` | Self-extracting installer — contains all code, templates, CSS, JS packed as a base64-encoded tar.gz archive |
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
2. Decode the base64 constant containing the tar.gz archive
3. Extract using a fallback chain (see below)
4. Extract to `sys_get_temp_dir() . '/ci4-installer-' . md5(__DIR__)`
5. `require` the extracted `Installer.php`
6. Instantiate and run: `(new Installer(__DIR__))->run()`

**Self-extraction fallback chain:**

The archive is packed as tar.gz (not zip) because `PharData` handles tar.gz natively and is available in virtually all PHP installations.

| Priority | Method | Condition |
|----------|--------|-----------|
| 1 | `PharData` | `extension_loaded('phar')` — available in nearly all PHP builds |
| 2 | `exec('tar -xzf ...')` | `exec()` available |
| 3 | Pure PHP tar parser | Built into the stub — ~50 lines of code that reads the tar format directly. No extensions needed. Last resort. |

This ensures the installer can self-extract even on the most minimal PHP installation. The pure PHP tar parser is the ultimate fallback — it reads the standard POSIX tar header format and decompresses with `gzinflate()` (which only needs `ext-zlib`, bundled with PHP by default).

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
| Capabilities | Filesystem method | Ownership test (see Section 5) |
| Database | Available drivers | `extension_loaded()` for mysqli, pgsql, sqlite3, sqlsrv |
| Permissions | Target dir writable | Actually try writing a temp file |
| Network | Outbound HTTP | Try a lightweight request to the download source |
| Protocol | HTTPS active | If yes, enable secure session cookie flags |

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
- For server-based drivers (MySQLi, Postgre, SQLSRV): hostname, port, database name, username, password
- For SQLite3: file path input with writability check. Installer validates the target directory is writable and warns if the path is inside the web root (security risk). Recommends placing the DB file in the `writable/` directory.
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

**Admin user (if auth configured):**
- Username, email, password fields
- Only shown if `auth.system` is not `none`

**Filesystem credentials (if needed):**
- Only shown if direct PHP file operations fail the ownership test
- FTP/FTPS/SSH2 hostname, username, password
- Credentials held in session memory only — never written to disk

### Phase 4: Installation

The actual work. Each substep is an independent AJAX request (or form POST with JS disabled) to manage execution time on shared hosting.

**Execution time management:**

Shared hosting commonly limits `max_execution_time` to 30 seconds. The installer handles this by:

1. Attempting `set_time_limit(0)` at the start of each substep — works on some hosts
2. Splitting Phase 4 into discrete AJAX substeps, each designed to complete within 30 seconds:
   - Substep 4a: Download
   - Substep 4b: Extract
   - Substep 4c: Validate & composer install (if needed)
   - Substep 4d: Write `.env`
   - Substep 4e: Set permissions
   - Substep 4f: Run migrations
   - Substep 4g: Run seeders
   - Substep 4h: Create admin user
3. For large downloads: chunked download with resume capability (track bytes received in session, use HTTP `Range` header to resume)
4. Without JS: each substep is a separate form POST, page reloads between them

The install step UI shows a progress list with checkmarks as each substep completes.

**4a. Download the application:**

Fallback chain in priority order:

| Priority | Strategy | Condition |
|----------|----------|-----------|
| 1 | `composer create-project` | `exec()` available + composer found |
| 2 | `git clone` | `exec()` available + git found |
| 3 | cURL zip download | `extension_loaded('curl')` |
| 4 | `file_get_contents` zip download | `allow_url_fopen` enabled |
| 5 | Manual upload | Everything fails — show URL and instructions |

Note: FTP/SSH are filesystem transport drivers (for writing files to the local server), not download methods. They are not used for downloading the application from a remote source.

**4b. Extract and normalize directory structure:**

For zip downloads, extraction fallback chain:
1. `ZipArchive` class — `class_exists('ZipArchive')`
2. `exec('unzip ...')` if exec available
3. `PharData` — `extension_loaded('phar')` (handles zip and tar, available in most PHP builds)
4. Manual fallback — show instructions

**Directory normalization:** GitHub release zips and `git clone` both create a nested directory (e.g., `pubvana-1.0.0/` or `pubvana/`). After extraction, the installer:

1. Lists the top-level contents of the extraction directory
2. If there is exactly one subdirectory and no files → it's a nested archive. Move all contents of that subdirectory up one level into the target directory, then remove the empty subdirectory.
3. If there are multiple items at the top level → contents are already flat, no normalization needed.
4. `composer create-project` creates a named subdirectory by design — the installer passes the target path directly to avoid nesting.

**4c. Validate the download:**
- Check for `vendor/autoload.php`, `spark`, `app/Config/App.php`
- If `vendor/` missing + composer available → run `composer install --no-dev`
- If `vendor/` missing + no composer → fail with message: release zip must include `vendor/`

**4d. Write `.env` file:**
1. Read the app's `env` template
2. Merge with auto-detected values (baseURL, DB credentials, encryption key)
3. Merge with user-provided values from the wizard
4. Write via filesystem abstraction
5. Fallback: display full `.env` contents in a textarea with copy button and instructions
6. If manual fallback used — poll/check for file existence before allowing next step

**4e. Set directory permissions:**
- Create and chmod writable directories defined in config
- Permission mode specified per directory in config (defaults to `0755` for directories)
- Via filesystem abstraction (direct/FTP/SSH)
- Fallback: show the exact directories and permissions needed

**4f. Handle CI4's `public/` directory convention:**

CI4 applications serve from a `public/` subdirectory. The installer addresses this based on the server environment:

1. If the installer detects it's running inside a directory that already IS the document root (e.g., `public_html/` on shared hosting), the app needs to be extracted so that `public/` contents merge into the document root, or a root-level `.htaccess` rewrites to `public/`. The installer writes a root `.htaccess` that routes all requests to `public/index.php`.
2. If the developer's release zip is already restructured for shared hosting (flat structure, no `public/` separation), no action needed — the installer detects this by checking whether `index.php` exists at the root vs. in `public/`.
3. The config can specify this behavior explicitly via `public_dir_handling`.

### Phase 5: Database Setup

Bootstrap CI4 programmatically — no shell required.

**Migration runner using CI4's `util_bootstrap.php`:**

CI4 provides `vendor/codeigniter4/framework/system/util_bootstrap.php` specifically for running framework services from external scripts without a full HTTP request. The installer uses this:

```
1. Define required constants: ROOTPATH, APPPATH, WRITABLEPATH, etc.
2. require ROOTPATH . 'vendor/codeigniter4/framework/system/util_bootstrap.php'
3. $runner = service('migrations');
4. $runner->latest();
```

This is the documented CI4 approach for external script integration. It boots the Services container, loads configuration, and makes all framework classes available.

**Fallback chain:**
1. Programmatic CI4 bootstrap via `util_bootstrap.php` (above)
2. `exec('php spark migrate')` if exec available
3. Show error with guidance: "Run `php spark migrate` via SSH, or contact your hosting provider"

**Seeders** — same pattern: bootstrap CI4, `$seeder->call('ClassName')`.

**Admin user creation — auth adapter system:**

Config specifies the auth system. Admin creation is driven solely by the `auth.system` config value — if it's `none`, no admin step is shown regardless of other settings.

**Auth adapter mapping:**

| Config Value | Adapter Class | Method |
|-------------|---------------|--------|
| `shield` | ShieldAdapter | Bootstrap CI4 → Shield's `UserModel` → `addGroup()` |
| `ion_auth` | IonAuthAdapter | Bootstrap CI4 → IonAuth `register()` → group assignment |
| `myth_auth` | MythAuthAdapter | Bootstrap CI4 → Myth:Auth user entity → permissions |
| `custom` | GenericAdapter | Direct DB insert with `password_hash()`, dev-configured table/fields |
| `none` | NoneAdapter | Skip — app handles first-run registration |

Adapters use CI4's own classes after bootstrapping, respecting the auth library's hashing, validation, and event triggers. `GenericAdapter` is the escape hatch — dev specifies table, fields, and hashing method in config.

### Phase 6: Cleanup

1. **Self-delete** `install.php` via filesystem abstraction
2. **Fallback:** create `install.lock` file if delete fails
3. Remove temp extraction directory
4. Show success screen with link to the app
5. If lock file was created instead of delete — show security warning: "Delete install.php from your server for security"

### State Recovery (Idempotent Resume)

If the installer is interrupted and re-run, each phase detects prior partial completion:

| Phase | Resume Detection |
|-------|-----------------|
| Download | `vendor/autoload.php` exists → skip download |
| Extract | Target directory contains CI4 app structure → skip extraction |
| `.env` write | `.env` file exists and contains expected keys → skip or offer to overwrite |
| Migrations | CI4's `migrations` table exists and is current → skip migrations |
| Seeding | Dev-specified check (e.g., row count in seeded table) or always re-run (seeders should be idempotent) |
| Admin user | Query for existing admin in the configured group → skip if found |

The installer never blindly re-runs a completed phase. It checks, reports what it found, and asks the user whether to skip or redo.

---

## 5. Filesystem Abstraction

Four drivers implementing a common interface. Auto-detected by empirical ownership test.

### Why Ownership Matters

On shared hosting, PHP can run in different modes that affect file ownership:

- **suPHP / PHP-FPM per-user:** PHP runs as the hosting account user. Files created by PHP are owned by the user. Direct file operations work perfectly — the user can manage these files via FTP/file manager later. → **DirectDriver**
- **mod_php / shared FPM pool:** PHP runs as the web server user (e.g., `www-data`, `nobody`). Files created by PHP are owned by the web server, not the user. The user can't manage these files via FTP, and future permission issues arise. → **FTP/SSH drivers needed** so files are created with correct ownership.

The ownership test detects this: write a temp file, compare `fileowner()` of that file with `getmyuid()`. If they match, the hosting runs PHP as the user and DirectDriver is safe.

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

Both HTTPS and non-HTTPS paths use PHP's native session mechanism (`$_SESSION`). The difference is cookie security:

- **HTTPS detected:** Session cookie set with `Secure`, `HttpOnly`, and `SameSite=Strict` flags
- **No HTTPS:** Session cookie set with `HttpOnly` and `SameSite=Strict` flags (no `Secure` flag, as it would prevent the cookie from being sent)

Sensitive values (DB password, FTP credentials) are held in the session only — never written to disk outside of PHP's session storage.

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
- **SQLite3:** `new SQLite3($path)` — test file creation/write at the specified path. Validate target directory is writable. Warn if path is inside the web root.
- **SQL Server:** `sqlsrv_connect()`

Specific error reporting: "Server unreachable" vs "Bad credentials" vs "Database doesn't exist" — never generic "connection failed."

If database doesn't exist, offer to create it. If create fails, show the exact SQL for manual execution in phpMyAdmin or host's DB tool.

### Rate Limiting on Connection Tests

To prevent brute-force credential testing if the installer is left exposed, connection test attempts are rate-limited via session counter: maximum 10 attempts per 5-minute window. After the limit, the test button is disabled with a countdown timer. This is per-session, not per-IP, since session is the only reliable state mechanism available before the app is installed.

---

## 7. Source Download

### Download Strategies

| Priority | Strategy | Condition |
|----------|----------|-----------|
| 1 | `composer create-project` | `exec()` + composer found |
| 2 | `git clone` | `exec()` + git found |
| 3 | cURL download | `extension_loaded('curl')` |
| 4 | `file_get_contents` | `allow_url_fopen` enabled |
| 5 | Manual | Everything fails — show URL and instructions |

Note: FTP/SSH are filesystem transport drivers for local file operations, not remote download methods. They do not appear in the download chain.

### Zip Extraction Strategies

| Priority | Strategy | Condition |
|----------|----------|-----------|
| 1 | `ZipArchive` | `class_exists('ZipArchive')` |
| 2 | `exec('unzip')` | `exec()` available |
| 3 | `PharData` | `extension_loaded('phar')` — available in most PHP builds |
| 4 | Manual | Show instructions |

### Directory Normalization

GitHub release zips and `git clone` create a nested top-level directory. After extraction:

1. List top-level contents of extraction directory
2. If exactly one subdirectory and no files → nested archive. Move contents up, remove empty subdirectory.
3. Multiple top-level items → already flat, no action needed.
4. `composer create-project` → installer passes target path directly to avoid nesting.

### Post-Download Validation

- Check for `vendor/autoload.php`, `spark`, `app/Config/App.php`
- Missing `vendor/` + composer available → run `composer install --no-dev`
- Missing `vendor/` + no composer → fail with clear message about release zip requirements

### Developer Config

```php
'source' => [
    'composer' => 'vendor/package-name',
    'git'      => 'https://github.com/org/repo.git',
    'zip'      => 'https://example.com/releases/latest/app.zip',
],
```

All three values can be provided. Installer picks the best strategy based on server capabilities. At minimum, `zip` should always be provided as the universal fallback.

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
| 7 | Admin Account | Username, email, password. Only shows if `auth.system` is not `none`. |
| 8 | Install | Progress display — substeps with checkmarks as each completes. AJAX-driven with JS, sequential form POSTs without. |
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

### Adapter Mapping

| Config `auth.system` Value | Adapter Class | Auth Library |
|---------------------------|---------------|-------------|
| `shield` | ShieldAdapter | CodeIgniter Shield |
| `ion_auth` | IonAuthAdapter | IonAuth |
| `myth_auth` | MythAuthAdapter | Myth:Auth |
| `custom` | GenericAdapter | Any — dev-configured |
| `none` | NoneAdapter | N/A — skip admin creation |

### Config — Standard Auth Libraries

```php
'auth' => [
    'system'  => 'shield',
    'collect' => ['username', 'email', 'password'],
    'group'   => 'superadmin',
],
```

### Config — Generic/Custom Auth

For apps using auth systems the installer doesn't have a dedicated adapter for:

```php
'auth' => [
    'system'         => 'custom',
    'collect'        => ['email', 'password'],
    'table'          => 'users',
    'fields'         => [
        'email'      => 'email',         // collect field → DB column
        'password'   => 'password_hash',  // collect field → DB column
    ],
    'hash_method'    => 'PASSWORD_DEFAULT',  // PHP password_hash() algorithm
    'extra_inserts'  => [                    // additional columns to set
        'role'   => 'admin',
        'active' => 1,
    ],
],
```

---

## 10. Configuration File Reference

### Validation Rules

The installer validates `installer-config.php` on load. Validation failures show a developer-facing error (not the end-user wizard).

**Required keys:**
- `branding.name` — string, non-empty
- `source` — array, must contain at least `zip`
- `requirements.php` — valid version string (e.g., `'8.2'`)

**Optional keys with defaults:**
- `branding.version` — default `'1.0.0'`
- `branding.logo` — default: no logo
- `branding.welcome_text` — default: auto-generated from app name
- `branding.colors` — default: installer's built-in color scheme
- `source.composer` — no default (skipped if absent)
- `source.git` — no default (skipped if absent)
- `requirements.extensions` — default: `[]` (no additional extensions required beyond CI4 core)
- `requirements.databases` — default: all four drivers offered
- `writable_dirs` — default: `['writable/cache', 'writable/logs', 'writable/session', 'writable/uploads']`
- `writable_dir_permissions` — default: `0755`
- `env_vars` — default: `[]` (no custom vars)
- `post_install.migrate` — default: `true`
- `post_install.seed` — default: `false`
- `post_install.seeder_class` — required if `seed` is `true`
- `auth.system` — default: `'none'`
- `auth.collect` — required if `auth.system` is not `'none'`
- `auth.group` — required for `shield`, `ion_auth`, `myth_auth`
- `auth.table`, `auth.fields`, `auth.hash_method` — required if `auth.system` is `'custom'`
- `public_dir_handling` — default: `'auto'` (detect and handle). Options: `'auto'`, `'htaccess'` (write root .htaccess), `'none'` (app handles it)
- `post_install_url` — default: `'/'`

**Validation errors:**
- Missing required key → "installer-config.php is missing required key: {key}"
- Invalid type → "installer-config.php: {key} must be {type}, got {actual}"
- Invalid `auth.system` value → "installer-config.php: auth.system must be one of: shield, ion_auth, myth_auth, custom, none"
- `seed` is true but no `seeder_class` → "installer-config.php: post_install.seeder_class is required when seed is true"

### Full Example

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
        'git'      => 'https://github.com/org/repo.git',
        'zip'      => 'https://example.com/releases/latest/app.zip',
    ],

    // --- Server Requirements ---
    'requirements' => [
        'php'        => '8.2',
        'extensions' => ['curl', 'mbstring', 'intl', 'json', 'fileinfo', 'openssl'],
        'databases'  => ['MySQLi'],  // restrict from: MySQLi, Postgre, SQLite3, SQLSRV
    ],

    // --- Writable Directories ---
    'writable_dirs'            => [
        'writable/cache',
        'writable/logs',
        'writable/session',
        'writable/uploads',
    ],
    'writable_dir_permissions' => 0755,

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
        'seed'          => true,
        'seeder_class'  => 'App\\Database\\Seeds\\DefaultSeeder',
    ],

    // --- Authentication ---
    'auth' => [
        'system'  => 'shield',
        'collect' => ['username', 'email', 'password'],
        'group'   => 'superadmin',
    ],

    // --- CI4 Public Directory Handling ---
    'public_dir_handling' => 'auto',

    // --- Post-Install Redirect ---
    'post_install_url' => '/',
];
```

---

## 11. Build System

### Build Process

`php build/pack.php` performs:

1. Compile Tailwind/DaisyUI against templates → `daisyui.min.css` (purged, minified)
2. Collect all files under `src/` into a tar.gz archive in memory
3. Base64-encode the tar.gz
4. Generate `install.php`:
   - Lock file check
   - Self-extraction logic with fallback chain (PharData → exec tar → pure PHP parser)
   - Base64 constant at bottom

Note: The `const INSTALLER_ARCHIVE = '...'` is declared after executable code. PHP resolves global `const` declarations at compile time, so this ordering works correctly. It is intentional — keeps the large base64 blob at the bottom of the file for readability.

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
    $archive = base64_decode(INSTALLER_ARCHIVE);
    $archivePath = $tmp . '.tar.gz';
    file_put_contents($archivePath, $archive);
    mkdir($tmp, 0755, true);

    // Extraction fallback chain
    $extracted = false;

    // 1. Try PharData (available in most PHP builds)
    if (!$extracted && extension_loaded('phar')) {
        try {
            $phar = new PharData($archivePath);
            $phar->extractTo($tmp);
            $extracted = true;
        } catch (Exception $e) {}
    }

    // 2. Try exec('tar')
    if (!$extracted && function_exists('exec')) {
        exec("tar -xzf " . escapeshellarg($archivePath) . " -C " . escapeshellarg($tmp), $out, $ret);
        if ($ret === 0) $extracted = true;
    }

    // 3. Pure PHP tar.gz reader (last resort)
    if (!$extracted) {
        // ~50 lines: gzdecode + POSIX tar header parser
        // Built into the stub at build time
    }

    unlink($archivePath);

    if (!$extracted) {
        die('Could not extract installer archive. Please contact support.');
    }
}

require $tmp . '/Installer.php';
(new Installer(__DIR__))->run();

const INSTALLER_ARCHIVE = '...base64 encoded tar.gz...';
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
- HTTPS detection enables `Secure` flag on session cookies
- All session cookies set with `HttpOnly` and `SameSite=Strict`
- Encryption key generated server-side, never transmitted from external source
- `.env` written with `0600` permissions where possible
- Installer self-deletes after completion; lock file fallback prevents re-execution
- No sensitive values stored in the installer archive itself
- CSRF protection on all wizard form submissions
- Rate limiting on database connection test attempts: 10 attempts per 5-minute window, session-based counter (prevents brute-force if installer is left exposed)
- SQLite database path validated to be outside web root when possible

---

## 14. Scope Boundaries

### In Scope
- Environment detection and requirements validation
- App download and extraction with directory normalization
- `.env` configuration and writing
- Database connection, creation, migrations, seeding (all four CI4 drivers)
- Admin user creation via auth adapters (Shield, IonAuth, Myth:Auth, Generic, None)
- Filesystem abstraction with FTP/FTPS/SSH2 support
- CI4 `public/` directory handling for shared hosting
- Web-based wizard UI with DaisyUI/Alpine.js
- Execution time management via AJAX substeps
- Self-cleanup after installation
- Idempotent resume after interruption
- Manual fallbacks for every automated operation

### Out of Scope
- Version updates/upgrades after initial install
- Server-level configuration (Apache/Nginx vhost setup, PHP installation)
- SSL certificate provisioning
- DNS configuration
- Backup/restore
- Multi-site installation
