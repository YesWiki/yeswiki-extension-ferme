<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Files\Service\RuntimeLock;
use YesWiki\Kernel\Service\ConsoleService;
use YesWiki\Kernel\Service\ProgramVersion;
use YesWiki\Kernel\Service\TripleStore;

/** Notices that the shared Program changed version (ADR-0007) and runs every wiki's own migrations, once per version. */
class FarmMigrationWatch
{
    public const RESOURCE = 'ferme:farm';
    public const PROPERTY = 'http://yeswiki.net/_vocabulary/ferme/migratedRelease';
    public const LOCK = 'cache/ferme-migrate.lock';
    public const LOG = 'cache/ferme-migrate.log';

    public function __construct(
        private readonly FarmConfig $config,
        private readonly TripleStore $triples,
        private readonly RuntimeLock $locks,
        private readonly WikiFinder $finder
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->flag('yeswiki-farm-migrate-on-update', true);
    }

    /** What the Program says it is, release line and version as one line. */
    public function release(): string
    {
        $program = new ProgramVersion();

        return trim($program->releaseLine() . ' ' . $program->version());
    }

    public function lastSeen(): string
    {
        return trim((string)$this->triples->getOne(self::RESOURCE, self::PROPERTY, '', ''));
    }

    /**
     * True once per new version, and never inside a run the farm started itself: a farm that never recorded one only records.
     */
    public function isDue(): bool
    {
        $release = $this->release();
        if (!$this->isEnabled() || getenv(InstanceConsole::MIGRATING) !== false || $release === '' || $this->lastSeen() === $release) {
            return false;
        }
        if ($this->finder->find() === []) {
            return false;
        }
        $handle = $this->locks->acquire(self::LOCK);
        if (!$this->locks->tryLock($handle)) {
            return false;
        }

        try {
            $seen = $this->lastSeen();
            if ($seen === $release) {
                return false;
            }
            if ($seen === '') {
                $this->triples->create(self::RESOURCE, self::PROPERTY, $release, '', '');
            } else {
                $this->triples->update(self::RESOURCE, self::PROPERTY, $seen, $release, '', '');
            }

            return $seen !== '';
        } finally {
            $this->locks->release($handle);
        }
    }

    /** Start the farm's migrations in the background when the version moved, so no visitor waits for thousands of wikis. */
    public function onMaintenance(): void
    {
        if (!$this->isDue()) {
            return;
        }
        $command = escapeshellarg(PHP_SAPI === 'cli' ? PHP_BINARY : 'php') . ' ' . escapeshellarg(ConsoleService::console()) . ' ferme:migrate --no-ansi --no-interaction';
        $log = $this->config->instanceDir() . '/' . self::LOG;
        if (function_exists('exec')) {
            @exec('cd ' . escapeshellarg($this->config->instanceDir()) . ' && ' . InstanceConsole::MIGRATING . '=1 nohup ' . $command . ' >> ' . escapeshellarg($log) . ' 2>&1 &');
        }
    }
}
