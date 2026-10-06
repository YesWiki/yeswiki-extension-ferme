<?php

namespace YesWiki\Ferme\Exception;

/** A wiki whose numbers could not be read, saying which and why. */
class WikiStatsException extends \RuntimeException
{
    public function __construct(private readonly string $folder, private readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($folder . ': ' . $reason, 0, $previous);
    }

    public function getFolder(): string
    {
        return $this->folder;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
