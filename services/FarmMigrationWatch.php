<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

/**
 * Runs the farm's migrations when the master wiki changes version.
 *
 * A wiki whose files are symbolic links to the master runs the master's new code
 * the second the master is updated, while its own database is still the old one,
 * and YesWiki migrates nothing on its own: only the update page and the `migrate`
 * command do. So both of those ask this whether the version moved, and it answers
 * once per version — the release is written down before the work starts.
 *
 * The version is read from wakka.config.php rather than from the running config:
 * the update that just wrote the new one is the very request asking the question.
 */
class FarmMigrationWatch
{
    public const STATE_FILE = 'private/ferme-release';
    public const LOG_FILE = 'private/ferme-migrate.log';
    public const CONSOLE = 'includes/commands/console';
    public const RUNNING = 'YESWIKI_FERME_MIGRATING';

    private $wiki;
    private $editor;
    private $root;

    public function __construct(Wiki $wiki, WikiConfigEditor $editor, ?string $root = null)
    {
        $this->wiki = $wiki;
        $this->editor = $editor;
        $this->root = rtrim($root ?? (string)getcwd(), DIRECTORY_SEPARATOR);
    }

    public function stateFile(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    public function logFile(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . self::LOG_FILE;
    }

    public function isEnabled(): bool
    {
        return filter_var($this->wiki->config['yeswiki-farm-migrate-on-update'] ?? true, FILTER_VALIDATE_BOOL);
    }

    /** What the master says it runs, version and release as one line. */
    public function release(): string
    {
        try {
            $config = $this->editor->load($this->root);
        } catch (\Throwable $throwable) {
            $config = $this->wiki->config;
        }

        return trim((string)($config['yeswiki_version'] ?? '') . ' ' . (string)($config['yeswiki_release'] ?? ''));
    }

    public function lastSeen(): string
    {
        return trim((string)@file_get_contents($this->stateFile()));
    }

    /**
     * True once per new version. A farm that has never recorded anything only
     * records: its wikis were already on the version it found, nothing is owed to
     * them. A run started by the farm itself is never due, so the migrations of one
     * wiki cannot start the migrations of the whole farm again.
     */
    public function isDue(): bool
    {
        $release = $this->release();
        if (!$this->isEnabled() || getenv(self::RUNNING) !== false || $release === '' || $this->lastSeen() === $release) {
            return false;
        }

        $seen = $this->claim($release);

        return $seen !== null && $seen !== '';
    }

    /**
     * For the update page: the visitor has their page, and thousands of wikis are
     * minutes of work, far more than a page view may hold. Nothing waits for the
     * result, it goes to the log next to the file that holds the release.
     */
    public function triggerAfterResponse(): void
    {
        if (!$this->isDue()) {
            return;
        }

        register_shutdown_function(function () {
            @ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            try {
                $this->startInBackground();
            } catch (\Throwable $throwable) {
                error_log('ferme: ' . $throwable->getMessage());
            }
        });
    }

    public function startInBackground(): void
    {
        if (!$this->canSpawn()) {
            error_log('ferme: ' . _t('FERME_MIGRATE_NO_EXEC'));

            return;
        }

        @file_put_contents(
            $this->logFile(),
            "\n=== " . date('Y-m-d H:i:s') . ' ' . $this->release() . " ===\n",
            FILE_APPEND | LOCK_EX
        );

        $command = self::RUNNING . '=1 ' . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($this->root . DIRECTORY_SEPARATOR . self::CONSOLE)
            . ' ferme:update --migrate-only --no-ansi --no-interaction';

        @exec($command . ' >> ' . escapeshellarg($this->logFile()) . ' 2>&1 &');
    }

    /**
     * Write down the release we are about to act on, and say what was written there
     * before. Two requests served the same second must not both start the farm's
     * migrations, so the reading and the writing happen under one lock.
     *
     * @return string|null the release seen before, null when somebody got there first
     */
    private function claim(string $release): ?string
    {
        $folder = dirname($this->stateFile());
        if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) {
            return null;
        }

        $handle = @fopen($this->stateFile(), 'c+');
        if ($handle === false) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }
            $seen = trim((string)stream_get_contents($handle));
            if ($seen === $release) {
                return null;
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $release . "\n");
            fflush($handle);

            return $seen;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private function canSpawn(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

        return !in_array('exec', $disabled, true);
    }
}
