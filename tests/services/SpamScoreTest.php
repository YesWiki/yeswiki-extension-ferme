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

    public function testAThrowawayMailAddsToAnotherSignalButNeverAccusesAlone(): void
    {
        $alone = $this->score->of('Le potager', '', ['entries' => 30, 'users' => 2, 'pages' => 80, 'disposableUsers' => 1], 'lepotager');
        $withUntouched = $this->score->of('Le potager', '', $this->untouched() + ['disposableUsers' => 1], 'lepotager');

        $this->assertSame(['disposable'], $alone['reasons']);
        $this->assertLessThan(3, $alone['score']);
        $this->assertGreaterThanOrEqual(3, $withUntouched['score']);
    }

    public function testThrowawayAccountsOnABusyWikiAreItsVisitorsNotItsAuthors(): void
    {
        $verdict = $this->score->of('Formation participation', '', ['entries' => 23, 'users' => 116, 'pages' => 144, 'disposableUsers' => 5], 'chantepie');

        $this->assertNotContains('disposable', $verdict['reasons']);
    }

    public function testAFolderNamedLikeATempMailAddress(): void
    {
        foreach (['mawabog928' => 'mawabog928', 'polek1230' => 'polek12306'] as $folder => $name) {
            $verdict = $this->score->of($name, '', $this->untouched(), $folder);

            $this->assertContains('throwaway', $verdict['reasons'], $folder);
            $this->assertGreaterThanOrEqual(3, $verdict['score'], $folder);
        }
    }

    public function testAYearOrATitleOfItsOwnIsNotATempMailAddress(): void
    {
        foreach (['dejeps2024' => 'dejeps2024', 'montcenis2026' => 'Agir pour Montcenis', 'rgpd2217' => 'RGPD 2022 - ACEPP 17'] as $folder => $name) {
            $verdict = $this->score->of($name, '', $this->untouched(), $folder);

            $this->assertNotContains('throwaway', $verdict['reasons'], $folder);
        }
    }

    public function testTheHomePageOfASmallWikiSpeaksForIt(): void
    {
        $home = 'Paper io is the best online game for you: play it free at https://paperio.example and share your score with your friends.';
        $verdict = $this->score->of('Paper IO', '', $this->untouched() + ['homeText' => $home], 'paperiogame');

        $this->assertContains('language', $verdict['reasons']);
        $this->assertContains('url', $verdict['reasons']);
        $this->assertGreaterThanOrEqual(3, $verdict['score']);
    }

    public function testAStrangersTextOnAnHonestWikiIsSpammedContentNotASpamWiki(): void
    {
        $home = 'Troubleshooting common printer issues: how to fix your printer when it shows errors, with the best guide for you at https://printers.example';
        $verdict = $this->score->of('Les Cinquante ans de la Savoyarde', '', $this->untouched() + ['homeText' => $home], '50ansSavoyarde');

        $this->assertSame(['untouched'], $verdict['reasons']);
    }

    public function testTheModelsOwnLinksToYeswikiAreNotHeldAgainstANewWiki(): void
    {
        $home = 'Félicitations, votre wiki est installé ! Pour cela vous pourrez facilement dans [YesWiki](https://www.yeswiki.net) modifier une page, voici notre [espace central](https://yeswiki.net/?LaGareCentrale).';
        $verdict = $this->score->of('monwiki', '', $this->untouched() + ['homeText' => $home], 'monwiki');

        $this->assertNotContains('url', $verdict['reasons']);
    }

    public function testAHomePageWrittenInAnotherAlphabet(): void
    {
        $home = 'Lịch quay xổ số miền Nam – Tra cứu KQXSMN chuẩn xác. Giới thiệu chung về xổ số miền Nam và những điều cần biết để trúng thưởng.';
        $verdict = $this->score->of('Le jardin partagé', '', $this->untouched() + ['homeText' => $home], 'jardinpartage');

        $this->assertNotContains('script', $verdict['reasons'], 'le titre n\'est pas dans le texte : un vandalisme, pas un wiki de spam');

        $owned = $this->score->of('lokoxon845', '', $this->untouched() + ['homeText' => $home], 'lokoxon845');
        $this->assertContains('script', $owned['reasons']);
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
