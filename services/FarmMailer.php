<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Content\Service\EntryManager;
use YesWiki\Kernel\Service\Mailer;

/** Writes to the people who run the wikis of the farm. */
class FarmMailer
{
    public const PLACEHOLDERS = ['title', 'url', 'referent', 'folder', 'lastActivity', 'entries', 'pages', 'users', 'files'];

    public function __construct(
        private readonly FarmConfig $config,
        private readonly EntryManager $entryManager,
        private readonly Mailer $mailer,
        private readonly WikiStatsStore $statsStore
    ) {
    }

    public function sendToReferent(string $tag, string $subject, string $body): string
    {
        return $this->send($this->farmEntry($tag), $subject, $body);
    }

    /**
     * @param array<string, mixed>  $entry
     * @param array<string, string> $extra
     */
    public function send(array $entry, string $subject, string $body, array $extra = []): string
    {
        $address = trim((string)($entry['bf_mail'] ?? ''));
        if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException(_t('FERME_MAIL_NO_ADDRESS'));
        }
        $subject = trim($this->fill($subject, $entry, $extra));
        $body = trim($this->fill($body, $entry, $extra));
        if ($subject === '' || $body === '') {
            throw new \RuntimeException(_t('FERME_MAIL_EMPTY'));
        }
        $this->mailer->sendEmailFromAdmin($address, $subject, $body, nl2br(htmlspecialchars($body)));
        if ($this->mailer->lastError() !== '') {
            throw new \RuntimeException($this->mailer->lastError());
        }

        return $address;
    }

    /**
     * Replace what a mail can point at, dropping the lines that point at an extra left empty.
     *
     * @param array<string, mixed>  $entry
     * @param array<string, string> $extra
     */
    public function fill(string $text, array $entry, array $extra = []): string
    {
        $folder = (string)($entry['bf_dossier-wiki'] ?? '');
        $stats = $folder === '' ? null : $this->statsStore->read($folder);
        $values = array_merge([
            'title' => self::titleOf($entry),
            'url' => $folder === '' ? '' : $this->config->wikiUrl($folder),
            'referent' => (string)($entry['bf_referent'] ?? ''),
            'folder' => $folder,
            'lastActivity' => (string)($stats['lastActivity'] ?? _t('FERME_STATS_NEVER_MEASURED')),
            'entries' => (string)(int)($stats['entries'] ?? 0),
            'pages' => (string)(int)($stats['pages'] ?? 0),
            'users' => (string)(int)($stats['users'] ?? 0),
            'files' => (string)(int)($stats['files'] ?? 0),
        ], $extra);

        $empty = array_keys(array_filter($extra, static fn (string $value): bool => $value === ''));
        if ($empty !== []) {
            $text = implode("\n", array_filter(explode("\n", $text), static function (string $line) use ($empty): bool {
                foreach ($empty as $name) {
                    if (str_contains($line, '{' . $name . '}')) {
                        return false;
                    }
                }

                return true;
            }));
            $text = (string)preg_replace("/\n{3,}/", "\n\n", $text);
        }
        foreach ($values as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }

        return $text;
    }

    /** @param array<string, mixed> $entry */
    public static function titleOf(array $entry): string
    {
        $title = (string)($entry['title'] ?? '');

        return $title !== '' ? $title : (string)($entry['bf_titre'] ?? $entry['bf_dossier-wiki'] ?? '');
    }

    /** @return array<string, mixed> */
    private function farmEntry(string $tag): array
    {
        $entry = $this->entryManager->isEntry($tag) ? $this->entryManager->getOne($tag) : null;
        if (empty($entry)) {
            throw new \RuntimeException(_t('FERME_MAIL_NO_ENTRY') . ' ' . $tag);
        }
        if ((string)($entry['form_id'] ?? '') !== $this->config->farmId()) {
            throw new \RuntimeException(_t('FERME_MAIL_NOT_A_WIKI') . ' ' . $tag);
        }

        return $entry;
    }
}
