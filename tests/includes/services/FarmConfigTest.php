<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(FarmConfig::class, 'isSafeName')]
#[CoversMethod(FarmConfig::class, 'init')]
class FarmConfigTest extends YesWikiTestCase
{
    protected function setUp(): void
    {
        self::getWiki();
    }

    /**
     * The rule the whole farm judges a folder name by — the API included, which
     * used to have a stricter one of its own and refused four real wikis.
     */
    public function testWhatAFarmFolderMayBeCalled()
    {
        foreach (['monwiki', 'mon-wiki', 'MonWiki_2', 'wiki2026', 'CessmaEnvironnement.old'] as $name) {
            $this->assertTrue(FarmConfig::isSafeName($name, true), $name . ' est un nom de dossier légitime');
        }
    }

    public function testWhatItMayNotBeCalled()
    {
        foreach (['', '.', '..', 'wiki/../autre', 'a\\b', "wiki\0", 'wiki//autre'] as $name) {
            $this->assertFalse(FarmConfig::isSafeName($name, true), var_export($name, true) . ' doit être refusé');
        }
    }

    public function testANestedNameIsOnlyAllowedWhereNestingIs()
    {
        $this->assertTrue(FarmConfig::isSafeName('groupe/monwiki', true));
        $this->assertFalse(FarmConfig::isSafeName('groupe/monwiki'), 'pas de sous-dossier là où on n\'en attend pas');
    }

    public function testAnEmptiedExtraSettingNamesNoFolder()
    {
        $wiki = self::getWiki();
        $saved = [$wiki->config['yeswiki-farm-extra-themes'] ?? null, $wiki->config['yeswiki-farm-extra-tools'] ?? null];
        $wiki->config['yeswiki-farm-extra-themes'] = [''];
        $wiki->config['yeswiki-farm-extra-tools'] = ['', ' /moncustom/ ', '..'];

        try {
            $wiki->services->get(FarmConfig::class)->init();

            $this->assertSame([], $wiki->config['yeswiki-farm-extra-themes'], 'themes/ entier recopié sur les liens des thèmes prêtés');
            $this->assertSame(['moncustom'], $wiki->config['yeswiki-farm-extra-tools']);
        } finally {
            [$wiki->config['yeswiki-farm-extra-themes'], $wiki->config['yeswiki-farm-extra-tools']] = $saved;
        }
    }
}
