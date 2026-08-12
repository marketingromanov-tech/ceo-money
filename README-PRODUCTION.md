# CEO Money — production security baseline

This document prepares deployment; it is not a production deployment manifest. Keep Laravel Sail `compose.yaml` for local development only.

## Runtime and web root

- PHP 8.2+ with the extensions required by Laravel, PDO MySQL, mbstring, OpenSSL, tokenizer, XML, ctype and JSON.
- Point the web server document root exclusively at `public/`. Disable directory listing.
- `.env`, `composer.json`, database files, `storage/` and logs must remain outside the served web root.
- The web user needs write access only to `storage/` and `bootstrap/cache/`.

## Required environment baseline

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<production-domain>
APP_KEY=<generate-once-and-store-in-secret-manager>
LOG_CHANNEL=stack
LOG_LEVEL=warning
SESSION_DRIVER=database
SESSION_LIFETIME=60
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=null
SESSION_PARTITIONED_COOKIE=false
SECURITY_HEADERS_ENABLED=true
CSP_REPORT_ONLY=true
TRUSTED_PROXIES=<known-proxy-ip-or-cidr>[,<another-known-proxy>]
```

Generate `APP_KEY` once in the protected production environment and back it up securely. Never commit it. Rotation after data exists invalidates sessions and may make encrypted MFA secrets unreadable unless `APP_PREVIOUS_KEYS` is managed during a controlled rotation.

## HTTPS and reverse proxy

Redirect HTTP to HTTPS at the load balancer/web server. Forward `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Port` and `X-Forwarded-Proto`. Configure `TRUSTED_PROXIES` with only known proxy/load-balancer addresses or CIDRs; never use a blanket trust value. Health checks may remain internal HTTP and no application redirect is imposed.

Production responses add nosniff, strict referrer policy, restricted permissions policy, `X-Frame-Options: DENY`, frame-ancestors protection and HSTS on securely recognized requests. CSP initially uses report-only mode:

```
default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; font-src 'self' data:; connect-src 'self' ws: wss:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'
```

`unsafe-inline` is temporarily required by existing Blade inline handlers/scripts and Alpine/Livewire expressions. Collect violation reports, migrate inline JavaScript to nonce/hash-compatible assets, then remove it before switching `CSP_REPORT_ONLY=false`.

## Database and operations

- Never expose MySQL port 3306 publicly. Bind it to an internal/private network.
- Use a separate least-privileged application DB user and protected credentials. Prefer separate credentials for backups.
- Before release: maintenance mode, backup, deploy code, run `php artisan migrate --force`, clear/rebuild caches, run production check, then restore traffic.
- Rollback must account for forward database compatibility; do not rotate `APP_KEY` as part of rollback.
- `DatabaseSeeder` exits without creating demo credentials outside local/testing.

## MFA and checks

Every active admin must configure MFA. Run:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan app:production-check
```

The command is read-only, prints no secrets and exits non-zero for production blockers.

## Queue and scheduler

No application schedule is currently registered in `routes/console.php`; therefore no accrual cron requirement was found. Critical financial flows and current database notifications execute synchronously. The database queue tables exist, but a worker is not required until queued jobs are introduced. Reassess both conclusions whenever scheduled commands or `ShouldQueue` jobs are added.

## Database backups

CEO Money includes private CLI-only database backup, verification, retention cleanup, and isolated restore-test commands. Production must provide compatible `mysqldump` and `mysql` clients and a dedicated restore-test database. Configure monitoring and an external scheduler; do not expose these operations through HTTP. The complete operating and disaster-recovery procedure is in [README-BACKUP-RESTORE.md](README-BACKUP-RESTORE.md).
