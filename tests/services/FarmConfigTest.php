<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** Where the farm's wikis live and what it lets them be. */
class FarmConfigTest extends FarmTestCase
{
    public function testWhatAFarmFolderMayBeCalled(): void
    {
        foreach (['monwiki', 'mon-wiki', 'MonWiki_2', 'wiki2026', 'CessmaEnvironnement.old'] as $name) {
            $this->assertTrue(FarmConfig::isSafeName($name, true), $name);
        }
    }

    public function testWhatItMayNotBeCalled(): void
    {
        foreach (['', '.', '..', 'wiki/../autre', 'a\\b', "wiki\0", 'wiki//autre'] as $name) {
            $this->assertFalse(FarmConfig::isSafeName($name, true), var_export($name, true));
        }
    }

    public function testANestedNameIsOnlyAllowedWhereNestingIs(): void
    {
        $this->assertTrue(FarmConfig::isSafeName('groupe/monwiki', true));
        $this->assertFalse(FarmConfig::isSafeName('groupe/monwiki'));
    }

    public function testANewWikiCannotTakeAFolderTheFarmWikiUses(): void
    {
        $config = $this->farm(['yeswiki-farm-root-folder' => '.']);

        $this->assertFalse($config->isFreeName('private'));
        $this->assertFalse($config->isFreeName('Custom'));
        $this->assertFalse($config->isFreeName('mon wiki'));
        $this->assertTrue($config->isFreeName('mon-wiki'));
        $this->assertTrue($this->farm()->isFreeName('private'), 'a folder of its own lets the farm use any slug');
    }

    public function testWikisLiveUnderTheRootFolderAndUrl(): void
    {
        $config = $this->farm(['yeswiki-farm-root-url' => 'http://farm.test/wikis']);

        $this->assertSame($this->scratch . '/wikis/mon-wiki', $config->wikiDir('mon-wiki'));
        $this->assertSame('http://farm.test/wikis/mon-wiki/', $config->wikiUrl('mon-wiki'));
        $this->assertSame($this->scratch . '/wikis/mon-wiki/yeswiki.config.php', $config->wikiConfigFile('mon-wiki'));
        $this->expectException(\InvalidArgumentException::class);
        $config->wikiDir('../ailleurs');
    }

    public function testAWikiStillCarryingItsDoryphoreConfigurationIsFound(): void
    {
        mkdir($this->scratch . '/wikis/ancien');
        file_put_contents($this->scratch . '/wikis/ancien/wakka.config.php', "<?php\n\$wakkaConfig = ['base_url' => 'http://farm.test/ancien/?'];\n");
        $config = $this->farm();

        $this->assertTrue($config->hasWiki('ancien'));
        $this->assertSame('http://farm.test/ancien/?', $config->readWikiConfig('ancien')['base_url']);
    }

    public function testEmptiedOrBrokenChoicesFallBackToWhatCoreShips(): void
    {
        $config = $this->farm([
            'yeswiki-farm-themes' => [['label' => '', 'theme' => 'margot'], ['label' => 'Absent', 'theme' => 'nowhere']],
            'yeswiki-farm-acls' => 'pas une liste',
            'yeswiki-farm-extensions' => ['', ' /moncustom/ ', '..'],
        ]);

        $this->assertSame([0], array_keys($config->themes()));
        $this->assertSame(THEME_PAR_DEFAUT, $config->themes()[0]['theme']);
        $this->assertSame('*', $config->acls()[0]['read']);
        $this->assertSame(['moncustom'], $config->extensions());
    }

    public function testAWikiWithItsOwnDatabaseGetsAnAccountNamedAfterIt(): void
    {
        $own = $this->farm(['yeswiki-farm-db-driver' => 'mysql', 'yeswiki-farm-db-admin-user' => 'root', 'yeswiki-farm-prefix' => 'ferme_'])->databaseFor('mon-wiki');
        $this->assertTrue($own['provision']);
        $this->assertSame('ferme_mon_wiki', $own['database']);
        $this->assertSame('ferme_mon_wiki', $own['user']);
        $this->assertGreaterThan(20, strlen($own['password']));

        $shared = $this->farm(['yeswiki-farm-db-driver' => 'mysql', 'db_database' => 'farmdb', 'yeswiki-farm-prefix' => 'ferme_'])->databaseFor('mon-wiki');
        $this->assertFalse($shared['provision']);
        $this->assertSame('farmdb', $shared['database']);
        $this->assertSame('ferme_mon_wiki__', $shared['prefix']);

        $this->assertSame('sqlite', $this->farm()->databaseFor('mon-wiki')['driver']);
    }
}
