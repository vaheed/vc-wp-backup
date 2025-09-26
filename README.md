# VirakCloud Backup & Migrate for WordPress

Backup, restore, and migrate WordPress sites to VirakCloud S3‑compatible object storage. Manual and scheduled backups, integrity checks, retention policies, and WP‑CLI. Redesigned admin UI for a clean, responsive, and professional experience.

## Features

- Full, DB-only, files-only, and incremental (manifest-based) backups
- S3-compatible storage via AWS SDK with multipart uploads
- Schedules: 2h, 4h, 8h, 12h, daily, weekly, fortnightly, monthly
- One-click restore (with dry-run) and basic migration (search/replace including serialized data)
- Structured logs with live tail + filters (All/Info/Debug/Errors)
- Modern, responsive admin UI (cards, progress stepper, drag‑and‑drop uploads)
- Status panel with badges, recent backups with quick actions
- i18n‑ready with updated POT/PO/MO files

## Requirements

- WordPress 6.x, PHP 8.1+, MySQL/MariaDB

## Install

1. Clone this plugin into `wp-content/plugins/virakcloud-backup`
2. Run `composer install` inside the plugin directory
3. Activate in WP Admin → Plugins
4. Configure S3 in WP Admin → VirakCloud Backup → Settings
5. Optional: regenerate translations: `composer i18n:pot` and merge with `msgmerge`

Upgrade from 1.0.1 → 1.0.2: no breaking changes. The setup wizard was removed (unused); settings are available from the Settings page.

## Build & Test

```bash
composer install
composer lint
composer stan
composer test
composer build-zip
```

The build will create a distributable zip in `dist/`.

To regenerate translation template:

```
composer i18n:pot
```

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

## What’s New (1.0.2)

- Redesigned, responsive admin UI inspired by modern SaaS dashboards
- Live logs with tabbed filters and copy/download actions
- Progress stepper (Archiving → Uploading → Complete) with smooth animations
- Drag‑and‑drop uploads for backups and restores with inline progress
- Searchable select on Restore, toggle switches for restore modes
- Updated translation template and Farsi locale; added Composer script for POT
- Removed unused setup wizard code; minor cleanup

## License

MIT License

Copyright (c) 2025 VirakCloud

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the “Software”), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
