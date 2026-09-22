<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Core\Entity\ConfigurationFile;
use YesWiki\Core\Service\ConfigurationService;
use YesWiki\Ferme\Exception\WikiBusyException;
use YesWiki\Ferme\Service\CustomAside;
use YesWiki\Ferme\Service\ExtensionVersions;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Ferme\Service\FolderLock;
use YesWiki\Ferme\Service\StatsRefresher;
use YesWiki\Ferme\Service\WikiConfigEditor;
use YesWiki\Ferme\Service\WikiDatabase;
use YesWiki\Ferme\Service\WikiHibernator;
use YesWiki\Ferme\Service\WikiUpdater;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(WikiUpdater::class, 'update')]
class WikiUpdaterTest extends YesWikiTestCase
{
    private string $tmp;
    private string $master;
    private string $wikiDir;
    private string $back;
    private $wikiApp;

    protected function setUp(): void
    {
        $this->wikiApp = self::getWiki();
        $this->back = (string)getcwd();
        $this->tmp = sys_get_temp_dir() . '/ferme-update-' . bin2hex(random_bytes(6));
        $this->master = $this->tmp . '/maitre';
        $this->wikiDir = $this->master . '/monwiki';

        mkdir($this->master . '/includes/commands', 0777, true);
        mkdir($this->wikiDir . '/includes/commands', 0777, true);
        file_put_contents(
            $this->master . '/includes/constants.php',
            "<?php\ndefine('YESWIKI_VERSION', 'doryphore');\ndefine('YESWIKI_RELEASE', '4.6.8');\n"
        );
        file_put_contents($this->master . '/includes/commands/console', "#!/usr/bin/env php\n<?php\necho \"rien a migrer\\n\";\n");
        chmod($this->master . '/includes/commands/console', 0755);
        file_put_contents($this->wikiDir . '/includes/constants.php', "<?php\ndefine('YESWIKI_RELEASE', '4.6.0');\n");
        copy($this->master . '/includes/commands/console', $this->wikiDir . '/includes/commands/console');
        chmod($this->wikiDir . '/includes/commands/console', 0755);
        $this->writeConfig('hibernate');

        $this->wikiApp->config['yeswiki_files'] = ['includes'];
        $this->wikiApp->config['yeswiki_symlinked_files'] = [];
        $this->wikiApp->config['yeswiki_empty_folders'] = [];
        $this->wikiApp->config['yeswiki-farm-extra-tools'] = [];

        class_exists(ConfigurationFile::class);
        class_exists(WikiBusyException::class);
        chdir($this->master);
    }

    protected function tearDown(): void
    {
        chdir($this->back);
        (new FileSystem())->rrmdir($this->tmp);
    }

    public function testAHibernatingWikiIsUpdatedThenPutBackToSleep()
    {
        $report = $this->updater()->update($this->wikiDir, ['backup' => false, 'sourceDir' => $this->master]);

        $this->assertSame('updated', $report['status']);
        $this->assertStringContainsString('4.6.8', file_get_contents($this->wikiDir . '/includes/constants.php'), 'le wiki a le nouveau coeur');
        $this->assertSame('hibernate', $this->readConfig()['wiki_status'], 'le wiki se rendort');
        $this->assertSame($this->wikiApp->config['yeswiki_release'], $this->readConfig()['yeswiki_release'], 'la version du maitre est posee');
        $this->assertSame($this->wikiApp->config['yeswiki_version'], $this->readConfig()['yeswiki_version']);
    }

    public function testAnAwakeWikiKeepsItsStatus()
    {
        $this->writeConfig('running');

        $this->updater()->update($this->wikiDir, ['backup' => false, 'sourceDir' => $this->master]);

        $this->assertSame('running', $this->readConfig()['wiki_status']);
    }

    public function testMigrateOnlyLeavesTheFilesAloneAndStillStampsTheVersion()
    {
        $report = $this->updater()->update($this->wikiDir, ['backup' => false, 'sourceDir' => $this->master, 'migrateOnly' => true]);

        $this->assertSame('updated', $report['status']);
        $this->assertStringContainsString('4.6.0', file_get_contents($this->wikiDir . '/includes/constants.php'), 'le coeur du wiki n\'a pas bouge');
        $this->assertSame($this->wikiApp->config['yeswiki_release'], $this->readConfig()['yeswiki_release']);
        $this->assertSame('hibernate', $this->readConfig()['wiki_status']);
    }

    public function testAWikiCoreIsWorkingOnIsRefused()
    {
        $this->writeConfig('updating');

        $this->expectException(WikiBusyException::class);
        $this->updater()->update($this->wikiDir, ['backup' => false, 'sourceDir' => $this->master]);
    }

    private function updater(): WikiUpdater
    {
        $lock = new FolderLock();
        $lock->useDirectory($this->tmp . '/locks');
        $editor = new WikiConfigEditor(
            $this->wikiApp,
            $this->createStub(FarmConfig::class),
            $this->wikiApp->services->get(ConfigurationService::class)
        );
        $hibernator = new WikiHibernator($this->createStub(FarmConfig::class), $editor, $lock);

        return new WikiUpdater(
            $this->wikiApp,
            $this->createStub(FarmConfig::class),
            new FileSystem(),
            $editor,
            $this->createStub(WikiDatabase::class),
            $this->createStub(CustomAside::class),
            $this->createStub(ExtensionVersions::class),
            $lock,
            $hibernator,
            $this->createStub(StatsRefresher::class)
        );
    }

    private function writeConfig(string $status): void
    {
        file_put_contents(
            $this->wikiDir . '/wakka.config.php',
            "<?php\n\$wakkaConfig = [\n  'wakka_name' => 'Mon wiki',\n  'yeswiki_version' => 'doryphore',\n  'yeswiki_release' => '4.6.0',\n  'wiki_status' => '" . $status . "',\n];\n"
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function readConfig(): array
    {
        $wakkaConfig = [];
        include $this->wikiDir . '/wakka.config.php';

        return $wakkaConfig;
    }
}
