<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Service\Mailer;
use YesWiki\Core\Service\PageManager;
use YesWiki\Ferme\Service\FarmMailer;
use YesWiki\Ferme\Service\WelcomeMailer;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversClass(WelcomeMailer::class)]
class WelcomeMailerTest extends YesWikiTestCase
{
    private \DateTimeImmutable $today;
    private array $sent = [];

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-09-24');
        $this->sent = [];
    }

    public function testTheReferentLearnsWhereTheirWikiIsAndHowToLogIn()
    {
        $this->welcome()->send($this->entry(['ferme_lifetime' => 'permanent']), 'WikiAdmin', $this->today);

        $this->assertCount(1, $this->sent);
        $this->assertSame('louise@exemple.org', $this->sent[0]['address']);
        $this->assertStringContainsString('Wiki de Louise', $this->sent[0]['subject']);
        $this->assertStringContainsString('WikiAdmin', $this->sent[0]['text']);
        $this->assertStringContainsString('louise', $this->sent[0]['text']);
    }

    public function testAWikiWithADeadlineIsToldItsDate()
    {
        $this->welcome()->send($this->entry(['ferme_lifetime' => 'short', 'ferme_expires_at' => '2026-12-23']), 'WikiAdmin', $this->today);

        $this->assertStringContainsString('23/12/2026', $this->sent[0]['text']);
        $this->assertStringContainsString('90', $this->sent[0]['text']);
        $this->assertStringContainsString(_t('FERME_LIFETIME_SHORT'), $this->sent[0]['text']);
    }

    public function testAPermanentWikiHearsNothingAboutADeadline()
    {
        foreach ([['ferme_lifetime' => 'permanent'], []] as $terms) {
            $this->sent = [];
            $this->welcome()->send($this->entry($terms), 'WikiAdmin', $this->today);

            $this->assertStringNotContainsString('{', $this->sent[0]['text']);
            $this->assertStringNotContainsString(_t('FERME_LIFETIME_SHORT'), $this->sent[0]['text']);
            $this->assertStringNotContainsString("\n\n\n", $this->sent[0]['text']);
        }
    }

    public function testNoLoginIsGivenWhenTheFarmPicksThePasswordItself()
    {
        $wiki = self::getWiki();
        $before = $wiki->config['yeswiki-farm-password-WikiAdmin'] ?? null;
        $wiki->config['yeswiki-farm-password-WikiAdmin'] = 'secret-de-la-ferme';

        try {
            $this->welcome()->send($this->entry(['ferme_lifetime' => 'permanent']), 'WikiAdmin', $this->today);
        } finally {
            $wiki->config['yeswiki-farm-password-WikiAdmin'] = $before;
        }

        $this->assertStringNotContainsString('WikiAdmin', $this->sent[0]['text']);
        $this->assertStringNotContainsString('secret-de-la-ferme', $this->sent[0]['text']);
        $this->assertStringContainsString('louise', $this->sent[0]['text'], 'the address is still given');
    }

    public function testAnAdminCanWriteTheirOwnWelcome()
    {
        $wiki = self::getWiki();
        $wiki->config['yeswiki-farm-welcome-mail-subject'] = 'Bienvenue sur {title}';
        $wiki->config['yeswiki-farm-welcome-mail-body'] = 'Salut {username}\\nÉchéance : {expires}\\nÀ bientôt';

        try {
            $this->welcome()->send($this->entry(['ferme_lifetime' => 'permanent']), 'WikiAdmin', $this->today);
        } finally {
            unset($wiki->config['yeswiki-farm-welcome-mail-subject'], $wiki->config['yeswiki-farm-welcome-mail-body']);
        }

        $this->assertSame('Bienvenue sur Wiki de Louise', $this->sent[0]['subject']);
        $this->assertSame("Salut WikiAdmin\nÀ bientôt", $this->sent[0]['text']);
    }

    private function entry(array $terms): array
    {
        return array_merge([
            'id_fiche' => 'FicheAlpha',
            'id_typeannonce' => '1100',
            'bf_titre' => 'Wiki de Louise',
            'bf_dossier-wiki' => 'louise',
            'bf_referent' => 'Louise Michel',
            'bf_mail' => 'louise@exemple.org',
        ], $terms);
    }

    private function welcome(): WelcomeMailer
    {
        $wiki = self::getWiki();

        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendEmailFromAdmin')->willReturnCallback(
            function (string $address, string $subject, string $text) {
                $this->sent[] = ['address' => $address, 'subject' => $subject, 'text' => $text];
            }
        );

        $entryManager = $this->createStub(EntryManager::class);

        return new WelcomeMailer(
            $wiki,
            new FarmMailer($wiki, $entryManager, $mailer, $this->createStub(WikiStatsStore::class)),
            new WikiLifetime($wiki, $entryManager, $this->createStub(PageManager::class))
        );
    }
}
