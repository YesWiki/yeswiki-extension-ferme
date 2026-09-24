<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Service\Mailer;
use YesWiki\Wiki;

/** Writes to the people who run the wikis of the farm. */
class FarmMailer
{
    public const PLACEHOLDERS = ['title', 'url', 'referent', 'folder', 'lastActivity', 'entries', 'pages', 'users', 'files'];

    private $wiki;
    private $entryManager;
    private $mailer;
    private $statsStore;

    public function __construct(Wiki $wiki, EntryManager $entryManager, Mailer $mailer, WikiStatsStore $statsStore)
    {
        $this->wiki = $wiki;
        $this->entryManager = $entryManager;
        $this->mailer = $mailer;
        $this->statsStore = $statsStore;
    }

    public function sendToReferent(string $idFiche, string $subject, string $body): string
    {
        return $this->send($this->farmEntry($idFiche), $subject, $body);
    }

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

        return $address;
    }

    /** Replace what an admin can point at in a mail, dropping the lines that point at an extra left empty. */
    public function fill(string $text, array $entry, array $extra = []): string
    {
        $folder = (string)($entry['bf_dossier-wiki'] ?? '');
        $stats = $folder === '' ? null : $this->statsStore->read($folder);

        $values = [
            'title' => (string)($entry['bf_titre'] ?? ''),
            'url' => $this->url($folder),
            'referent' => (string)($entry['bf_referent'] ?? ''),
            'folder' => $folder,
            'lastActivity' => (string)($stats['lastActivity'] ?? _t('FERME_STATS_NEVER_MEASURED')),
            'entries' => (string)(int)($stats['entries'] ?? 0),
            'pages' => (string)(int)($stats['pages'] ?? 0),
            'users' => (string)(int)($stats['users'] ?? 0),
            'files' => (string)(int)($stats['files'] ?? 0),
        ];
        $values = array_merge($values, $extra);

        $empty = array_keys(array_filter($extra, function ($value) {
            return $value === '';
        }));
        if ($empty !== []) {
            $text = implode("\n", array_filter(explode("\n", $text), function (string $line) use ($empty) {
                foreach ($empty as $name) {
                    if (str_contains($line, '{' . $name . '}')) {
                        return false;
                    }
                }

                return true;
            }));
            $text = preg_replace("/\n{3,}/", "\n\n", $text);
        }

        foreach ($values as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }

        return $text;
    }

    private function farmEntry(string $idFiche): array
    {
        $entry = $this->entryManager->isEntry($idFiche) ? $this->entryManager->getOne($idFiche) : null;
        if (empty($entry)) {
            throw new \RuntimeException(_t('FERME_MAIL_NO_ENTRY') . ' ' . $idFiche);
        }

        $farmForm = (string)($this->wiki->config['bazar_farm_id'] ?? '1100');
        if ((string)($entry['id_typeannonce'] ?? '') !== $farmForm) {
            throw new \RuntimeException(_t('FERME_MAIL_NOT_A_WIKI') . ' ' . $idFiche);
        }

        return $entry;
    }

    private function url(string $folder): string
    {
        if ($folder === '') {
            return '';
        }

        $root = rtrim((string)($this->wiki->config['yeswiki-farm-root-url'] ?? ''), '/');

        return $root === '' ? $folder : $root . '/' . $folder . '/';
    }
}
