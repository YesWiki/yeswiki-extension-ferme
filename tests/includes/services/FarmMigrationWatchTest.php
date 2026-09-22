<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\FarmMigrationWatch;
use YesWiki\Ferme\Service\WikiConfigEditor;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(FarmMigrationWatch::class, 'isDue')]
#[CoversMethod(FarmMigrationWatch::class, 'isEnabled')]
#[CoversMethod(FarmMigrationWatch::class, 'release')]
class FarmMigrationWatchTest extends YesWikiTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        self::getWiki();
        $this->tmp = sys_get_temp_dir() . '/ferme-watch-' . uniqid();
        mkdir($this->tmp . '/private', 0777, true);
        putenv(FarmMigrationWatch::RUNNING);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/private/*') ?: []);
        @unlink($this->tmp . '/wakka.config.php');
        @rmdir($this->tmp . '/private');
        @rmdir($this->tmp);
        putenv(FarmMigrationWatch::RUNNING);
    }

    public function testTheVersionIsReadFromTheFileTheUpdateJustWrote()
    {
        $this->masterRuns('doryphore', '4.6.8');
        self::getWiki()->config['yeswiki_release'] = '4.6.7';

        $this->assertSame('doryphore 4.6.8', $this->watch()->release());
    }

    public function testTheFirstLookOnlyWritesDownTheVersionItFound()
    {
        $this->masterRuns('doryphore', '4.6.7');

        $this->assertFalse($this->watch()->isDue(), 'les wikis étaient déjà sur cette version, on ne leur doit rien');
        $this->assertSame('doryphore 4.6.7', $this->watch()->lastSeen());
        $this->assertFalse($this->watch()->isDue(), 'et rien n\'a bougé depuis');
    }

    public function testAMasterThatChangedVersionIsDueOnceAndOnlyOnce()
    {
        $this->masterRuns('doryphore', '4.6.7');
        $this->watch()->isDue();

        $this->masterRuns('doryphore', '4.6.8');
        $moved = $this->watch();
        $this->assertTrue($moved->isDue());
        $this->assertSame('doryphore 4.6.8', $moved->lastSeen(), 'la version est notée avant que le travail commence');
        $this->assertFalse($this->watch()->isDue(), 'un deuxième passage ne relance rien');
    }

    public function testTheFarmMigratingItsOwnWikisIsNeverDue()
    {
        $this->masterRuns('doryphore', '4.6.7');
        $this->watch()->isDue();
        $this->masterRuns('doryphore', '4.6.8');

        putenv(FarmMigrationWatch::RUNNING . '=1');

        $this->assertFalse($this->watch()->isDue(), 'sinon chaque wiki migré relancerait toute la ferme');
    }

    public function testAFarmThatTurnedItOffIsNeverDue()
    {
        $this->masterRuns('doryphore', '4.6.7');
        $this->watch()->isDue();
        $this->masterRuns('doryphore', '4.6.8');

        $this->assertFalse($this->watch(false)->isDue());
        $this->assertFalse($this->watch('false')->isDue(), 'comme écrit dans un wakka.config.php');
        $this->assertTrue($this->watch(true)->isDue());
    }

    public function testAMasterThatSaysNothingOfItsVersionIsLeftAlone()
    {
        $this->masterRuns('', '');

        $this->assertFalse($this->watch()->isDue());
        $this->assertSame('', $this->watch()->lastSeen());
    }

    private function masterRuns(string $version, string $release): void
    {
        file_put_contents(
            $this->tmp . '/wakka.config.php',
            "<?php\n\$wakkaConfig = " . var_export([
                'yeswiki_version' => $version,
                'yeswiki_release' => $release,
            ], true) . ";\n"
        );
    }

    private function watch($enabled = null): FarmMigrationWatch
    {
        $wiki = self::getWiki();
        if ($enabled === null) {
            unset($wiki->config['yeswiki-farm-migrate-on-update']);
        } else {
            $wiki->config['yeswiki-farm-migrate-on-update'] = $enabled;
        }

        return new FarmMigrationWatch($wiki, $wiki->services->get(WikiConfigEditor::class), $this->tmp);
    }
}
