# VirakCloud Backup & Migrate for WordPress

Backup, restore, and migrate WordPress sites to VirakCloud S3-compatible object storage. Manual and scheduled backups, integrity checks, retention policies, and WP‑CLI.

## Features

- Full, DB-only, files-only, and incremental (manifest-based) backups
- S3-compatible storage via AWS SDK with multipart uploads
- Schedules: 2h, 4h, 8h, 12h, daily, weekly, fortnightly, monthly
- One-click restore (with dry-run) and basic migration (search/replace including serialized data)
- Structured logs with downloadable view, status panel, i18n-ready

## Requirements

- WordPress 6.x, PHP 8.1+, MySQL/MariaDB

## Install

1. Clone this plugin into `wp-content/plugins/virakcloud-backup`
2. Run `composer install` inside the plugin directory
3. Activate in WP Admin → Plugins
4. Configure S3 in WP Admin → VirakCloud Backup → Settings

## Build & Test

```bash
composer install
composer lint
composer stan
composer test
composer build-zip
```

The build will create a distributable zip in `dist/`.

## WP‑CLI

```bash
wp vcbk backup --type=full
wp vcbk schedule list
wp vcbk restore --file=/path/to/archive.zip [--dry-run]
wp vcbk migrate --from=https://old.example.com --to=https://new.example.com
```

## Cron

For reliability, configure a real cron to trigger WP-Cron:

```
*/5 * * * * wget -q -O - https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

## Security

- Nonces and capability checks for admin actions
- Secrets scrubbed from logs
- Optional encryption support when available (libsodium/OpenSSL) – future iterations will add client-side archive encryption

## Troubleshooting

- If you see a notice about missing Composer dependencies, run `composer install` in the plugin folder.
- Large sites: adjust PHP memory/timeouts or use `tar.gz` archives.

