<?php

namespace YesWiki\Ferme\Exception;

use YesWiki\Bazar\Exception\RequiredFieldsException;

/**
 * A wiki that could not be created. It extends RequiredFieldsException because that is
 * the only failure bazar answers by reopening the form with what the visitor typed.
 */
class WikiCreationException extends RequiredFieldsException
{
    /** @var string|null the input to clear, when one of them is to blame */
    private $blamedField;

    public function __construct(string $message, ?string $blamedField = null)
    {
        parent::__construct([]);
        $this->message = $message;
        $this->blamedField = $blamedField;
    }

    public function getBlamedField(): ?string
    {
        return $this->blamedField;
    }
}
