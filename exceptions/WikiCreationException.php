<?php

namespace YesWiki\Ferme\Exception;

use YesWiki\Content\Exception\EntryValidationException;

/** A wiki that could not be created: the entry form opens again with what the visitor typed. */
class WikiCreationException extends EntryValidationException
{
    public function __construct(string $message, private readonly ?string $blamedField = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getBlamedField(): ?string
    {
        return $this->blamedField;
    }
}
