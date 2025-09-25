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
            default:
                WP_CLI::log('Usage: wp vcbk <backup|schedule|restore|migrate>');
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
}
