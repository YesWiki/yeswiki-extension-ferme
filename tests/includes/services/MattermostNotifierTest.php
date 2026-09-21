<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\MattermostNotifier;
use YesWiki\Ferme\Service\SpamScore;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(MattermostNotifier::class, 'created')]
#[CoversMethod(MattermostNotifier::class, 'deleted')]
#[CoversMethod(MattermostNotifier::class, 'isConfigured')]
class MattermostNotifierTest extends YesWikiTestCase
{
    protected function setUp(): void
    {
        $wiki = self::getWiki();
        $wiki->config['yeswiki-farm-mattermost-webhook'] = 'https://chat.exemple.org/hooks/abc';
        $wiki->config['yeswiki-farm-root-url'] = 'https://ferme.exemple.org';
    }

    public function testAFarmWithoutAWebhookSaysSoAndSendsNothing()
    {
        self::getWiki()->config['yeswiki-farm-mattermost-webhook'] = '';
        $notifier = $this->notifier();

        $this->assertFalse($notifier->isConfigured());

        $notifier->created($this->entry(), 'monwiki');
        $this->assertSame([], $notifier->envoyes, 'rien ne part quand rien n\'est configuré');
    }

    public function testACreationSaysWhichWikiAndWhoAsksForIt()
    {
        $notifier = $this->notifier();
        $notifier->created($this->entry(), 'monwiki');

        $this->assertCount(1, $notifier->envoyes);
        $message = $notifier->envoyes[0];
        $this->assertStringContainsString('Wiki de Louise', $message['title']);
        $this->assertSame('https://ferme.exemple.org/monwiki/', $message['title_link']);
        $this->assertSame('#5cb85c', $message['color'], 'vert pour un wiki ordinaire');

        $champs = array_column($message['fields'], 'value', 'title');
        $this->assertContains('monwiki', $champs);
        $this->assertContains('Louise Michel', $champs);
        $this->assertContains('louise@exemple.org', $champs);
    }

    public function testTheMessageLinksToTheDeletionPageRatherThanDeletingByItself()
    {
        $notifier = $this->notifier();
        $notifier->created($this->entry(), 'monwiki');

        $texte = $notifier->envoyes[0]['text'];
        $this->assertStringContainsString('deletepage', $texte);
        $this->assertStringContainsString('FicheLouise', $texte);
        $this->assertStringContainsString('](', $texte, 'un lien markdown, pas un bouton');
    }

    public function testAWikiThatLooksLikeSpamIsFlaggedRedAtBirth()
    {
        $entry = array_merge($this->entry(), [
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

    public function testADeletionSaysWhetherTheWikiWentWithTheEntry()
    {
        $notifier = $this->notifier();
        $notifier->deleted($this->entry(), 'monwiki', false);
        $notifier->deleted($this->entry(), 'monwiki', true);

        $this->assertStringContainsString(_t('FERME_HOOK_DELETED'), $notifier->envoyes[0]['title']);
        $this->assertStringContainsString(_t('FERME_HOOK_ENTRY_DELETED'), $notifier->envoyes[1]['title']);
    }

    private function entry(): array
    {
        return [
            'id_fiche' => 'FicheLouise',
            'bf_titre' => 'Wiki de Louise',
            'bf_description' => 'Le wiki de notre association pour les activités de quartier',
            'bf_referent' => 'Louise Michel',
            'bf_mail' => 'louise@exemple.org',
        ];
    }

    private function notifier(): MattermostNotifier
    {
        $wiki = self::getWiki();

        return new class($wiki, new SpamScore($wiki)) extends MattermostNotifier {
            public $envoyes = [];

            protected function send(array $attachment): void
            {
                if (!$this->isConfigured()) {
                    return;
                }
                $this->envoyes[] = $attachment;
            }
        };
    }
}
