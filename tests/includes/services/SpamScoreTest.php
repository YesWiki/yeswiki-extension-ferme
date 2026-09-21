<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\SpamScore;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(SpamScore::class, 'of')]
#[CoversMethod(SpamScore::class, 'threshold')]
class SpamScoreTest extends YesWikiTestCase
{
    private SpamScore $score;

    protected function setUp(): void
    {
        $wiki = self::getWiki();
        $wiki->config['default_language'] = 'fr';
        $wiki->config['yeswiki-farm-spam-threshold'] = 3;
        $wiki->config['yeswiki-farm-spam-words'] = '';
        $this->score = new SpamScore($wiki);
    }

    public function testAnOrdinaryFrenchWikiIsLeftAlone()
    {
        $verdict = $this->score->of(
            'Les jardins partagés de Vaulx',
            'Le wiki de notre association de quartier',
            $this->untouched(),
            'jardins-vaulx'
        );

        $this->assertLessThan(3, $verdict['score'], (string)implode(',', $verdict['reasons']));
    }

    public function testALinkInTheDescriptionIsNotEnoughToAccuseAWiki()
    {
        $verdict = $this->score->of(
            'Des fraises aux fenêtres',
            'Notre site est sur https://fraises.example.org',
            $this->untouched(),
            'desfraisesauxfenetres'
        );

        $this->assertSame(['url', 'untouched'], $verdict['reasons']);
        $this->assertLessThan(3, $verdict['score'], 'un lien et un wiki neuf, cela arrive tous les jours');
    }

    public function testGamblingWrittenAsOneWordInTheFolderIsSeen()
    {
        foreach (['keonhacai5csacom', 'taixiumoe', 'sunwin99ceo', 'trang8xbet', 'i9bet01live'] as $folder) {
            $verdict = $this->score->of($folder, '', $this->untouched(), $folder);

            $this->assertGreaterThanOrEqual(3, $verdict['score'], $folder . ' : ' . implode(',', $verdict['reasons']));
            $this->assertContains('words', $verdict['reasons'], $folder);
        }
    }

    public function testAFolderThatIsADomainNameWithNoTitleOfItsOwn()
    {
        $verdict = $this->score->of('s8comnet', '', $this->untouched(), 's8comnet');

        $this->assertContains('domain', $verdict['reasons']);
        $this->assertGreaterThanOrEqual(3, $verdict['score']);
    }

    public function testTheSameFolderKeepsItsNameWhenSomebodyNamedTheWiki()
    {
        $verdict = $this->score->of('Nuggets Clara', '', $this->untouched(), 'INO3Ressourcesclaracantournet');

        $this->assertNotContains('domain', $verdict['reasons'], 'un titre à soi, donc quelqu\'un derrière');
        $this->assertLessThan(3, $verdict['score']);
    }

    public function testAnotherAlphabetIsEnoughOnItsOwn()
    {
        $verdict = $this->score->of('Лучшие казино', '', [], 'kazino');

        $this->assertSame(['script'], $verdict['reasons']);
        $this->assertGreaterThanOrEqual(3, $verdict['score']);
    }

    public function testAFarmCanAddItsOwnWords()
    {
        self::getWiki()->config['yeswiki-farm-spam-words'] = 'trottinette|patinette';
        $score = new SpamScore(self::getWiki());

        $verdict = $score->of('Trottinette pas chère', '', [], 'promo');

        $this->assertContains('words', $verdict['reasons']);
    }

    public function testTheThresholdIsTheFarmsToSetAndNeverZero()
    {
        $wiki = self::getWiki();
        $this->assertSame(3, $this->score->threshold());

        $wiki->config['yeswiki-farm-spam-threshold'] = 0;
        $this->assertSame(1, (new SpamScore($wiki))->threshold(), 'sinon tout serait du spam');
    }

    /**
     * @return array<string,int>
     */
    private function untouched(): array
    {
        return ['entries' => 8, 'users' => 1, 'pages' => 53];
    }
}
