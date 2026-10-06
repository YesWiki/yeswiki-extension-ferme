<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Content\Service\EntryManager;
use YesWiki\Content\Service\PageManager;
use YesWiki\Ferme\Service\FarmMailer;
use YesWiki\Ferme\Service\WelcomeMailer;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Files\Service\Storage;
use YesWiki\Kernel\Service\Mailer;
use YesWiki\Kernel\Service\UrlFormatter;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** The mail the referent of a new wiki gets. */
class WelcomeMailerTest extends FarmTestCase
{
    private \DateTimeImmutable $today;
    /** @var list<array<string, string>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = new \DateTimeImmutable('2026-09-24');
        $this->sent = [];
    }

    public function testTheReferentLearnsWhereTheirWikiIsAndHowToLogIn(): void
    {
        $this->welcome()->send($this->fiche(['ferme_lifetime' => 'permanent']), 'WikiAdmin', $this->today);

        $this->assertCount(1, $this->sent);
        $this->assertSame('louise@exemple.org', $this->lastMail()['address']);
        $this->assertStringContainsString('Wiki de Louise', $this->lastMail()['subject']);
        $this->assertStringContainsString('WikiAdmin', $this->lastMail()['text']);
        $this->assertStringContainsString('louise', $this->lastMail()['text']);
    }

    public function testAWikiWithADeadlineIsToldItsDate(): void
    {
        $this->welcome()->send($this->fiche(['ferme_lifetime' => 'short', 'ferme_expires_at' => '2026-12-23']), 'WikiAdmin', $this->today);

        $this->assertStringContainsString('23/12/2026', $this->lastMail()['text']);
        $this->assertStringContainsString('90', $this->lastMail()['text']);
        $this->assertStringContainsString(_t('FERME_LIFETIME_SHORT'), $this->lastMail()['text']);
    }

    public function testAPermanentWikiHearsNothingAboutADeadline(): void
    {
        foreach ([['ferme_lifetime' => 'permanent'], []] as $terms) {
            $this->sent = [];
            $this->welcome()->send($this->fiche($terms), 'WikiAdmin', $this->today);

            $this->assertStringNotContainsString('{', $this->lastMail()['text']);
            $this->assertStringNotContainsString(_t('FERME_LIFETIME_SHORT'), $this->lastMail()['text']);
            $this->assertStringNotContainsString("\n\n\n", $this->lastMail()['text']);
        }
    }

    public function testNoLoginIsGivenWhenTheFarmPicksThePasswordItself(): void
    {
        $this->welcome(['yeswiki-farm-password-WikiAdmin' => 'secret-de-la-ferme'])->send($this->fiche(['ferme_lifetime' => 'permanent']), 'WikiAdmin', $this->today);

        $this->assertStringNotContainsString('WikiAdmin', $this->lastMail()['text']);
        $this->assertStringNotContainsString('secret-de-la-ferme', $this->lastMail()['text']);
        $this->assertStringContainsString('louise', $this->lastMail()['text']);
    }

    public function testAnAdminCanWriteTheirOwnWelcome(): void
    {
        $this->welcome([
            'yeswiki-farm-welcome-mail-subject' => 'Bienvenue sur {title}',
            'yeswiki-farm-welcome-mail-body' => 'Salut {username}\\nÉchéance : {expires}\\nÀ bientôt',
        ])->send($this->fiche(['ferme_lifetime' => 'permanent']), 'WikiAdmin', $this->today);

        $this->assertSame('Bienvenue sur Wiki de Louise', $this->lastMail()['subject']);
        $this->assertSame("Salut WikiAdmin\nÀ bientôt", $this->lastMail()['text']);
    }

    /**
     * @param array<string, string> $terms
     *
     * @return array<string, string>
     */
    private function fiche(array $terms): array
    {
        return array_merge([
            'tag' => 'fiche-alpha',
            'form_id' => '1100',
            'bf_titre' => 'Wiki de Louise',
            'bf_dossier-wiki' => 'louise',
            'bf_referent' => 'Louise Michel',
            'bf_mail' => 'louise@exemple.org',
        ], $terms);
    }

    /** @param array<string, mixed> $settings */
    private function welcome(array $settings = []): WelcomeMailer
    {
        $farm = $this->farm($settings + [
            'yeswiki-farm-root-url' => 'https://farm.test/',
            'yeswiki-farm-lifetime-short' => 90,
            'yeswiki-farm-welcome-mail-subject' => '',
            'yeswiki-farm-welcome-mail-body' => '',
            'yeswiki-farm-donate-url' => '',
        ]);
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendEmailFromAdmin')->willReturnCallback(
            function (string $address, string $subject, string $text): void {
                $this->sent[] = ['address' => $address, 'subject' => $subject, 'text' => $text];
            }
        );

        return new WelcomeMailer(
            $farm,
            new FarmMailer($farm, $this->createStub(EntryManager::class), $mailer, $this->createStub(WikiStatsStore::class)),
            new WikiLifetime($farm, $this->createStub(PageManager::class), self::getWiki()->services->get(UrlFormatter::class), Storage::rootedAt($this->scratch . '/farm'))
        );
    }

    /** @return array<string, string> */
    private function lastMail(): array
    {
        $this->assertNotEmpty($this->sent);

        return $this->sent[0];
    }
}
