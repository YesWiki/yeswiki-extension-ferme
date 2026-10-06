<?php

namespace YesWiki\Ferme\Exception;

/** Another process is already working on that wiki folder, and says what it is doing. */
class FolderBusyException extends \RuntimeException
{
    public function __construct(private readonly string $folder, private readonly string $heldFor)
    {
        parent::__construct(_t('FERME_FOLDER_BUSY') . ' ' . basename($folder) . ($heldFor === '' ? '' : ' (' . $heldFor . ')'));
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
