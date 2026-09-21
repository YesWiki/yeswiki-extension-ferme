<?php

namespace YesWiki\Ferme\Service;

/**
 * Puts a wiki to sleep, or wakes it up, by the `wiki_status` its own configuration
 * carries. A sleeping wiki still reads, but refuses every write, which is what a
 * farm wants for a site nobody maintains any more.
 */
class WikiHibernator
{
    public const RUNNING = 'running';
    public const HIBERNATE = 'hibernate';
    public const ASLEEP = ['hibernate', 'archiving', 'updating'];

    private $config;
    private $editor;
    private $lock;

    public function __construct(FarmConfig $config, WikiConfigEditor $editor, FolderLock $lock)
    {
        $this->config = $config;
        $this->editor = $editor;
        $this->lock = $lock;
    }

    /**
     * A wiki is awake when it says nothing, or says it is running. Archiving and
     * updating are the states core sets while it works, and they refuse writes too.
     */
    public static function isAsleep(string $status): bool
    {
        return in_array(trim($status), self::ASLEEP, true);
    }

    public static function label(string $status): string
    {
        $status = trim($status);
        if ($status === '' || $status === self::RUNNING) {
            return _t('FERME_STATUS_RUNNING');
        }

        return $status === self::HIBERNATE
            ? _t('FERME_STATUS_HIBERNATE')
            : _t('FERME_STATUS_BUSY') . ' (' . $status . ')';
    }

    /**
     * @return array{changed:bool,status:string,before:string}
     */
    public function hibernate(string $folder): array
    {
        return $this->set($folder, self::HIBERNATE);
    }

    /**
     * @return array{changed:bool,status:string,before:string}
     */
    public function wake(string $folder): array
    {
        return $this->set($folder, self::RUNNING);
    }

    /**
     * @return array{changed:bool,status:string,before:string}
     */
    private function set(string $folder, string $status): array
    {
        $dir = $this->config->wikiDir($folder);
        if (!is_file($this->config->wikiConfigFile($folder))) {
            throw new \RuntimeException(_t('FERME_FILE') . $folder . '/wakka.config.php' . _t('FERME_NOT_FOUND'));
        }

        return $this->lock->during($dir, _t('FERME_LOCK_STATUS'), function () use ($dir, $status) {
            $config = $this->editor->load($dir);
            $before = trim((string)($config['wiki_status'] ?? self::RUNNING));
            if ($before === '') {
                $before = self::RUNNING;
            }
            if ($before === $status) {
                return ['changed' => false, 'status' => $status, 'before' => $before];
            }

            $this->editor->apply($config, ['wiki_status' => $status], []);
            $this->editor->write($dir, $config);

            return ['changed' => true, 'status' => $status, 'before' => $before];
        });
    }
}
