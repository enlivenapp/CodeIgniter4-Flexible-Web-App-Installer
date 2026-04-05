# Changelog

All notable changes to the CodeIgniter 4 Flexible Web App Installer will be documented in this file.

## [1.1.0] - 2026-04-05

### Removed
- **IonAuth adapter** — removed `IonAuthAdapter.php`; only Shield, custom, and none remain
- **MythAuth adapter** — removed `MythAuthAdapter.php`
- **Composer source** — removed `ComposerSource.php`; zip and stream sources cover all use cases
- **Git source** — removed `GitSource.php`
- **Composer/Git detection** — removed from `Detector` and `Requirements` (no longer needed without those sources)

### Changed
- **ShieldAdapter** — refactored SQL building to determine quote style once upfront instead of duplicating per driver branch; renamed `executeInsertGetId` → `insertAndGetId`, `executeInsert` → `insertRow`; consolidated `tableExists` PDO branches; expanded `$dbCredentials` docblock
- **AuthAdapterFactory** — minor alignment cleanup
- **Installer** — streamlined install flow
- **CurlSource / StreamSource** — simplified download logic
- **SourceFactory** — reduced to zip-only source resolution
- **MigrationRunner** — simplified
- **ConfigValidator / EnvWriter** — minor fixes
- **Build script** — updated `pack.php`
- **UI templates** — refinements across all steps
- **README** — updated for current feature set

## [1.0.0] - 2026-04-04

Initial release.

- Step-by-step web wizard for CI4 app installation
- Works on shared hosting with no CLI, SSH, or Composer access
- Apache, Nginx, LiteSpeed, and IIS support
- MySQLi, PostgreSQL, SQLite3, and SQL Server database drivers
- Filesystem abstraction: Direct PHP, FTP, FTPS, SSH2
- Graduated fallback chains for every operation
- DaisyUI + Alpine.js UI
- Automatic server capability detection
- Self-deleting installer after completion
- Single `install.php` self-extracting build via `pack.php`
