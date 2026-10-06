<?php

namespace YesWiki\Ferme\Exception;

/** The wiki is hibernating, and the farm does nothing to it but wake it. */
class WikiAsleepException extends \RuntimeException
{
    public function __construct(private readonly string $folder)
    {
        parent::__construct(_t('FERME_WIKI_ASLEEP') . ' ' . $folder);
    }

    public function getFolder(): string
    {
        return $this->folder;
    }
}
