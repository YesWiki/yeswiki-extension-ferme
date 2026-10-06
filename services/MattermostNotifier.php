<?php

namespace YesWiki\Ferme\Service;

use Symfony\Component\HttpClient\HttpClient;
use YesWiki\Identity\Service\AuthenticationService;
use YesWiki\Kernel\Service\UrlFormatter;

/** Tells a Mattermost channel when a wiki appears or goes, with a link to the page that deletes it. */
class MattermostNotifier
{
    private const TIMEOUT = 3;

    public function __construct(
        private readonly FarmConfig $config,
        private readonly SpamScore $spam,
        private readonly UrlFormatter $urlFormatter,
        private readonly AuthenticationService $authentication
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->webhook() !== '';
    }

    /** @param array<string, mixed> $entry */
    public function created(array $entry, string $folder): void
    {
        $name = FarmMailer::titleOf($entry);
        $verdict = $this->spam->of($name, (string)($entry['bf_description'] ?? ''), []);
        $suspect = $verdict['score'] >= $this->spam->threshold();

        $this->send([
            'color' => $suspect ? '#d9534f' : '#5cb85c',
            'title' => _t('FERME_HOOK_CREATED') . ' ' . $name,
            'title_link' => $this->config->wikiUrl($folder),
            'fields' => array_merge(
                $this->describe($entry, $folder),
                $suspect ? [[
                    'short' => false,
                    'title' => _t('FERME_CHIP_SUSPECT'),
                    'value' => implode(', ', array_map(static fn (string $reason): string => _t('FERME_SPAM_' . strtoupper($reason)), $verdict['reasons'])),
                ]] : []
            ),
            'text' => $this->links((string)($entry['tag'] ?? ''), $folder),
        ]);
    }

    /** @param array<string, mixed> $entry */
    public function deleted(array $entry, string $folder, bool $wikiKept): void
    {
        $this->send([
            'color' => '#777777',
            'title' => _t($wikiKept ? 'FERME_HOOK_ENTRY_DELETED' : 'FERME_HOOK_DELETED') . ' ' . FarmMailer::titleOf($entry),
            'fields' => $this->describe($entry, $folder),
        ]);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
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
        $who = $this->authentication->getLoggedUserName();
        if ($who !== '') {
            $fields[] = ['short' => true, 'title' => _t('FERME_HOOK_BY'), 'value' => $who];
        }

        return $fields;
    }

    private function links(string $tag, string $folder): string
    {
        $links = ['[' . _t('FERME_HOOK_OPEN_WIKI') . '](' . $this->config->wikiUrl($folder) . ')'];
        if ($tag !== '') {
            $links[] = '[' . _t('FERME_HOOK_SEE_ENTRY') . '](' . $this->urlFormatter->href('', $tag, null, false) . ')';
            $links[] = '**[' . _t('FERME_HOOK_DELETE') . '](' . $this->urlFormatter->href('deletepage', $tag, null, false) . ')**';
        }

        return implode(' · ', $links);
    }

    /**
     * A notification must never break what it reports on.
     *
     * @param array<string, mixed> $attachment
     */
    protected function send(array $attachment): void
    {
        $webhook = $this->webhook();
        if ($webhook === '') {
            return;
        }

        try {
            HttpClient::create()->request('POST', $webhook, ['json' => ['attachments' => [$attachment]], 'timeout' => self::TIMEOUT])->getStatusCode();
        } catch (\Throwable $throwable) {
            error_log('ferme: mattermost: ' . $throwable->getMessage());
        }
    }

    private function webhook(): string
    {
        return $this->config->text('yeswiki-farm-mattermost-webhook');
    }
}
