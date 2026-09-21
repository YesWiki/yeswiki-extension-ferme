<?php

namespace YesWiki\Ferme\Exception;

/**
 * The wiki is hibernating, and the farm does nothing to a hibernating wiki but
 * wake it. Whoever put it to sleep meant it to be left alone.
 */
class WikiAsleepException extends \RuntimeException
{
    private $folder;

    public function __construct(string $folder)
    {
        parent::__construct(_t('FERME_WIKI_ASLEEP') . ' ' . $folder);
        $this->folder = $folder;
    }

    public function getFolder(): string
    {
        return $this->folder;
    }
}
