<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Exception\FolderBusyException;
use YesWiki\Ferme\Exception\WikiAsleepException;
use YesWiki\Ferme\Service\FolderLock;
use YesWiki\Ferme\Service\WikiHibernator;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** A wiki put to sleep or woken by the wiki_status its own database stores. */
class WikiHibernatorTest extends FarmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->scratch . '/wikis/monwiki');
        $this->wikiWithDatabase($this->scratch . '/wikis/monwiki', 'http://farm.test/monwiki/?', ['yeswiki_name' => 'Mon wiki']);
        $this->farm();
    }

    public function testAWikiThatSaysNothingIsAwake(): void
    {
        $this->assertFalse(WikiHibernator::isAsleep(''));
        $this->assertFalse(WikiHibernator::isAsleep('running'));
        $this->assertTrue(WikiHibernator::isAsleep('hibernate'));
        $this->assertTrue(WikiHibernator::isAsleep('archiving'));
        $this->assertTrue(WikiHibernator::isAsleep('updating'));
        $this->assertSame(_t('FERME_STATUS_RUNNING'), WikiHibernator::label(''));
    }

    public function testSendingAWikiToSleepWritesItIntoItsOwnDatabase(): void
    {
        $result = $this->service(WikiHibernator::class)->hibernate('monwiki');

        $this->assertSame(['changed' => true, 'status' => 'hibernate', 'before' => 'running'], $result);
        $this->assertSame('hibernate', $this->read()['wiki_status']);
        $this->assertSame('Mon wiki', $this->read()['yeswiki_name'], 'nothing else is lost');
    }

    public function testWakingItUpPutsItBackInService(): void
    {
        $hibernator = $this->service(WikiHibernator::class);
        $hibernator->hibernate('monwiki');

        $this->assertSame(['changed' => true, 'status' => 'running', 'before' => 'hibernate'], $hibernator->wake('monwiki'));
        $this->assertSame('running', $this->read()['wiki_status']);
    }

    public function testAskingTwiceChangesNothingAndSaysSo(): void
    {
        $hibernator = $this->service(WikiHibernator::class);
        $hibernator->hibernate('monwiki');

        $this->assertFalse($hibernator->hibernate('monwiki')['changed']);
    }

    public function testASleepingWikiIsLeftAloneByEverythingElse(): void
    {
        $hibernator = $this->service(WikiHibernator::class);
        $hibernator->refuseIfAsleep('monwiki');
        $hibernator->hibernate('monwiki');

        $this->expectException(WikiAsleepException::class);
        $hibernator->refuseIfAsleep('monwiki');
    }

    public function testWorkDoneWhileAwakePutsTheWikiBackToSleepEvenOnAFailure(): void
    {
        $hibernator = $this->service(WikiHibernator::class);
        $hibernator->hibernate('monwiki');

        try {
            $hibernator->whileAwake('monwiki', function () use ($hibernator): void {
                $this->assertSame('running', $hibernator->statusOf('monwiki'));

                throw new \RuntimeException('la base est tombée');
            });
        } catch (\RuntimeException $failed) {
            $this->assertSame('la base est tombée', $failed->getMessage());
        }

        $this->assertSame('hibernate', $hibernator->statusOf('monwiki'));
        $this->assertSame(7, $hibernator->whileAwake('monwiki', static fn (): int => 7));
    }

    public function testAWikiBeingWorkedOnIsLeftAlone(): void
    {
        $this->service(FolderLock::class)->acquire($this->scratch . '/wikis/monwiki', 'mise à jour');
        $other = new FolderLock($this->scratchLocks());
        $other->useDirectory($this->scratch . '/locks');
        $hibernator = new WikiHibernator($this->service(\YesWiki\Ferme\Service\FarmConfig::class), $this->service(\YesWiki\Ferme\Service\WikiConfigEditor::class), $other);

        $this->expectException(FolderBusyException::class);
        $hibernator->hibernate('monwiki');
    }

    public function testAFolderWithoutAConfigurationIsRefused(): void
    {
        mkdir($this->scratch . '/wikis/vide');

        $this->expectException(\RuntimeException::class);
        $this->service(WikiHibernator::class)->hibernate('vide');
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        return $this->settingsOf($this->scratch . '/wikis/monwiki');
    }
}
