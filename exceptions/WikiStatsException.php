<?php

namespace YesWiki\Ferme\Exception;

/**
 * A wiki whose numbers could not be read, carrying which wiki and why, so a sweep
 * can record the reason and carry on with the next one.
 */
class WikiStatsException extends \RuntimeException
{
    private $folder;
    private $reason;

    public function __construct(string $folder, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($folder . ': ' . $reason, 0, $previous);
        $this->folder = $folder;
        $this->reason = $reason;
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
