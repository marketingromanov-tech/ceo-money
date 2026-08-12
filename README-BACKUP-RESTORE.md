# CEO Money database backup and restore runbook

Database backups are operational artifacts, not public downloads. The application writes them to `storage/app/private/backups/database` through the private `backup` filesystem disk. The web UI only lists metadata and provides no create, download, delete, or restore action.

## Configuration

```dotenv
BACKUP_DISK=backup
BACKUP_RETENTION_DAYS=30
MYSQLDUMP_BINARY=mysqldump
MYSQL_BINARY=mysql
BACKUP_RESTORE_TEST_DATABASE=ceo_money_restore_test
APP_COMMIT=<deployed-git-sha>
```

The runtime user needs read/write access to the private backup directory. The database user used for backup needs the MySQL privileges required by `mysqldump --single-transaction --triggers --routines`; restore-test additionally needs permission to create and drop only the dedicated test database. Prefer separate least-privileged operational credentials in production.

Never expose the backup directory through the web server, object-storage public ACLs, Sail file sharing, or support downloads. Store off-host copies in encrypted private storage with restricted operator access and retention controls.

## Create, verify, retain

Run from the application runtime (with the MySQL client tools installed):

```bash
php artisan backup:database --verify
php artisan backup:verify <backup-record-id>
php artisan backup:cleanup
```

`backup:database` streams `mysqldump` directly into gzip, then atomically renames the temporary file. The database password is supplied to the child process through `MYSQL_PWD`; it is not included in command arguments, output, metadata, or audit payloads. A SHA-256 digest and byte size are stored in `backup_records`.

Schedule the verified backup command with the platform scheduler or cron, not an HTTP endpoint. A typical daily invocation is:

```cron
15 2 * * * cd /srv/ceo-money && php artisan backup:database --verify --quiet
```

Monitor its exit code and alert on failure. Run `backup:cleanup` separately after successful off-host replication. Retention deletion is limited to files referenced by records inside the configured private backup root.

## Isolated restore drill

The restore-test command always verifies the file first and refuses to target the current application database:

```bash
php artisan backup:restore-test <backup-record-id>
```

It recreates only `BACKUP_RESTORE_TEST_DATABASE`, imports the archive, checks required schema and migrations, reports key table counts, checks duplicate financial source references, and confirms stored MFA ciphertext values remain non-empty strings. It drops the isolated database after the test; `--keep` is available only for a controlled investigation. In production the command also requires `--force`.

Use a database name and credentials dedicated to restore drills. Never point this setting at production, staging, a developer database, or a shared schema. A passing drill validates recoverability of the SQL artifact; it does not replace application smoke tests or reconciliation by finance staff.

## Controlled real restore

There is deliberately no browser or application command that overwrites the live database. A real restore is a privileged incident operation:

1. Declare an incident and identify the recovery point and responsible approvers.
2. Stop traffic, workers, schedulers, and all writers; enable maintenance mode.
3. Capture and verify a final pre-restore backup when the database is readable.
4. Run `backup:verify` and an isolated `backup:restore-test` against the selected artifact.
5. Confirm the target host/database twice. Create a new empty recovery database rather than importing over the live schema.
6. Import with the native MySQL client using credentials from the secret manager. Do not put passwords in shell history or process arguments.
7. Point a maintenance-only application instance at the recovery database using the original `APP_KEY` (and required `APP_PREVIOUS_KEYS`). Run read-only smoke checks and financial reconciliation.
8. Switch production only after explicit approval. Keep the former database intact for rollback until the incident is closed.
9. Run login/MFA, investor ownership, deposit, withdrawal, lot, transaction, accrual, notification, and audit-history checks before reopening traffic.
10. Record timestamps, backup ID/SHA-256, operators, approvals, counts, and reconciliation results. Never store credentials or decrypted MFA values in the incident record.

Rollback is a controlled connection switch back to the untouched former database. Do not run reverse migrations or rotate `APP_KEY` during the recovery window.
