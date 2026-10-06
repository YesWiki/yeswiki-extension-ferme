<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\SpamScore;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** How much a wiki looks like a spam creation. */
class SpamScoreTest extends FarmTestCase
{
    private SpamScore $score;

    protected function setUp(): void
    {
        parent::setUp();
        $this->score = $this->scoreWith([]);
    }

    public function testAnOrdinaryFrenchWikiIsLeftAlone(): void
    {
        $verdict = $this->score->of(
            'Les jardins partagés de Vaulx',
            'Le wiki de notre association de quartier',
            $this->untouched(),
            'jardins-vaulx'
        );

        $this->assertLessThan(3, $verdict['score'], (string)implode(',', $verdict['reasons']));
    }

    public function testALinkInTheDescriptionIsNotEnoughToAccuseAWiki(): void
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

    public function testGamblingWrittenAsOneWordInTheFolderIsSeen(): void
    {
        foreach (['keonhacai5csacom', 'taixiumoe', 'sunwin99ceo', 'trang8xbet', 'i9bet01live'] as $folder) {
            $verdict = $this->score->of($folder, '', $this->untouched(), $folder);

            $this->assertGreaterThanOrEqual(3, $verdict['score'], $folder . ' : ' . implode(',', $verdict['reasons']));
            $this->assertContains('words', $verdict['reasons'], $folder);
        }
    }

    public function testAFolderThatIsADomainNameWithNoTitleOfItsOwn(): void
    {
        $verdict = $this->score->of('s8comnet', '', $this->untouched(), 's8comnet');

        $this->assertContains('domain', $verdict['reasons']);
        $this->assertGreaterThanOrEqual(3, $verdict['score']);
    }

    public function testTheSameFolderKeepsItsNameWhenSomebodyNamedTheWiki(): void
    {
        $verdict = $this->score->of('Nuggets Clara', '', $this->untouched(), 'INO3Ressourcesclaracantournet');

        $this->assertNotContains('domain', $verdict['reasons'], 'un titre à soi, donc quelqu\'un derrière');
        $this->assertLessThan(3, $verdict['score']);
    }

    public function testAnotherAlphabetIsEnoughOnItsOwn(): void
    {
        $verdict = $this->score->of('Лучшие казино', '', [], 'kazino');

        $this->assertSame(['script'], $verdict['reasons']);
        $this->assertGreaterThanOrEqual(3, $verdict['score']);
    }

    public function testAFarmCanAddItsOwnWords(): void
    {
        $score = $this->scoreWith(['yeswiki-farm-spam-words' => 'trottinette|patinette']);

        $verdict = $score->of('Trottinette pas chère', '', [], 'promo');

        $this->assertContains('words', $verdict['reasons']);
    }

    public function testTheThresholdIsTheFarmsToSetAndNeverZero(): void
    {
        $this->assertSame(3, $this->score->threshold());
        $this->assertSame(1, $this->scoreWith(['yeswiki-farm-spam-threshold' => 0])->threshold());
    }

    /** @param array<string, mixed> $settings */
    private function scoreWith(array $settings): SpamScore
    {
        return new SpamScore($this->farm($settings + ['default_language' => 'fr', 'yeswiki-farm-spam-threshold' => 3, 'yeswiki-farm-spam-words' => '']));
    }

    /** @return array<string, int> */
    private function untouched(): array
    {
        return ['entries' => 8, 'users' => 1, 'pages' => 53];
    }
}
