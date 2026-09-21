<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\HttpClient\HttpClient;
use YesWiki\Wiki;

/**
 * Tells a Mattermost channel when a wiki appears or goes, so a farm that anyone
 * can create a wiki on is moderated as things happen rather than once a month.
 *
 * The message carries a link to the entry's deletion page rather than a button
 * that deletes by itself: the moderator lands on the farm, has to be logged in as
 * an admin, and confirms there. Nothing here needs an endpoint of its own.
 */
class MattermostNotifier
{
    private const TIMEOUT = 3;

    private $wiki;
    private $spam;

    public function __construct(Wiki $wiki, SpamScore $spam)
    {
        $this->wiki = $wiki;
        $this->spam = $spam;
    }

    public function isConfigured(): bool
    {
        return $this->webhook() !== '';
    }

    /**
     * @param array<string,mixed> $entry the farm entry the wiki was created from
     */
    public function created(array $entry, string $folder): void
    {
        $name = (string)($entry['bf_titre'] ?? $folder);
        $verdict = $this->spam->of($name, (string)($entry['bf_description'] ?? ''), []);
        $suspect = $verdict['score'] >= $this->spam->threshold();

        $this->send([
            'color' => $suspect ? '#d9534f' : '#5cb85c',
            'title' => _t('FERME_HOOK_CREATED') . ' ' . $name,
            'title_link' => $this->wikiUrl($folder),
            'fields' => array_merge(
                $this->describe($entry, $folder),
                $suspect ? [[
                    'short' => false,
                    'title' => _t('FERME_CHIP_SUSPECT'),
                    'value' => implode(', ', array_map(function (string $reason) {
                        return _t('FERME_SPAM_' . strtoupper($reason));
                    }, $verdict['reasons'])),
                ]] : []
            ),
            'text' => $this->links((string)($entry['id_fiche'] ?? ''), $folder),
        ]);
    }

    /**
     * @param array<string,mixed> $entry
     */
    public function deleted(array $entry, string $folder, bool $wikiKept): void
    {
        $this->send([
            'color' => '#777777',
            'title' => _t($wikiKept ? 'FERME_HOOK_ENTRY_DELETED' : 'FERME_HOOK_DELETED')
                . ' ' . (string)($entry['bf_titre'] ?? $folder),
            'fields' => $this->describe($entry, $folder),
        ]);
    }

    /**
     * @param array<string,mixed> $entry
     *
     * @return array<int,array<string,mixed>>
     */
    private function describe(array $entry, string $folder): array
    {
        $fields = [
            ['short' => true, 'title' => _t('FERME_CLI_COL_FOLDER'), 'value' => $folder],
            ['short' => true, 'title' => _t('FERME_WIKI_OWNER'), 'value' => (string)($entry['bf_referent'] ?? '')],
        ];

        $mail = trim((string)($entry['bf_mail'] ?? ''));
        if ($mail !== '') {
            $fields[] = ['short' => true, 'title' => _t('FERME_MAIL_SUBJECT'), 'value' => $mail];
        }

        $who = $this->wiki->GetUserName();
        if (!empty($who)) {
            $fields[] = ['short' => true, 'title' => _t('FERME_HOOK_BY'), 'value' => (string)$who];
        }

        return $fields;
    }

    /**
     * The line under the message: open the wiki, read its entry, and the one that
     * matters to a moderator, the page that deletes it. It asks for a login and a
     * confirmation like any other, so this stays a link and not a trigger.
     */
    private function links(string $idFiche, string $folder): string
    {
        $links = ['[' . _t('FERME_HOOK_OPEN_WIKI') . '](' . $this->wikiUrl($folder) . ')'];

        if ($idFiche !== '') {
            $links[] = '[' . _t('BAZ_SEE_ENTRY') . '](' . $this->wiki->href('', $idFiche) . ')';
            $links[] = '**[⛔ ' . _t('FERME_HOOK_DELETE') . '](' . $this->wiki->href('deletepage', $idFiche) . ')**';
        }

        return implode(' · ', $links);
    }

    private function wikiUrl(string $folder): string
    {
        return rtrim((string)($this->wiki->config['yeswiki-farm-root-url'] ?? ''), '/') . '/' . $folder . '/';
    }

    /**
     * A notification must never break what it is reporting on, so anything the
     * channel does wrong stays between here and the log.
     *
     * @param array<string,mixed> $attachment
     */
    protected function send(array $attachment): void
    {
        $webhook = $this->webhook();
        if ($webhook === '') {
            return;
        }

        try {
            HttpClient::create()->request('POST', $webhook, [
                'json' => ['attachments' => [$attachment]],
                'timeout' => self::TIMEOUT,
            ])->getStatusCode();
        } catch (\Throwable $throwable) {
            error_log('ferme: mattermost: ' . $throwable->getMessage());
        }
    }

    private function webhook(): string
    {
        return trim((string)($this->wiki->config['yeswiki-farm-mattermost-webhook'] ?? ''));
    }
}
