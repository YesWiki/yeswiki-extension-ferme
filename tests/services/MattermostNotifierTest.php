<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\MattermostNotifier;
use YesWiki\Ferme\Service\SpamScore;
use YesWiki\Identity\Service\AuthenticationService;
use YesWiki\Kernel\Service\UrlFormatter;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** The channel told of each wiki made or gone. */
class MattermostNotifierTest extends FarmTestCase
{
    public function testAFarmWithoutAWebhookSaysSoAndSendsNothing(): void
    {
        $notifier = $this->notifier('');

        $this->assertFalse($notifier->isConfigured());

        $notifier->created($this->fiche(), 'monwiki');
        $this->assertSame([], $notifier->envoyes, 'rien ne part quand rien n\'est configuré');
    }

    public function testACreationSaysWhichWikiAndWhoAsksForIt(): void
    {
        $notifier = $this->notifier();
        $notifier->created($this->fiche(), 'monwiki');

        $this->assertCount(1, $notifier->envoyes);
        $message = $notifier->envoyes[0];
        $this->assertStringContainsString('Wiki de Louise', $message['title']);
        $this->assertSame('https://ferme.exemple.org/monwiki/', $message['title_link']);
        $this->assertSame('#5cb85c', $message['color']);

        $champs = array_column($message['fields'], 'value');
        $this->assertContains('monwiki', $champs);
        $this->assertContains('Louise Michel', $champs);
        $this->assertContains('louise@exemple.org', $champs);
    }

    public function testTheMessageLinksToTheDeletionPageRatherThanDeletingByItself(): void
    {
        $notifier = $this->notifier();
        $notifier->created($this->fiche(), 'monwiki');

        $texte = $notifier->envoyes[0]['text'];
        $this->assertStringContainsString('deletepage', $texte);
        $this->assertStringContainsString('fiche-louise', $texte);
        $this->assertStringContainsString('](', $texte);
    }

    public function testTheCreationMessageEndsWithTheWayToDeleteTheWiki(): void
    {
        $notifier = $this->notifier();
        $notifier->created($this->fiche(), 'monwiki');

        $texte = $notifier->envoyes[0]['text'];
        $this->assertStringContainsString('https://ferme.exemple.org/monwiki/', $texte);
        $this->assertStringContainsString('**[', $texte);
        $this->assertStringEndsWith(')**', trim($texte));
    }

    public function testWithoutItsTagTheMessageStillOpensTheWiki(): void
    {
        $sansTag = $this->fiche();
        unset($sansTag['tag']);

        $notifier = $this->notifier();
        $notifier->created($sansTag, 'monwiki');

        $texte = $notifier->envoyes[0]['text'];
        $this->assertStringContainsString('https://ferme.exemple.org/monwiki/', $texte);
        $this->assertStringNotContainsString('deletepage', $texte);
    }

    public function testAWikiThatLooksLikeSpamIsFlaggedRedAtBirth(): void
    {
        $entry = array_merge($this->fiche(), [
            'bf_titre' => 'Top Mumbai Escorts, best casino bonus',
            'bf_description' => 'Book the best online slots now',
        ]);

        $notifier = $this->notifier();
        $notifier->created($entry, 'spam33');

        $message = $notifier->envoyes[0];
        $this->assertSame('#d9534f', $message['color']);
        $champs = array_column($message['fields'], 'title');
        $this->assertContains(_t('FERME_CHIP_SUSPECT'), $champs);
    }

    public function testADeletionSaysWhetherTheWikiWentWithTheEntry(): void
    {
        $notifier = $this->notifier();
        $notifier->deleted($this->fiche(), 'monwiki', false);
        $notifier->deleted($this->fiche(), 'monwiki', true);

        $this->assertStringContainsString(_t('FERME_HOOK_DELETED'), $notifier->envoyes[0]['title']);
        $this->assertStringContainsString(_t('FERME_HOOK_ENTRY_DELETED'), $notifier->envoyes[1]['title']);
    }

    /** @return array<string, string> */
    private function fiche(): array
    {
        return [
            'tag' => 'fiche-louise',
            'bf_titre' => 'Wiki de Louise',
            'bf_description' => 'Le wiki de notre association pour les activités de quartier',
            'bf_referent' => 'Louise Michel',
            'bf_mail' => 'louise@exemple.org',
        ];
    }

    private function notifier(string $webhook = 'https://chat.exemple.org/hooks/abc'): CapturingNotifier
    {
        $farm = $this->farm(['yeswiki-farm-mattermost-webhook' => $webhook, 'yeswiki-farm-root-url' => 'https://ferme.exemple.org', 'yeswiki-farm-spam-threshold' => 3, 'yeswiki-farm-spam-words' => '']);
        $services = self::getWiki()->services;

        return new CapturingNotifier($farm, new SpamScore($farm), $services->get(UrlFormatter::class), $services->get(AuthenticationService::class));
    }
}

/** A notifier that keeps what it would have posted. */
class CapturingNotifier extends MattermostNotifier
{
    /** @var list<array<string, mixed>> */
    public array $envoyes = [];

    protected function send(array $attachment): void
    {
        if ($this->isConfigured()) {
            $this->envoyes[] = $attachment;
        }
    }
}
