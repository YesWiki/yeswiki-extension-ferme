<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Ferme\Service\FolderLock;
use YesWiki\Ferme\Service\WikiSymlinker;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(WikiSymlinker::class, 'inventory')]
#[CoversMethod(WikiSymlinker::class, 'inspect')]
#[CoversMethod(WikiSymlinker::class, 'link')]
#[CoversMethod(WikiSymlinker::class, 'unlink')]
class WikiSymlinkerTest extends YesWikiTestCase
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
        $this->tmp = sys_get_temp_dir() . '/ferme-symlink-' . bin2hex(random_bytes(6));

        $this->master = $this->tmp . '/maitre';
        $this->wikiDir = $this->tmp . '/maitre/monwiki';
        foreach (['javascripts/vendor', 'tools/bazar', 'custom'] as $dir) {
            mkdir($this->master . '/' . $dir, 0777, true);
            mkdir($this->wikiDir . '/' . $dir, 0777, true);
        }
        mkdir($this->master . '/tools/ferme/client', 0777, true);

        foreach (['javascripts/jquery.js' => 'un', 'javascripts/vendor/lib.js' => 'deux', 'tools/bazar/bazar.php' => 'trois'] as $file => $body) {
            file_put_contents($this->master . '/' . $file, $body);
            file_put_contents($this->wikiDir . '/' . $file, $body);
        }
        file_put_contents($this->wikiDir . '/custom/custom.css', 'body{}');

        $this->wikiApp->config['yeswiki-farm-lent-files'] = ['javascripts', 'tools/bazar', 'absent'];
        chdir($this->master);
    }

    protected function tearDown(): void
    {
        chdir($this->back);
        (new FileSystem())->rrmdir($this->tmp);
    }

    public function testAFolderHoldingTheSameFilesIsLent()
    {
        $plan = $this->byEntry($this->linker()->inspect($this->wikiDir));

        $this->assertSame('link', $plan['javascripts']['action']);
        $this->assertSame('FERME_SYMLINK_SAME', $plan['javascripts']['why']);
        $this->assertSame(6, $plan['javascripts']['bytes'], 'les deux fichiers du dossier');
    }

    public function testAFolderTheWikiHasChangedIsLeftAlone()
    {
        file_put_contents($this->wikiDir . '/tools/bazar/bazar.php', 'trois, plus un correctif maison');

        $plan = $this->byEntry($this->linker()->inspect($this->wikiDir));

        $this->assertSame('keep', $plan['tools/bazar']['action']);
        $this->assertSame('FERME_SYMLINK_DIFFERENT', $plan['tools/bazar']['why']);
    }

    public function testWhatTheMasterDoesNotHaveIsNotLent()
    {
        $plan = $this->byEntry($this->linker()->inspect($this->wikiDir));

        $this->assertSame('keep', $plan['absent']['action']);
        $this->assertSame('FERME_EXTRA_MISSING', $plan['absent']['why']);
    }

    public function testTheGuardIsAlwaysPutInPlace()
    {
        $plan = $this->byEntry($this->linker()->inspect($this->wikiDir));

        $this->assertSame('link', $plan[WikiSymlinker::GUARD_LINK]['action']);
    }

    public function testLendingReplacesTheCopiesAndLeavesTheWikisOwnFolders()
    {
        $report = $this->linker()->link($this->wikiDir, false);

        $this->assertSame(3, $report['linked'], 'javascripts, tools/bazar et le garde-fou');
        $this->assertTrue(is_link($this->wikiDir . '/javascripts'));
        $this->assertTrue(is_link($this->wikiDir . '/tools/bazar'));
        $this->assertSame($this->master . '/tools/ferme/client', readlink($this->wikiDir . '/' . WikiSymlinker::GUARD_LINK));
        $this->assertSame('deux', file_get_contents($this->wikiDir . '/javascripts/vendor/lib.js'), 'le wiki lit bien les fichiers du maître');
        $this->assertFalse(is_link($this->wikiDir . '/custom'), 'ce qui est au wiki reste au wiki');
        $this->assertSame('body{}', file_get_contents($this->wikiDir . '/custom/custom.css'));
    }

    public function testADryRunTouchesNothing()
    {
        $report = $this->linker()->link($this->wikiDir, true);

        $this->assertSame(3, $report['linked']);
        $this->assertFalse(is_link($this->wikiDir . '/javascripts'));
    }

    public function testComingBackPutsTheFilesInAndTakesTheGuardOut()
    {
        $linker = $this->linker();
        $linker->link($this->wikiDir, false);

        $report = $linker->unlink($this->wikiDir, false);

        $this->assertSame(3, $report['linked']);
        $this->assertFalse(is_link($this->wikiDir . '/javascripts'));
        $this->assertSame('deux', file_get_contents($this->wikiDir . '/javascripts/vendor/lib.js'), 'les fichiers sont revenus');
        $this->assertFileDoesNotExist($this->wikiDir . '/' . WikiSymlinker::GUARD_LINK);
    }

    public function testTheMasterIsNeverItsOwnWiki()
    {
        $this->expectException(\RuntimeException::class);
        $this->linker()->link($this->master, true);
    }

    public function testTwoFoldersAreTheSameWhenTheirFilesAndSizesMatch()
    {
        $this->assertSame(
            WikiSymlinker::inventory($this->master . '/javascripts'),
            WikiSymlinker::inventory($this->wikiDir . '/javascripts')
        );
        $this->assertSame(
            ['jquery.js' => 2, 'vendor/lib.js' => 4],
            WikiSymlinker::inventory($this->master . '/javascripts')
        );
    }

    private function linker(): WikiSymlinker
    {
        $lock = new FolderLock();
        $lock->useDirectory($this->tmp . '/locks');

        return new WikiSymlinker($this->wikiApp, $this->createStub(FarmConfig::class), new FileSystem(), $lock);
    }

    /**
     * @param array<int,array<string,mixed>> $plan
     *
     * @return array<string,array<string,mixed>>
     */
    private function byEntry(array $plan): array
    {
        $byEntry = [];
        foreach ($plan as $step) {
            $byEntry[$step['entry']] = $step;
        }

        return $byEntry;
    }
}
