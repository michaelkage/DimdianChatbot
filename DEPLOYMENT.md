# DIMDIAN Support Deployment and Rollback Runbook

This runbook is for the PHP/MySQL shared-hosting deployment. The repository CI environment is not a substitute for testing the actual Hostinger account.

## Before changing production

1. Schedule a quiet window and notify support staff.
2. Record the current release identifier and document-root setting.
3. Back up the production database with `php bin/backup-db.php` or phpMyAdmin.
4. Back up `config.php`, the WooCommerce API credentials, the vehicle encryption key, and the private uploads directory separately. Keep copies encrypted and offline; do not put them in Git.
5. Confirm the backup can be restored into a separate test database.
6. Keep the currently deployed code directory intact as the rollback release.

**Important:** Losing `integration.vehicle_data_encryption_key` makes previously encrypted vehicle identifiers unreadable. A key change requires a planned re-encryption migration; do not simply replace the key.

## Deploy

1. Upload the new release into a separate directory rather than overwriting the active release in place.
2. Keep the private `config.php` and private upload/backup directories outside the public document root. Reuse the existing config after adding the new WooCommerce, backup and vehicle-encryption settings.
3. Confirm the web document root points to the release's `public/` directory and HTTPS is active.
4. Run the additive migration before switching the site to the new code:

   ```sh
   mysql -u DB_USER -p DB_NAME < database/migrations/2026-11-17-marketplace-fitment-hardening.sql
   ```

   For a fresh database, import `database/schema.sql` first. The migration is idempotent and creates only new tables.
5. Switch the document root to the new release's `public/` directory. If Hostinger does not allow document-root switching, upload the public files during the quiet window after taking a backup.
6. Run `php bin/preflight.php` over SSH and resolve every failure. Confirm the WooCommerce API test succeeds when the catalogue is enabled.
7. Verify the health endpoint, staff login, customer message send/reply, image upload/retrieval, vehicle submission, product search and the staff inbox.
8. Test a known SKU and product name against the live WooCommerce catalogue. Test MPN only after the WordPress lookup plugin is installed and its exact product meta key is configured.
9. Test one verified fitment and one unmatched vehicle. The unmatched case must reach the human queue.
10. Keep AI disabled until the provider probe succeeds against the real provider. Then enable it deliberately and test a harmless FAQ plus the human escalation paths.
11. Keep the previous release and pre-deploy backup until the new release has completed an agreed observation period.

## Scheduled maintenance

Configure a daily Hostinger cron task to run `php /absolute/path/to/DimdianChatbot/bin/cleanup-rate-limits.php`. This removes expired rate-limit buckets outside the customer request path. Confirm the cron uses the same private `config.php` and database as the application.

## Rollback

1. Disable AI in the staff inbox and tell staff to handle new chats manually.
2. Switch the document root back to the previous release, or restore the previous application files.
3. **Do not automatically roll back the database.** This migration is additive; the old application can leave the new tables unused. Keeping the new tables avoids losing conversations created after deployment.
4. If the database itself is corrupt, pause customer writes and restore the pre-deploy database backup. This discards all database changes made after that backup, so export any newer conversations first if possible.
5. Restore the matching `config.php` and private uploads backup only if they were changed or damaged. Preserve the exact vehicle-encryption key used for the database being restored.
6. Verify health, staff login, customer messaging and attachment retrieval before reopening traffic.
7. Record the failure and do not retry the same release until the cause is understood.

## Hostinger-specific validation

On the actual account, verify PHP 8.2+, PDO MySQL, cURL, fileinfo and OpenSSL; HTTPS; writable private storage; the configured upload limits; database privileges for migrations and backups; and whether SSH exposes `mysql`/`mysqldump`. If command-line database tools are unavailable, use phpMyAdmin export/import. No production deployment or Hostinger test is implied by passing GitHub Actions.
