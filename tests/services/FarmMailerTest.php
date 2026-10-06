<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Content\Service\EntryManager;
use YesWiki\Ferme\Service\FarmMailer;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Kernel\Service\Mailer;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** Mails to the people who run the farm's wikis. */
class FarmMailerTest extends FarmTestCase
{
    public function testAMailGoesToTheAddressOnTheEntryAndNowhereElse(): void
    {
        $sent = [];
        $mailer = $this->mailerSpy($sent);
        $farmMailer = $this->farmMailer($this->fiche(), $mailer);

        $address = $farmMailer->sendToReferent('fiche-alpha', 'Bonjour', 'Votre wiki {title} va bien.');

        $this->assertSame('louise@exemple.org', $address);
        $this->assertCount(1, $sent);
        $this->assertSame('louise@exemple.org', $sent[0]['address']);
        $this->assertSame('Bonjour', $sent[0]['subject']);
        $this->assertSame('Votre wiki Wiki de Louise va bien.', $sent[0]['text']);
    }

    public function testWhatAnAdminCanPointAtInTheirMessage(): void
    {
        $farmMailer = $this->farmMailer($this->fiche());

        $filled = $farmMailer->fill('{title} · {url} · {referent} · {folder}', $this->fiche());

        $this->assertStringContainsString('Wiki de Louise', $filled);
        $this->assertStringContainsString('louise', $filled);
        $this->assertStringContainsString('Louise Michel', $filled);
        $this->assertStringNotContainsString('{', $filled);
    }

    public function testAPlaceholderNobodyDefinedIsLeftAsItIs(): void
    {
        $filled = $this->farmMailer($this->fiche())->fill('Bonjour {inconnu}', $this->fiche());

        $this->assertSame('Bonjour {inconnu}', $filled);
    }

    public function testAWikiWithoutAnAddressIsRefusedRatherThanSentNowhere(): void
    {
        $sent = [];
        $entry = array_merge($this->fiche(), ['bf_mail' => '']);

        try {
            $this->farmMailer($entry, $this->mailerSpy($sent))->sendToReferent('fiche-alpha', 'Bonjour', 'Un mot');
            $this->fail('a wiki with no address should be refused');
        } catch (\RuntimeException $exception) {
            $this->assertSame(_t('FERME_MAIL_NO_ADDRESS'), $exception->getMessage());
        }

        $this->assertSame([], $sent);
    }

    public function testAnAddressThatIsNotOneIsRefusedToo(): void
    {
        $this->expectException(\RuntimeException::class);
        $entry = array_merge($this->fiche(), ['bf_mail' => 'pas-une-adresse']);
        $this->farmMailer($entry)->sendToReferent('fiche-alpha', 'Bonjour', 'Un mot');
    }

    public function testAnEntryThatIsNotAWikiOfTheFarmIsRefused(): void
    {
        $entry = array_merge($this->fiche(), ['form_id' => '42']);

        $this->expectException(\RuntimeException::class);
        $this->farmMailer($entry)->sendToReferent('fiche-alpha', 'Bonjour', 'Un mot');
    }

    public function testAnEmptySubjectOrMessageIsRefused(): void
    {
        foreach ([['', 'Un mot'], ['Bonjour', ''], ['Bonjour', '   ']] as $letter) {
            try {
                $this->farmMailer($this->fiche())->sendToReferent('fiche-alpha', $letter[0], $letter[1]);
                $this->fail('an empty letter should be refused');
            } catch (\RuntimeException $exception) {
                $this->assertSame(_t('FERME_MAIL_EMPTY'), $exception->getMessage());
            }
        }
    }

    /** @return array<string, string> */
    private function fiche(): array
    {
        return [
            'tag' => 'fiche-alpha',
            'form_id' => '1100',
            'bf_titre' => 'Wiki de Louise',
            'bf_dossier-wiki' => 'louise',
            'bf_referent' => 'Louise Michel',
            'bf_mail' => 'louise@exemple.org',
        ];
    }

    /** @param list<array<string, string>> $sent */
    private function mailerSpy(array &$sent): Mailer
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendEmailFromAdmin')->willReturnCallback(
            function (string $address, string $subject, string $text) use (&$sent): void {
                $sent[] = ['address' => $address, 'subject' => $subject, 'text' => $text];
            }
        );

        return $mailer;
    }

    /** @param array<string, string> $entry */
    private function farmMailer(array $entry, ?Mailer $mailer = null): FarmMailer
    {
        $entryManager = $this->createStub(EntryManager::class);
        $entryManager->method('isEntry')->willReturn(true);
        $entryManager->method('getOne')->willReturn($entry);

        $store = $this->createStub(WikiStatsStore::class);
        $store->method('read')->willReturn(['lastActivity' => '2026-09-15 17:33:27']);

        return new FarmMailer($this->farm(['bazar_farm_id' => '1100', 'yeswiki-farm-root-url' => 'https://farm.test/']), $entryManager, $mailer ?? $this->createStub(Mailer::class), $store);
    }
}
