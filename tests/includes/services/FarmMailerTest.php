<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Service\Mailer;
use YesWiki\Ferme\Service\FarmMailer;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(FarmMailer::class, 'sendToReferent')]
#[CoversMethod(FarmMailer::class, 'fill')]
class FarmMailerTest extends YesWikiTestCase
{
    protected function setUp(): void
    {
        self::getWiki();
    }

    public function testAMailGoesToTheAddressOnTheEntryAndNowhereElse()
    {
        $sent = [];
        $mailer = $this->mailerSpy($sent);
        $farmMailer = $this->farmMailer($this->entry(), $mailer);

        $address = $farmMailer->sendToReferent('FicheAlpha', 'Bonjour', 'Votre wiki {title} va bien.');

        $this->assertSame('louise@exemple.org', $address);
        $this->assertCount(1, $sent);
        $this->assertSame('louise@exemple.org', $sent[0]['address']);
        $this->assertSame('Bonjour', $sent[0]['subject']);
        $this->assertSame('Votre wiki Wiki de Louise va bien.', $sent[0]['text']);
    }

    public function testWhatAnAdminCanPointAtInTheirMessage()
    {
        $farmMailer = $this->farmMailer($this->entry());

        $filled = $farmMailer->fill('{title} · {url} · {referent} · {folder}', $this->entry());

        $this->assertStringContainsString('Wiki de Louise', $filled);
        $this->assertStringContainsString('louise', $filled);
        $this->assertStringContainsString('Louise Michel', $filled);
        $this->assertStringNotContainsString('{', $filled, 'every placeholder was replaced');
    }

    public function testAPlaceholderNobodyDefinedIsLeftAsItIs()
    {
        $filled = $this->farmMailer($this->entry())->fill('Bonjour {inconnu}', $this->entry());

        $this->assertSame('Bonjour {inconnu}', $filled);
    }

    public function testAWikiWithoutAnAddressIsRefusedRatherThanSentNowhere()
    {
        $sent = [];
        $entry = array_merge($this->entry(), ['bf_mail' => '']);

        try {
            $this->farmMailer($entry, $this->mailerSpy($sent))->sendToReferent('FicheAlpha', 'Bonjour', 'Un mot');
            $this->fail('a wiki with no address should be refused');
        } catch (\RuntimeException $exception) {
            $this->assertSame(_t('FERME_MAIL_NO_ADDRESS'), $exception->getMessage());
        }

        $this->assertSame([], $sent, 'and nothing left');
    }

    public function testAnAddressThatIsNotOneIsRefusedToo()
    {
        $this->expectException(\RuntimeException::class);
        $entry = array_merge($this->entry(), ['bf_mail' => 'pas-une-adresse']);
        $this->farmMailer($entry)->sendToReferent('FicheAlpha', 'Bonjour', 'Un mot');
    }

    public function testAnEntryThatIsNotAWikiOfTheFarmIsRefused()
    {
        $entry = array_merge($this->entry(), ['id_typeannonce' => '42']);

        $this->expectException(\RuntimeException::class);
        $this->farmMailer($entry)->sendToReferent('FicheAlpha', 'Bonjour', 'Un mot');
    }

    public function testAnEmptySubjectOrMessageIsRefused()
    {
        foreach ([['', 'Un mot'], ['Bonjour', ''], ['Bonjour', '   ']] as $letter) {
            try {
                $this->farmMailer($this->entry())->sendToReferent('FicheAlpha', $letter[0], $letter[1]);
                $this->fail('an empty letter should be refused');
            } catch (\RuntimeException $exception) {
                $this->assertSame(_t('FERME_MAIL_EMPTY'), $exception->getMessage());
            }
        }
    }

    private function entry(): array
    {
        return [
            'id_fiche' => 'FicheAlpha',
            'id_typeannonce' => '1100',
            'bf_titre' => 'Wiki de Louise',
            'bf_dossier-wiki' => 'louise',
            'bf_referent' => 'Louise Michel',
            'bf_mail' => 'louise@exemple.org',
        ];
    }

    private function mailerSpy(array &$sent): Mailer
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendEmailFromAdmin')->willReturnCallback(
            function (string $address, string $subject, string $text) use (&$sent) {
                $sent[] = ['address' => $address, 'subject' => $subject, 'text' => $text];
            }
        );

        return $mailer;
    }

    private function farmMailer(array $entry, ?Mailer $mailer = null): FarmMailer
    {
        $wiki = self::getWiki();
        $wiki->config['bazar_farm_id'] = '1100';

        $entryManager = $this->createStub(EntryManager::class);
        $entryManager->method('isEntry')->willReturn(true);
        $entryManager->method('getOne')->willReturn($entry);

        $store = $this->createStub(WikiStatsStore::class);
        $store->method('read')->willReturn(['lastActivity' => '2026-09-15 17:33:27']);

        return new FarmMailer($wiki, $entryManager, $mailer ?? $this->createStub(Mailer::class), $store);
    }
}
