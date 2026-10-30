# Changelog

## Unreleased

### Security
- `SessionManager::regenerateId()` no longer copies session data to the old ID during the
  grace period. Only a tombstone is stored; a request with the old ID gets a fresh empty session.

### Added
- CSRF token pool: up to `csrfTokenPoolSize` (default 10) outstanding tokens, each valid for
  `csrfTokenTtl` seconds (default 7200, 0 = no TTL). New `SessionManager::consumeCsrfToken()`
  for single-use validation.
- `DatabaseStorage::getLastError()` and `DatabaseStorage::getCreateTableStatements()`.

### Fixed
- `DatabaseStorage::createTable()` is driver-aware (mysql, sqlite, pgsql) and creates indexes with
  separate `CREATE INDEX IF NOT EXISTS` statements outside MySQL. Failures are logged via
  `error_log()` and available from `getLastError()` instead of being swallowed silently.
- README documents the real `CookieManager` constructor argument order.

### Backward-compatibility notes
- **PHP >= 8.3 is now required** (was ^8.1).
- `DatabaseStorage` no longer permanently sets `PDO::ERRMODE_EXCEPTION` on the PDO you pass in;
  it applies it per operation and restores your mode. Code that relied on the side effect must
  set the error mode itself.
- With `regenerateGracePeriod > 0`, concurrent requests still carrying the old cookie now get an
  empty session instead of the previous data.
- CSRF hashes are now stored under `_csrf_tokens` (array) instead of `_csrf_token`. Hashes already
  stored under the legacy key in existing sessions are still accepted.
- New constructor parameters on `SessionManager` are appended with defaults; existing calls work.
