<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\WikiCreator;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(WikiCreator::class, 'createFromEntry')]
class WikiCreatorRobotsTest extends YesWikiTestCase
{
    private string $destination;

    protected function setUp(): void
    {
        $this->destination = sys_get_temp_dir() . '/ferme-robots-' . uniqid() . '/';
        mkdir($this->destination);
        file_put_contents($this->destination . 'robots.txt', "User-agent: *\nAllow: /\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->destination . 'robots.txt');
        @rmdir($this->destination);
        unset(self::getWiki()->config['yeswiki-farm-block-robots'], self::getWiki()->config['yeswiki-farm-extra-config']);
    }

    public function testANewWikiAsksSearchEnginesToStayAwayByDefault()
    {
        unset(self::getWiki()->config['yeswiki-farm-block-robots']);

        $this->assertSame(WikiCreator::ROBOTS_META, $this->config()['meta']['robots']);
        $this->create();
        $this->assertSame(WikiCreator::ROBOTS_TXT, file_get_contents($this->destination . 'robots.txt'));
    }

    public function testAFarmCanLetItsWikisBeIndexed()
    {
        self::getWiki()->config['yeswiki-farm-block-robots'] = 'false';

        $this->assertArrayNotHasKey('meta', $this->config());
        $this->create();
        $this->assertSame("User-agent: *\nAllow: /\n", file_get_contents($this->destination . 'robots.txt'));
    }

    public function testTheBlockKeepsTheOtherMetasAFarmGivesItsWikis()
    {
        self::getWiki()->config['yeswiki-farm-extra-config'] = ['meta' => ['author' => 'La ferme']];

        $this->assertSame(['author' => 'La ferme', 'robots' => WikiCreator::ROBOTS_META], $this->config()['meta']);
    }

    private function config(): array
    {
        $creator = self::getWiki()->services->get(WikiCreator::class);
        $method = new \ReflectionMethod(WikiCreator::class, 'buildWikiConfig');

        return $method->invoke(
            $creator,
            ['bf_titre' => 'Le potager', 'bf_dossier-wiki' => 'lepotager', 'id_fiche' => 'LePotager'],
            'bf_dossier-wiki',
            'yeswiki_lepotager__',
            ['write' => '*', 'read' => '*', 'comments' => 'comments-closed'],
            ['theme' => 'margot', 'style' => 'margot.css', 'squelette' => '1col.tpl.html']
        );
    }

    private function create(): void
    {
        $creator = self::getWiki()->services->get(WikiCreator::class);
        (new \ReflectionMethod(WikiCreator::class, 'writeRobotsFile'))->invoke($creator, $this->destination);
    }
}
