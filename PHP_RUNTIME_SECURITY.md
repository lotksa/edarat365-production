# PHP runtime security release policy

PHP 8.5.12 remains the supported stable source release as reviewed on 27 October
2026. The `8.5.12-1` announcement is a Windows dependency rebuild, not a Linux
Docker tag or a new PHP source release. Do not use a release candidate on production.

## Required controls

- PHP must be in the supported 8.5 branch, at least 8.5.12.
- Keep `session.upload_progress.enabled=0` at process startup, including the
  cPanel FPM pool override. Application-level `ini_set()` is insufficient.
- Verify the Brotli decoder linked by PHP/cURL, not only PHP's version string.
  Brotli upstream 1.2.1 fixes CVE-2026-111497. Debian 13 backports that fix in
  `libbrotli1` version `1.1.0-2+deb13u1`.
- The read-only `backend/tools/check-php-runtime.sh` gate checks CLI startup,
  resolves cURL's decoder, checks package integrity and requires the reviewed
  Debian floor or an RPM with upstream 1.2.1+/recorded CVE backport evidence.
  Unsupported or unidentifiable native decoders fail the gate.
- This gate verifies package evidence. It does not replace auditing a vendor
  backport, checking the active web SAPI/pool, or restarting stale workers after
  a native-library update. Do not use a fake HTTP client as proof of native safety.

## Reviewed deployment

The current AlmaLinux/cPanel installation has PHP 8.5.12 and local security
backports in `brotli-1.0.9-10.el9_8.0.1.lotsecurity` and
`ea-brotli-1.0.9-2.2.11.cpanel.0.1.lotsecurity`. Both RPM changelogs record
upstream fix `50a9b70` for CVE-2026-111497. Confirm RPM integrity and active worker
library mappings on the server; the package names alone are not proof.

For each release, follow `PROJECT_RULES.md`: verify current cPanel paths and Git
state, create and validate fresh database/upload backups outside deployment
directories, run the read-only gate, deploy only the reviewed code paths, then
check the cPanel log, HTTP health, assets and data/upload fingerprints. Never
run migrations, seeds, cleanup commands, or overwrite `.env` for this gate.

Do not disable legitimate gzip/deflate/Brotli HTTP responses when the native
decoder is already patched. AI, Turnstile and SMS clients retain their existing
HTTP behavior.

## Primary references

- https://www.php.net/archive/2026.php#2026-10-26-1
- https://www.php.net/archive/2026.php#2026-10-23-1
- https://github.com/google/brotli/releases/tag/v1.2.1
- https://lists.debian.org/debian-security-announce/2026/msg00498.html

Run locally: `docker exec edarat365-backend sh tools/check-php-runtime.sh`.
On cPanel: `PHP_BIN=/opt/cpanel/ea-php85/root/usr/bin/php sh
/home1/edarat/laravel-app/tools/check-php-runtime.sh`.
