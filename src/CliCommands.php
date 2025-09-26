<?php

namespace VirakCloud\Backup;

use WP_CLI;

class CliCommands
{
    /**
     * @param string[] $args
     * @param array<string, string> $assoc
     */
    public static function root(array $args, array $assoc): void
    {
        $sub = $args[0] ?? 'help';
        switch ($sub) {
            case 'backup':
                self::backup($args, $assoc);
                break;
            case 'schedule':
                self::schedule($args, $assoc);
                break;
            case 'restore':
                self::restore($args, $assoc);
                break;
            case 'migrate':
                self::migrate($args, $assoc);
                break;
            case 'restore-full':
                self::restoreFull($args, $assoc);
                break;
            case 'clean-local':
                self::cleanLocal($args, $assoc);
                break;
            default:
                WP_CLI::log('Usage: wp vcbk <backup|schedule|restore|restore-full|migrate|clean-local>');
        }
    }

    /**
     * @param string[] $args
     * @param array<string, string> $assoc
     */
    public static function backup(array $args, array $assoc): void
    {
        $type = $assoc['type'] ?? 'full';
        $settings = new Settings();
        $logger = new Logger();
        $bm = new BackupManager($settings, $logger);
        $result = $bm->run($type, ['schedule' => true]);
        WP_CLI::success('Backup created: ' . $result['key']);
    }

    /**
     * @param string[] $args
     * @param array<string, string> $assoc
     */
    public static function schedule(array $args, array $assoc): void
    {
        $action = $args[1] ?? 'list';
        $settings = new Settings();
        $logger = new Logger();
        $scheduler = new Scheduler($settings, $logger);
        if ($action === 'list') {
            $ts = wp_next_scheduled('vcbk_cron_run');
            WP_CLI::log('Next run: ' . ($ts ? date('c', $ts) : 'not scheduled'));
        } else {
            WP_CLI::error('Unknown action');
        }
    }

    /**
     * @param string[] $args
     * @param array<string, string> $assoc
     */
    public static function restore(array $args, array $assoc): void
    {
        $file = $assoc['file'] ?? null;
        if (!$file || !file_exists($file)) {
            WP_CLI::error('Provide --file=/path/to/archive.zip');
            return;
        }
        $settings = new Settings();
        $logger = new Logger();
        $rm = new RestoreManager($settings, $logger);
        $rm->restoreLocal($file, ['dry_run' => !empty($assoc['dry-run'])]);
        WP_CLI::success('Restore process completed');
    }

    /**
     * Full-site restore. Usage:
     *   wp vcbk restore-full --file=/path/to/archive.zip [--no-preserve-plugin] [--dry-run]
     *   wp vcbk restore-full --key=backups/<site-prefix>/backup-20250101-010101.zip [--no-preserve-plugin]
     *
     * @param string[] $args
     * @param array<string, string> $assoc
     */
    public static function restoreFull(array $args, array $assoc): void
    {
        $settings = new Settings();
        $logger = new Logger();
        $rm = new RestoreManager($settings, $logger);
        $preserve = !isset($assoc['no-preserve-plugin']);
        $opts = ['preserve_plugin' => $preserve, 'dry_run' => !empty($assoc['dry-run'])];
        if (!empty($assoc['key'])) {
            $rm->restoreFullFromS3((string) $assoc['key'], $opts);
        } else {
            $file = $assoc['file'] ?? null;
            if (!$file || !file_exists($file)) {
                WP_CLI::error('Provide --file= or --key=');
                return;
            }
            $rm->restoreFullLocal((string) $file, $opts);
        }
        WP_CLI::success('Full-site restore completed');
    }

    /**
     * @param string[] $args
     * @param array<string, string> $assoc
     */
    public static function migrate(array $args, array $assoc): void
    {
        $from = $assoc['from'] ?? null;
        $to = $assoc['to'] ?? null;
        if (!$from || !$to) {
            WP_CLI::error('Provide --from=URL --to=URL');
            return;
        }
        $mm = new MigrationManager(new Logger());
        $mm->searchReplace($from, $to);
        WP_CLI::success('Migration search/replace complete');
    }

    /**
     * Remove old local archives, keeping N latest (default 1).
     * Usage: wp vcbk clean-local [--keep=1]
     * @param string[] $args
     * @param array<string, string> $assoc
     */
    public static function cleanLocal(array $args, array $assoc): void
    {
        $keep = isset($assoc['keep']) ? (int) $assoc['keep'] : 1;
        $settings = new Settings();
        $logger = new Logger();
        $bm = new BackupManager($settings, $logger);
        $deleted = $bm->pruneLocal($keep);
        WP_CLI::success("Deleted $deleted local file(s)");
    }
}
