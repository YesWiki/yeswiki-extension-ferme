<?php

namespace YesWiki\Ferme\Exception;

/**
 * Another process is already working on that wiki folder. It carries what the
 * holder said it was doing, so a queue can report it and move to the next wiki.
 */
class FolderBusyException extends \RuntimeException
{
    private $folder;
    private $heldFor;

    public function __construct(string $folder, string $heldFor)
    {
        parent::__construct(_t('FERME_FOLDER_BUSY') . ' ' . $folder . ($heldFor === '' ? '' : ' (' . $heldFor . ')'));
        $this->folder = $folder;
        $this->heldFor = $heldFor;
    }

    public function getFolder(): string
    {
        return $this->folder;
    }

    public function getHeldFor(): string
    {
        return $this->heldFor;
    }
}
