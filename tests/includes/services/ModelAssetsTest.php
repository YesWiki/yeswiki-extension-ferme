<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Core\Service\ArchiveService;
use YesWiki\Core\Service\RemoteBackupService;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Ferme\Service\ModelAssets;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(ModelAssets::class, 'localWikiPath')]
#[CoversMethod(ModelAssets::class, 'isModelContent')]
#[CoversMethod(ModelAssets::class, 'advance')]
class ModelAssetsTest extends YesWikiTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ferme-model-assets-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/farm/monwiki', 0777, true);
        mkdir($this->tmp . '/backups', 0777, true);
        mkdir($this->tmp . '/model', 0777, true);
        file_put_contents($this->tmp . '/farm/monwiki/wakka.config.php', '<?php');
    }

    protected function tearDown(): void
    {
        (new FileSystem())->rrmdir($this->tmp);
    }

    public function testAWikiOfTheFarmIsFoundOnDiskAndOneElsewhereIsNot()
    {
        $assets = $this->assets();

        $this->assertSame($this->tmp . '/farm/monwiki', $assets->localWikiPath('https://ferme.example.org/monwiki'));
        $this->assertSame($this->tmp . '/farm/monwiki', $assets->localWikiPath('https://ferme.example.org/monwiki/'));
        $this->assertSame(rtrim(getcwd(), '/'), $assets->localWikiPath('https://ferme.example.org'));
        $this->assertNull($assets->localWikiPath('https://ailleurs.org/wiki'));
        $this->assertNull($assets->localWikiPath('https://ferme.example.org/pas-un-wiki'));
        $this->assertNull($assets->localWikiPath(''));
    }

    public function testOnlyTheContentFoldersComeOutOfABackup()
    {
        $assets = $this->assets();

        foreach (['files/photo.jpg', 'files/PageAccueil/photo.jpg', 'custom/custom.css', 'custom/templates/a.twig'] as $kept) {
            $this->assertTrue($assets->isModelContent($kept), "'$kept' should be kept");
        }
        foreach ([
            'wakka.config.php',
            'index.php',
            'vendor/lib.php',
            'private/backups/content.sql',
            'custom/wiki-models/autre/default-content.sql',
            'custom/cache/thing.txt',
            'files/../../etc/passwd',
            '/etc/passwd',
        ] as $dropped) {
            $this->assertFalse($assets->isModelContent($dropped), "'$dropped' should be left out");
        }
    }

    public function testAWikiOfTheFarmIsCopiedWithoutOpeningAJob()
    {
        mkdir($this->tmp . '/farm/monwiki/files/PageAccueil', 0777, true);
        mkdir($this->tmp . '/farm/monwiki/custom/wiki-models/autre', 0777, true);
        file_put_contents($this->tmp . '/farm/monwiki/files/PageAccueil/photo.jpg', 'PLEINE-QUALITE');
        file_put_contents($this->tmp . '/farm/monwiki/custom/custom.css', 'body{}');
        file_put_contents($this->tmp . '/farm/monwiki/custom/wiki-models/autre/default-content.sql', 'SELECT 1;');

        $result = $this->assets()->start('monmodele', 'https://ferme.example.org/monwiki');

        $this->assertFalse($result['running']);
        $this->assertFileExists($this->tmp . '/model/files/PageAccueil/photo.jpg');
        $this->assertFileExists($this->tmp . '/model/custom/custom.css');
        $this->assertFileDoesNotExist($this->tmp . '/model/custom/wiki-models/autre/default-content.sql');
        $this->assertFileDoesNotExist($this->tmp . '/' . ModelAssets::JOB_FILENAME);
    }

    public function testAWikiElsewhereWithoutAnAccountFetchesNothingAndSaysSo()
    {
        $result = $this->assets()->start('monmodele', 'https://ailleurs.org/wiki');

        $this->assertFalse($result['running']);
        $this->assertNotEmpty($result['messages']);
        $this->assertFileDoesNotExist($this->tmp . '/' . ModelAssets::JOB_FILENAME);
    }

    public function testUnpackingReplacesWhatTheModelHeld()
    {
        file_put_contents($this->tmp . '/model/default-content.sql', 'SELECT 1;');
        mkdir($this->tmp . '/model/files', 0777, true);
        file_put_contents($this->tmp . '/model/files/perime.jpg', 'vieux');

        $zip = $this->backupZip();
        $assets = $this->assets($this->finishedBackup(basename($zip)));
        $this->writeJob();

        $result = $assets->advance();

        $this->assertFalse($result['running']);
        $this->assertFileExists($this->tmp . '/model/files/photo.jpg');
        $this->assertSame('PLEINE-QUALITE', file_get_contents($this->tmp . '/model/files/photo.jpg'));
        $this->assertFileExists($this->tmp . '/model/files/orphelin.pdf');
        $this->assertFileExists($this->tmp . '/model/custom/custom.css');
        $this->assertFileDoesNotExist($this->tmp . '/model/files/perime.jpg');
        $this->assertFileDoesNotExist($this->tmp . '/model/custom/wiki-models/autre/default-content.sql');
        $this->assertFileDoesNotExist($this->tmp . '/model/wakka.config.php');
        $this->assertFileExists($this->tmp . '/model/default-content.sql');
        $this->assertFileDoesNotExist($zip, 'the downloaded backup should be cleaned up');
    }

    public function testAFetchThatFailsLeavesTheModelAsItWas()
    {
        mkdir($this->tmp . '/model/files', 0777, true);
        file_put_contents($this->tmp . '/model/files/deja-la.jpg', 'garde-moi');

        $backup = $this->createStub(RemoteBackupService::class);
        $backup->method('status')->willReturn(['step' => 'idle', 'running' => false, 'error' => 'the remote gave up']);
        $assets = $this->assets($backup);
        $this->writeJob();

        try {
            $assets->advance();
            $this->fail('a failed fetch should be reported');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('the remote gave up', $exception->getMessage());
        }

        $this->assertFileExists($this->tmp . '/model/files/deja-la.jpg');
    }

    private function assets(?RemoteBackupService $backup = null): ModelAssets
    {
        $wiki = $this->getWiki();
        $wiki->config['base_url'] = 'https://ferme.example.org/?';

        $config = $this->createStub(FarmConfig::class);
        $config->method('rootUrl')->willReturn('https://ferme.example.org/');
        $config->method('wikiDir')->willReturnCallback(function (string $folder) {
            return $this->tmp . '/farm/' . $folder . '/';
        });
        $config->method('modelDir')->willReturn($this->tmp . '/model');
        $config->method('ensureBackupDir')->willReturn($this->tmp);

        $archive = $this->createStub(ArchiveService::class);
        $archive->method('getPrivateFolder')->willReturn($this->tmp . '/backups');

        return new ModelAssets(
            $wiki,
            $config,
            new FileSystem(),
            $archive,
            $backup ?? $this->createStub(RemoteBackupService::class)
        );
    }

    private function finishedBackup(string $filename): RemoteBackupService
    {
        $backup = $this->createStub(RemoteBackupService::class);
        $backup->method('status')->willReturn([
            'step' => 'done',
            'running' => false,
            'filename' => $filename,
        ]);

        return $backup;
    }

    /**
     * A backup as a source wiki asked for 'custom' and 'files' would send it: those two
     * folders, plus the root of the wiki, which always travels with an archive.
     */
    private function backupZip(): string
    {
        $path = $this->tmp . '/backups/2026-01-01T00-00-00_source_archive_only_files.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('index.php', '<?php');
        $zip->addFromString('wakka.config.php', '<?php $wakkaConfig = [];');
        $zip->addEmptyDir('cache');
        $zip->addFromString('custom/custom.css', 'body{}');
        $zip->addFromString('custom/wiki-models/autre/default-content.sql', 'SELECT 1;');
        $zip->addFromString('custom/cache/thing.txt', 'cache');
        $zip->addFromString('files/photo.jpg', 'PLEINE-QUALITE');
        $zip->addFromString('files/orphelin.pdf', 'lie a aucune page');
        $zip->close();

        return $path;
    }

    private function writeJob(): void
    {
        file_put_contents(
            $this->tmp . '/' . ModelAssets::JOB_FILENAME,
            json_encode(['model' => 'monmodele', 'baseUrl' => 'https://source.example.net', 'startedAt' => time()])
        );
    }
}
