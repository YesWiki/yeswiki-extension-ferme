<?php

namespace YesWiki\Ferme\Exception;

/**
 * Core is already working on that wiki, archiving it or updating it. Whatever
 * holds it owns its folder until it is done, so the farm keeps its hands off.
 */
class WikiBusyException extends \RuntimeException
{
    private $folder;
    private $status;

    public function __construct(string $folder, string $status)
    {
        parent::__construct(_t('FERME_WIKI_BUSY') . ' ' . $folder . ' (' . $status . ')');
        $this->folder = $folder;
        $this->status = $status;
    }

    public function getFolder(): string
    {
        return $this->folder;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
