<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Core\Service\ConfigurationService;
use YesWiki\Ferme\Exception\FolderBusyException;
use YesWiki\Ferme\Exception\WikiAsleepException;
use YesWiki\Ferme\Exception\WikiBusyException;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Ferme\Service\FolderLock;
use YesWiki\Ferme\Service\WikiConfigEditor;
use YesWiki\Ferme\Service\WikiHibernator;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(WikiHibernator::class, 'hibernate')]
#[CoversMethod(WikiHibernator::class, 'wake')]
#[CoversMethod(WikiHibernator::class, 'isAsleep')]
class WikiHibernatorTest extends YesWikiTestCase
{
    private string $tmp;
    private FolderLock $lock;

    protected function setUp(): void
    {
        self::getWiki();
        $this->tmp = sys_get_temp_dir() . '/ferme-hibernation-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/monwiki', 0777, true);
        $this->write('monwiki', "<?php\n\$wakkaConfig = [\n  'wakka_name' => 'Mon wiki',\n  'table_prefix' => 'yw_monwiki_',\n];\n");
        $this->lock = new FolderLock();
        $this->lock->useDirectory($this->tmp . '/locks');
    }

    protected function tearDown(): void
    {
        (new FileSystem())->rrmdir($this->tmp);
    }

    public function testAWikiThatSaysNothingIsAwake()
    {
        $this->assertFalse(WikiHibernator::isAsleep(''));
        $this->assertFalse(WikiHibernator::isAsleep('running'));
        $this->assertTrue(WikiHibernator::isAsleep('hibernate'));
        $this->assertTrue(WikiHibernator::isAsleep('archiving'), 'core sets this while it works, and writes are refused');
        $this->assertTrue(WikiHibernator::isAsleep('updating'));
    }

    public function testAHibernatingWikiIsLeftToWhoeverCanPutItBackToSleep()
    {
        $this->write('monwiki', "<?php\n\$wakkaConfig = ['wiki_status' => 'hibernate'];\n");

        $this->expectNotToPerformAssertions();
        $this->hibernator()->refuseIfBusyIn($this->tmp . '/monwiki');
    }

    public function testAWikiCoreIsWorkingOnIsRefused()
    {
        $this->write('monwiki', "<?php\n\$wakkaConfig = ['wiki_status' => 'updating'];\n");

        $this->expectException(WikiBusyException::class);
        $this->hibernator()->refuseIfBusyIn($this->tmp . '/monwiki');
    }

    public function testSendingAWikiToSleepWritesItIntoItsOwnConfiguration()
    {
        $result = $this->hibernator()->hibernate('monwiki');

        $this->assertTrue($result['changed']);
        $this->assertSame('running', $result['before']);
        $this->assertSame('hibernate', $result['status']);
        $this->assertSame('hibernate', $this->read('monwiki')['wiki_status']);
        $this->assertSame('Mon wiki', $this->read('monwiki')['wakka_name'], 'the rest of the file is untouched');
    }

    public function testWakingItUpPutsItBackInService()
    {
        $hibernator = $this->hibernator();
        $hibernator->hibernate('monwiki');

        $result = $hibernator->wake('monwiki');

        $this->assertTrue($result['changed']);
        $this->assertSame('hibernate', $result['before']);
        $this->assertSame('running', $this->read('monwiki')['wiki_status']);
    }

    public function testAskingTwiceChangesNothingAndSaysSo()
    {
        $hibernator = $this->hibernator();
        $hibernator->hibernate('monwiki');
        $written = filemtime($this->tmp . '/monwiki/wakka.config.php');

        $again = $hibernator->hibernate('monwiki');

        $this->assertFalse($again['changed']);
        $this->assertSame('hibernate', $again['status']);
        $this->assertSame($written, filemtime($this->tmp . '/monwiki/wakka.config.php'));
    }

    public function testAWikiBeingWorkedOnIsLeftAlone()
    {
        $busy = new FolderLock();
        $busy->useDirectory($this->tmp . '/locks');
        $busy->acquire($this->tmp . '/monwiki/', 'mise à jour');

        $this->expectException(FolderBusyException::class);
        $this->hibernator()->hibernate('monwiki');
    }

    public function testAFolderWithoutAConfigurationIsRefused()
    {
        mkdir($this->tmp . '/vide');

        $this->expectException(\RuntimeException::class);
        $this->hibernator()->hibernate('vide');
    }

    public function testASleepingWikiIsLeftAloneByEverythingElse()
    {
        $hibernator = $this->hibernator();
        $hibernator->hibernate('monwiki');

        $this->expectException(WikiAsleepException::class);
        $hibernator->refuseIfAsleep('monwiki');
    }

    public function testAWikiInServiceIsNotRefusedAnything()
    {
        $this->hibernator()->refuseIfAsleep('monwiki');
        $this->hibernator()->refuseIfAsleepIn($this->tmp . '/monwiki');

        $this->assertTrue(true, 'aucune exception');
    }

    public function testTheRefusalWorksFromAPathToo()
    {
        $this->hibernator()->hibernate('monwiki');

        $this->expectException(WikiAsleepException::class);
        $this->hibernator()->refuseIfAsleepIn($this->tmp . '/monwiki/');
    }

    public function testAWikiCoreIsBusyWithIsAlsoLeftAlone()
    {
        $this->write('monwiki', "<?php\n\$wakkaConfig = ['wiki_status' => 'archiving', 'table_prefix' => 'yw_'];\n");

        $this->expectException(WikiAsleepException::class);
        $this->hibernator()->refuseIfAsleep('monwiki');
    }

    private function hibernator(): WikiHibernator
    {
        $config = $this->createStub(FarmConfig::class);
        $config->method('wikiDir')->willReturnCallback(function (string $folder) {
            return $this->tmp . '/' . $folder . '/';
        });
        $config->method('wikiConfigFile')->willReturnCallback(function (string $folder) {
            return $this->tmp . '/' . $folder . '/wakka.config.php';
        });
        $config->method('readWikiConfig')->willReturnCallback(function (string $folder) {
            $wakkaConfig = [];
            $path = $this->tmp . '/' . $folder . '/wakka.config.php';
            if (is_file($path)) {
                include $path;
            }

            return $wakkaConfig;
        });

        $editor = new WikiConfigEditor(
            self::getWiki(),
            $config,
            self::getWiki()->services->get(ConfigurationService::class)
        );

        return new WikiHibernator($config, $editor, $this->lock);
    }

    private function write(string $folder, string $content): void
    {
        file_put_contents($this->tmp . '/' . $folder . '/wakka.config.php', $content);
    }

    /**
     * @return array<string,mixed>
     */
    private function read(string $folder): array
    {
        $wakkaConfig = [];
        include $this->tmp . '/' . $folder . '/wakka.config.php';

        return $wakkaConfig;
    }
}
