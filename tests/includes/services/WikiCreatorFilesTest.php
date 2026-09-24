<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\WikiCreator;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(WikiCreator::class, 'createFromEntry')]
class WikiCreatorFilesTest extends YesWikiTestCase
{
    private string $tmp;
    private string $source;
    private string $destination;

    protected function setUp(): void
    {
        self::getWiki();
        $this->tmp = sys_get_temp_dir() . '/ferme-creator-' . uniqid();
        $this->source = $this->tmp . '/source/';
        $this->destination = $this->tmp . '/nouveau/';

        foreach (['tools/aceditor', 'tools/bazar', 'themes/margot'] as $folder) {
            mkdir($this->source . $folder, 0777, true);
            file_put_contents($this->source . $folder . '/index.php', 'x');
        }
        file_put_contents($this->source . 'tools/README.md', 'ce dossier contient les extensions');
        file_put_contents($this->source . 'index.php', 'x');
        mkdir($this->source . 'tools/ferme/client', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->wipe($this->tmp);
    }

    public function testALoneFileGetsTheFolderAboveItEvenWhenEverythingElseIsBorrowed()
    {
        $this->copyFiles(['tools/aceditor', 'tools/bazar', 'themes/margot']);

        $this->assertFileExists($this->destination . 'tools/README.md', 'le fichier qui faisait échouer la création');
        $this->assertSame('ce dossier contient les extensions', file_get_contents($this->destination . 'tools/README.md'));
        $this->assertFileExists($this->destination . 'index.php');
    }

    public function testWhatTheWikiBorrowsIsALinkAndWhatItOwnsIsACopy()
    {
        $this->copyFiles(['tools/aceditor', 'tools/bazar', 'themes/margot']);

        $this->assertTrue(is_link($this->destination . 'tools/aceditor'), 'emprunté à la ferme');
        $this->assertTrue(is_link($this->destination . 'themes/margot'), 'le dossier themes n\'existait pas non plus');
        $this->assertSame($this->source . 'tools/aceditor', readlink($this->destination . 'tools/aceditor'));
        $this->assertFalse(is_link($this->destination . 'tools/README.md'));
        $this->assertDirectoryExists($this->destination . 'cache');
    }

    public function testToolsAndThemesStayRealFoldersSoAWikiCanHaveItsOwn()
    {
        $this->copyFiles(['tools/aceditor', 'tools/bazar', 'themes/margot']);

        foreach (['tools', 'themes'] as $folder) {
            $this->assertDirectoryExists($this->destination . $folder);
            $this->assertFalse(
                is_link($this->destination . $folder),
                $folder . ' emprunté en entier, le wiki ne pourrait plus avoir les siens'
            );
        }

        mkdir($this->destination . 'tools/monextension');
        $this->assertDirectoryExists($this->destination . 'tools/monextension');
    }

    public function testTheFarmLendsItsCoreExtensionsOneByOneAndNeverTheWholeFolder()
    {
        $shipped = \Symfony\Component\Yaml\Yaml::parseFile('tools/ferme/config.yaml')['parameters'];

        foreach (['yeswiki-farm-lent-files', 'yeswiki_symlinked_files'] as $setting) {
            $lent = $shipped[$setting];
            $this->assertNotContains('tools', $lent, $setting . ' : tools en entier');
            $this->assertNotContains('themes', $lent, $setting . ' : themes en entier');
            foreach ($lent as $entry) {
                $this->assertStringNotContainsString('ferme', $entry, 'la ferme ne se prête pas à ses wikis');
            }
        }
    }

    public function testAFarmThatBorrowsNothingStillCopiesEverything()
    {
        $this->copyFiles([]);

        $this->assertFileExists($this->destination . 'tools/README.md');
        $this->assertFileExists($this->destination . 'tools/aceditor/index.php');
        $this->assertFalse(is_link($this->destination . 'tools/aceditor'));
    }

    public function testANewWikiGetsTheGuardThatRefusesAFarmInsideIt()
    {
        $this->copyFiles([]);

        $this->assertSame($this->source . 'tools/ferme/client', readlink($this->destination . 'tools/ferme-client'));
        $this->assertDirectoryDoesNotExist($this->destination . 'tools/ferme');
    }

    public function testAnExtraThemeTheFarmAlreadyLendsIsNotCopiedOntoItsOwnLink()
    {
        $this->copyFiles(['tools/aceditor', 'tools/bazar', 'themes/margot'], ['margot'], ['bazar']);

        $this->assertTrue(is_link($this->destination . 'themes/margot'));
        $this->assertTrue(is_link($this->destination . 'tools/bazar'));
        $this->assertSame('x', file_get_contents($this->source . 'themes/margot/index.php'));
    }

    /**
     * @param array<int,string> $symlinked
     * @param array<int,string> $extraThemes
     * @param array<int,string> $extraTools
     */
    private function copyFiles(array $symlinked, array $extraThemes = [], array $extraTools = []): void
    {
        $wiki = self::getWiki();
        $wiki->config['yeswiki_files'] = ['index.php', 'tools/aceditor', 'tools/bazar', 'tools/README.md', 'themes/margot'];
        $wiki->config['yeswiki_empty_folders'] = ['cache', 'custom', 'files', 'private'];
        $wiki->config['yeswiki_symlinked_files'] = $symlinked;
        $wiki->config['yeswiki-farm-extra-themes'] = $extraThemes;
        $wiki->config['yeswiki-farm-extra-tools'] = $extraTools;

        $creator = $wiki->services->get(WikiCreator::class);
        $method = new \ReflectionMethod(WikiCreator::class, 'copyWikiFiles');
        $method->invoke($creator, $this->source, $this->destination);
    }

    private function wipe(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->wipe($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}
