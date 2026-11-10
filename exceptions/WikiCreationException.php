<?php

// A wiki that could not be created: a RequiredFieldsException on cores that have one (4.6.7 and up), so bazar reopens the form with what the visitor typed.

namespace YesWiki\Ferme\Exception;

use YesWiki\Bazar\Exception\RequiredFieldsException;

if (!class_exists(__NAMESPACE__ . '\WikiCreationBase', false)) {
    class_alias(
        class_exists(RequiredFieldsException::class, false) || stream_resolve_include_path('tools/bazar/exceptions/RequiredFieldsException.php') !== false
            ? RequiredFieldsException::class
            : \Exception::class,
        __NAMESPACE__ . '\WikiCreationBase'
    );
}

class WikiCreationException extends WikiCreationBase
{
    private $blamedField;

    public function __construct(string $message, ?string $blamedField = null)
    {
        if ($this instanceof RequiredFieldsException) {
            parent::__construct([]);
        } else {
            parent::__construct($message);
        }
        $this->message = $message;
        $this->blamedField = $blamedField;
    }

    public function getBlamedField(): ?string
    {
        return $this->blamedField;
    }
}
