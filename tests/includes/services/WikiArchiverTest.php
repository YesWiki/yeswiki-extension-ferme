<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Ferme\Service\FolderLock;
use YesWiki\Ferme\Service\StatsRefresher;
use YesWiki\Ferme\Service\WikiArchiver;
use YesWiki\Ferme\Service\WikiHibernator;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(WikiArchiver::class, 'tidy')]
#[CoversMethod(WikiArchiver::class, 'ensurePrivate')]
#[CoversMethod(WikiArchiver::class, 'archives')]
#[CoversMethod(WikiArchiver::class, 'pathOf')]
#[CoversMethod(WikiArchiver::class, 'forget')]
class WikiArchiverTest extends YesWikiTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        self::getWiki();
        $this->tmp = sys_get_temp_dir() . '/ferme-archive-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/monwiki', 0777, true);
        file_put_contents($this->tmp . '/monwiki/wakka.config.php', "<?php\n\$wakkaConfig = ['table_prefix' => 'yw_'];\n");
    }

    protected function tearDown(): void
    {
        (new FileSystem())->rrmdir($this->tmp);
    }

    public function testAWikiWithoutAPrivateFolderIsGivenOne()
    {
        $this->assertDirectoryDoesNotExist($this->tmp . '/monwiki/private');

        $this->assertTrue($this->archiver()->ensurePrivate('monwiki'));

        $this->assertDirectoryExists($this->tmp . '/monwiki/private');
        $this->assertDirectoryExists($this->tmp . '/monwiki/private/backups', 'ce que le cœur exige pour s\'archiver');
        $this->assertStringContainsString('DENY FROM ALL', (string)file_get_contents($this->tmp . '/monwiki/private/.htaccess'));
    }

    public function testAWikiThatAlreadyHasOneIsLeftAlone()
    {
        $archiver = $this->archiver();
        $archiver->ensurePrivate('monwiki');

        $this->assertFalse($archiver->ensurePrivate('monwiki'), 'rien à créer la deuxième fois');
    }

    public function testAnArchiveNobodyCameToFetchIsThrownOutAfterAWeek()
    {
        $this->archiver()->ensurePrivate('monwiki');
        $vieille = $this->archive('vieille.zip', strtotime('-8 days'));
        $fraiche = $this->archive('fraiche.zip', strtotime('-2 days'));

        $report = $this->archiver()->tidy('monwiki');

        $this->assertSame(1, $report['removed']);
        $this->assertFileDoesNotExist($vieille);
        $this->assertFileExists($fraiche, 'celle de la semaine reste');
    }

    public function testTheFarmDecidesHowLongAnArchiveStays()
    {
        $this->archiver()->ensurePrivate('monwiki');
        $this->archive('avant-hier.zip', strtotime('-2 days'));

        $this->assertSame(0, $this->archiver()->tidy('monwiki', 604800)['removed']);
        $this->assertSame(1, $this->archiver()->tidy('monwiki', 3600)['removed']);
    }

    public function testAnArchiveIsNamedAndFoundByItsOwnWiki()
    {
        $this->archiver()->ensurePrivate('monwiki');
        $this->archive('sauvegarde.zip', time());

        $archiver = $this->archiver();
        $this->assertNotNull($archiver->pathOf('monwiki', 'sauvegarde.zip'));
        $this->assertNull($archiver->pathOf('monwiki', '../../wakka.config.php'), 'on ne sort pas du dossier');
        $this->assertNull($archiver->pathOf('monwiki', 'inconnue.zip'));
        $this->assertCount(1, $archiver->archives('monwiki'));
        $this->assertSame(1, $archiver->forget('monwiki', ['sauvegarde.zip']));
        $this->assertSame([], $archiver->archives('monwiki'));
    }

    private function archive(string $name, int $time): string
    {
        $path = $this->tmp . '/monwiki/private/backups/' . $name;
        file_put_contents($path, 'PK');
        touch($path, $time);

        return $path;
    }

    private function archiver(): WikiArchiver
    {
        $config = $this->createStub(FarmConfig::class);
        $config->method('wikiDir')->willReturnCallback(function (string $folder) {
            return $this->tmp . '/' . $folder . '/';
        });
        $config->method('readWikiConfig')->willReturn(['table_prefix' => 'yw_']);

        $lock = new FolderLock();
        $lock->useDirectory($this->tmp . '/locks');

        return new WikiArchiver($config, $lock, $this->createStub(WikiHibernator::class), $this->createStub(StatsRefresher::class));
    }
}
