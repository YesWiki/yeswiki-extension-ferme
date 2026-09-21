<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Service\Mailer;
use YesWiki\Wiki;

/**
 * Writes to the people who run the wikis of the farm. The address is taken from
 * the farm entry, never from what the browser sent, so an admin picks which wikis
 * to write to and not who the mail goes to.
 */
class FarmMailer
{
    public const PLACEHOLDERS = ['title', 'url', 'referent', 'folder', 'lastActivity'];

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

    /**
     * @return string the address it went to
     */
    public function sendToReferent(string $idFiche, string $subject, string $body): string
    {
        $entry = $this->farmEntry($idFiche);
        $address = trim((string)($entry['bf_mail'] ?? ''));

        if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException(_t('FERME_MAIL_NO_ADDRESS'));
        }

        $subject = trim($this->fill($subject, $entry));
        $body = trim($this->fill($body, $entry));
        if ($subject === '' || $body === '') {
            throw new \RuntimeException(_t('FERME_MAIL_EMPTY'));
        }

        $this->mailer->sendEmailFromAdmin($address, $subject, $body, nl2br(htmlspecialchars($body)));

        return $address;
    }

    /**
     * Replace the handful of things an admin can point at in a subject or a body.
     *
     * @param array<string,mixed> $entry
     */
    public function fill(string $text, array $entry): string
    {
        $folder = (string)($entry['bf_dossier-wiki'] ?? '');
        $stats = $folder === '' ? null : $this->statsStore->read($folder);

        $values = [
            'title' => (string)($entry['bf_titre'] ?? ''),
            'url' => $this->url($folder),
            'referent' => (string)($entry['bf_referent'] ?? ''),
            'folder' => $folder,
            'lastActivity' => (string)($stats['lastActivity'] ?? _t('FERME_STATS_NEVER_MEASURED')),
        ];

        foreach ($values as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }

        return $text;
    }

    /**
     * @return array<string,mixed>
     */
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
