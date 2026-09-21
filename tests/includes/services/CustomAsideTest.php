<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\CustomAside;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(CustomAside::class, 'hide')]
#[CoversMethod(CustomAside::class, 'reveal')]
#[CoversMethod(CustomAside::class, 'recover')]
#[CoversMethod(CustomAside::class, 'isAside')]
class CustomAsideTest extends YesWikiTestCase
{
    private string $tmp;
    private string $wikiDir;

    protected function setUp(): void
    {
        self::getWiki();
        $this->tmp = sys_get_temp_dir() . '/ferme-custom-aside-' . bin2hex(random_bytes(6));
        $this->wikiDir = $this->tmp . '/farm/monwiki';
        mkdir($this->wikiDir . '/custom/themes', 0777, true);
        mkdir($this->tmp . '/backups', 0777, true);
        file_put_contents($this->wikiDir . '/custom/custom.css', 'body{}');
    }

    protected function tearDown(): void
    {
        (new FileSystem())->rrmdir($this->tmp);
    }

    public function testCustomGoesAsideAndComesBackWithWhatItHeld()
    {
        $aside = $this->aside();

        $this->assertTrue($aside->hide($this->wikiDir));
        $this->assertFileDoesNotExist($this->wikiDir . '/custom');
        $this->assertFileExists($this->wikiDir . '/custom.temp/custom.css');
        $this->assertTrue($aside->isAside($this->wikiDir));

        $this->assertNull($aside->reveal($this->wikiDir));
        $this->assertSame('body{}', file_get_contents($this->wikiDir . '/custom/custom.css'));
        $this->assertDirectoryExists($this->wikiDir . '/custom/themes');
        $this->assertFileDoesNotExist($this->wikiDir . '/custom.temp');
        $this->assertFalse($aside->isAside($this->wikiDir));
    }

    public function testTheAsideSaysItIsOursAndTheRestoredFolderKeepsNoTrace()
    {
        $aside = $this->aside();
        $aside->hide($this->wikiDir);

        $this->assertFileExists($this->wikiDir . '/custom.temp/' . CustomAside::SENTINEL);

        $aside->reveal($this->wikiDir);

        $this->assertFileDoesNotExist($this->wikiDir . '/custom/' . CustomAside::SENTINEL);
    }

    public function testASymlinkedCustomIsMovedAsALinkAndPutBackAsOne()
    {
        (new FileSystem())->rrmdir($this->wikiDir . '/custom');
        mkdir($this->tmp . '/shared', 0777, true);
        file_put_contents($this->tmp . '/shared/custom.css', 'partage');
        symlink($this->tmp . '/shared', $this->wikiDir . '/custom');

        $aside = $this->aside();
        $aside->hide($this->wikiDir);

        $this->assertTrue(is_link($this->wikiDir . '/custom.temp'));
        $this->assertFileExists($this->tmp . '/shared/custom.css');

        $aside->reveal($this->wikiDir);

        $this->assertTrue(is_link($this->wikiDir . '/custom'));
        $this->assertSame($this->tmp . '/shared', readlink($this->wikiDir . '/custom'));
    }

    public function testHidingRefusesWhenSomethingIsAlreadyInTheWay()
    {
        mkdir($this->wikiDir . '/custom.temp');
        file_put_contents($this->wikiDir . '/custom.temp/autre.css', 'pas a nous');

        $this->expectException(\RuntimeException::class);
        $this->aside()->hide($this->wikiDir);
    }

    public function testAWikiWithoutCustomHasNothingToHide()
    {
        (new FileSystem())->rrmdir($this->wikiDir . '/custom');

        $this->assertFalse($this->aside()->hide($this->wikiDir));
    }

    public function testRevealingTwiceLeavesTheWikiAlone()
    {
        $aside = $this->aside();
        $aside->hide($this->wikiDir);
        $aside->reveal($this->wikiDir);
        $aside->reveal($this->wikiDir);

        $this->assertSame('body{}', file_get_contents($this->wikiDir . '/custom/custom.css'));
    }

    public function testAnAsideLeftByAnInterruptedRunIsPutBack()
    {
        $this->aside()->hide($this->wikiDir);

        $message = $this->aside()->recover($this->wikiDir);

        $this->assertNotNull($message);
        $this->assertSame('body{}', file_get_contents($this->wikiDir . '/custom/custom.css'));
        $this->assertFileDoesNotExist($this->wikiDir . '/custom.temp');
    }

    public function testRecoveringWithNothingAsideDoesNothing()
    {
        $this->assertNull($this->aside()->recover($this->wikiDir));
        $this->assertSame('body{}', file_get_contents($this->wikiDir . '/custom/custom.css'));
    }

    public function testACustomFolderFoundBackInItsPlaceIsKeptInTheBackups()
    {
        $this->aside()->hide($this->wikiDir);
        mkdir($this->wikiDir . '/custom');
        file_put_contents($this->wikiDir . '/custom/custom.css', 'remis par le webmestre');

        $message = $this->aside()->recover($this->wikiDir);

        $this->assertSame('body{}', file_get_contents($this->wikiDir . '/custom/custom.css'));

        $kept = glob($this->tmp . '/backups/customs/*/custom.css');
        $this->assertCount(1, $kept);
        $this->assertSame('remis par le webmestre', file_get_contents($kept[0]));
        $this->assertStringContainsString(dirname($kept[0]), (string)$message);
    }

    public function testAnEmptyCustomFoundBackInItsPlaceIsJustDropped()
    {
        $this->aside()->hide($this->wikiDir);
        mkdir($this->wikiDir . '/custom');

        $this->aside()->recover($this->wikiDir);

        $this->assertSame('body{}', file_get_contents($this->wikiDir . '/custom/custom.css'));
        $this->assertSame([], glob($this->tmp . '/backups/customs/*') ?: []);
    }

    public function testAnUnmarkedAsideNextToALiveCustomIsNotTouched()
    {
        mkdir($this->wikiDir . '/custom.temp');
        file_put_contents($this->wikiDir . '/custom.temp/a-moi.css', 'pas a nous');

        try {
            $this->aside()->recover($this->wikiDir);
            $this->fail('an unmarked aside next to a live custom/ should be refused');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('custom.temp', $exception->getMessage());
        }

        $this->assertFileExists($this->wikiDir . '/custom.temp/a-moi.css');
        $this->assertSame('body{}', file_get_contents($this->wikiDir . '/custom/custom.css'));
    }

    public function testAnUnmarkedAsideOfAWikiLeftWithoutCustomIsPutBack()
    {
        rename($this->wikiDir . '/custom', $this->wikiDir . '/custom.temp');

        $this->assertNotNull($this->aside()->recover($this->wikiDir));
        $this->assertSame('body{}', file_get_contents($this->wikiDir . '/custom/custom.css'));
        $this->assertFileDoesNotExist($this->wikiDir . '/custom.temp');
    }

    public function testAnAsideDroppedInsideCustomByHandIsFoundAndPutBack()
    {
        $aside = $this->aside();
        $aside->hide($this->wikiDir);
        mkdir($this->wikiDir . '/custom');
        file_put_contents($this->wikiDir . '/custom/custom.css', 'recree par la mise a jour');
        rename($this->wikiDir . '/custom.temp', $this->wikiDir . '/custom/custom.temp');

        $this->assertTrue($aside->isAside($this->wikiDir));

        $message = $aside->recover($this->wikiDir);

        $this->assertNotNull($message);
        $this->assertSame('body{}', file_get_contents($this->wikiDir . '/custom/custom.css'));
        $this->assertFileDoesNotExist($this->wikiDir . '/custom/custom.temp');
        $this->assertFalse($aside->isAside($this->wikiDir));

        $kept = glob($this->tmp . '/backups/customs/*/custom.css');
        $this->assertCount(1, $kept);
        $this->assertSame('recree par la mise a jour', file_get_contents($kept[0]));
    }

    public function testAnUnmarkedAsideInsideCustomIsReportedAndLeftAlone()
    {
        mkdir($this->wikiDir . '/custom/custom.temp');
        file_put_contents($this->wikiDir . '/custom/custom.temp/a-moi.css', 'pas a nous');

        $aside = $this->aside();
        $this->assertTrue($aside->isAside($this->wikiDir));

        try {
            $aside->recover($this->wikiDir);
            $this->fail('an unmarked aside inside custom/ should be refused');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('custom.temp', $exception->getMessage());
        }

        $this->assertFileExists($this->wikiDir . '/custom/custom.temp/a-moi.css');
        $this->assertSame('body{}', file_get_contents($this->wikiDir . '/custom/custom.css'));
    }

    public function testAnAsideInsideASymlinkedCustomBelongsToWhatTheLinkPointsAt()
    {
        (new FileSystem())->rrmdir($this->wikiDir . '/custom');
        mkdir($this->tmp . '/shared/custom.temp', 0777, true);
        symlink($this->tmp . '/shared', $this->wikiDir . '/custom');

        $aside = $this->aside();

        $this->assertFalse($aside->isAside($this->wikiDir));
        $this->assertNull($aside->recover($this->wikiDir));
        $this->assertDirectoryExists($this->tmp . '/shared/custom.temp');
    }

    private function aside(): CustomAside
    {
        $config = $this->createStub(FarmConfig::class);
        $config->method('ensureBackupDir')->willReturnCallback(function (string $subFolder = '') {
            $dir = $this->tmp . '/backups' . ($subFolder === '' ? '' : '/' . $subFolder);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            return $dir;
        });

        return new CustomAside($config, new FileSystem());
    }
}
