<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\WikiArchiver;
use YesWiki\Ferme\Service\WikiCreator;
use YesWiki\Ferme\Service\WikiHibernator;
use YesWiki\Ferme\Service\WikiRemover;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** A wiki's own archive, made by its core:archive, handed over, and thrown out when nobody came for it. */
class WikiArchiverTest extends FarmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->scratch . '/wikis/monwiki/private/backups', 0o755, true);
        file_put_contents($this->scratch . '/wikis/monwiki/yeswiki.config.php', "<?php\n\n\$yeswikiConfig = ['base_url' => 'http://farm.test/monwiki/?'];\n");
        $this->farm();
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            $this->service(WikiStatsStore::class)->forget('archived');
        }
        parent::tearDown();
    }

    public function testAnArchiveNobodyCameToFetchIsThrownOutAfterAWeek(): void
    {
        $vieille = $this->archive('vieille.zip', (int)strtotime('-8 days'));
        $fraiche = $this->archive('fraiche.zip', (int)strtotime('-2 days'));

        $report = $this->service(WikiArchiver::class)->tidy('monwiki');

        $this->assertSame(1, $report['removed']);
        $this->assertFileDoesNotExist($vieille);
        $this->assertFileExists($fraiche);
    }

    public function testTheFarmDecidesHowLongAnArchiveStays(): void
    {
        $this->archive('avant-hier.zip', (int)strtotime('-2 days'));

        $this->assertSame(0, $this->service(WikiArchiver::class)->tidy('monwiki', 604800)['removed']);
        $this->assertSame(1, $this->service(WikiArchiver::class)->tidy('monwiki', 3600)['removed']);
    }

    public function testAnArchiveIsNamedAndFoundByItsOwnWiki(): void
    {
        $this->archive('sauvegarde.zip', time());
        $archiver = $this->service(WikiArchiver::class);

        $this->assertNotNull($archiver->pathOf('monwiki', 'sauvegarde.zip'));
        $this->assertNull($archiver->pathOf('monwiki', '../../yeswiki.config.php'));
        $this->assertNull($archiver->pathOf('monwiki', 'inconnue.zip'));
        $this->assertCount(1, $archiver->archives('monwiki'));
        $this->assertSame(1, $archiver->forget('monwiki', ['sauvegarde.zip']));
        $this->assertSame([], $archiver->archives('monwiki'));
    }

    public function testASleepingWikiIsArchivedByItsOwnCoreAndGoesBackToSleep(): void
    {
        $dir = $this->service(WikiCreator::class)->createFromEntry($this->entry('archived'), 'bf_dossier-wiki');
        $this->service(WikiHibernator::class)->hibernate('archived');

        $made = $this->service(WikiArchiver::class)->create('archived');

        $this->assertFileExists($dir . '/private/backups/' . $made['file']);
        $this->assertGreaterThan(0, $made['bytes']);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($dir . '/private/backups/' . $made['file']));
        $this->assertNotFalse($zip->locateName('private/backups/content.sql'), 'a full archive, database included');
        $zip->close();
        $this->assertSame('hibernate', $this->service(WikiHibernator::class)->statusOf('archived'));

        $this->service(WikiRemover::class)->destroy('archived', null);
        $this->assertDirectoryDoesNotExist($dir);
    }

    private function archive(string $name, int $time): string
    {
        $path = $this->scratch . '/wikis/monwiki/private/backups/' . $name;
        file_put_contents($path, 'PK');
        touch($path, $time);

        return $path;
    }
}
