<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\InstanceConsole;
use YesWiki\Ferme\Service\SpamCleaner;
use YesWiki\Ferme\Service\WikiCreator;
use YesWiki\Ferme\Service\WikiHibernator;
use YesWiki\Ferme\Service\WikiPages;
use YesWiki\Ferme\Service\WikiRemover;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** The robots' pages taken out of a wiki: what is spam, what goes, what is only cleaned. */
class SpamCleanerTest extends FarmTestCase
{
    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            $this->service(WikiStatsStore::class)->forget('spammed');
        }
        parent::tearDown();
    }

    public function testTheLinesCarryingTheSpamGoAndTheRestStays(): void
    {
        $body = "====== Notre association ======\n"
            . "http://dateram.com/1 http://dateram.com/2 http://dateram.com/3 http://dateram.com/4 http://dateram.com/5\n"
            . "Nous nous réunissons le mardi soir.\n"
            . "Best escort services in Kolkata\n"
            . 'Contact : [[https://asso.example.org notre site]]';

        $this->assertSame(
            "====== Notre association ======\nNous nous réunissons le mardi soir.\nContact : [[https://asso.example.org notre site]]",
            SpamCleaner::strip($body)
        );
    }

    public function testAnHonestPageComesBackWhole(): void
    {
        $body = "====== Compte rendu ======\nOn a parlé du budget.\nVoir [[https://exemple.org le dossier]].";

        $this->assertSame($body, SpamCleaner::strip($body));
    }

    public function testAPageThatIsOnlySpamComesBackEmpty(): void
    {
        $body = "{{attach file=\"nude_porn_sexy.jpg\"}}\nBest online casino bonus\nhttp://a.tk/1 http://a.tk/2 http://a.tk/3 http://a.tk/4 http://a.tk/5";

        $this->assertSame('', SpamCleaner::strip($body));
    }

    public function testAHandfulOfLinksOnALineIsSomebodyWritingAndNotARobot(): void
    {
        $body = 'Voir [[https://exemple.org le site]], [[https://autre.org l\'autre]] et [[https://encore.org le troisième]]';

        $this->assertSame($body, SpamCleaner::strip($body), 'une page de liens honnête en aligne trois sans être du spam');
    }

    public function testAFarmCanNameTheHostsOneCampaignKeepsPointingAt(): void
    {
        $body = "====== présentation du blog [[https://first42.fr/ first42]] ======\nNotre compte rendu du mardi.";

        $this->assertSame($body, SpamCleaner::strip($body), 'sans la liste, un lien seul ne dit rien');
        $this->assertSame('Notre compte rendu du mardi.', SpamCleaner::strip($body, 'first42\\.fr|legeekdunet\\.com'));
    }

    public function testACyrillicOrChineseCharacterComesBackWhole(): void
    {
        $body = "Bonjour х et 具 ici\nBest escort in town\nÀ demain";

        $cleaned = SpamCleaner::strip($body);

        $this->assertSame("Bonjour х et 具 ici\nÀ demain", $cleaned);
        $this->assertSame($body === mb_convert_encoding($body, 'UTF-8', 'UTF-8'), true);
        $this->assertSame(
            $cleaned,
            mb_convert_encoding($cleaned, 'UTF-8', 'UTF-8'),
            'la base refuse une chaîne dont un caractère a été coupé en deux'
        );
    }

    public function testWhatCountsAsASpammedPageIsOneRuleForEverybody(): void
    {
        $deux = 'Best escort in town, casino open';
        $un = 'Le contact est xxx, appelez le casino municipal';
        $liens = str_repeat("http://a.tk/1\n", 60);

        $this->assertTrue(SpamCleaner::isSpamPage($deux, 2, 0), 'deux mots du lexique sur une page');
        $this->assertFalse(SpamCleaner::isSpamPage($un, 1, 0), 'un seul mot peut être un mot ordinaire');
        $this->assertTrue(SpamCleaner::isSpamPage($liens, 0, 60), 'une page de liens');
        $this->assertFalse(
            SpamCleaner::isSpamPage(str_repeat("Une ligne de compte rendu.\n", 500) . $liens, 0, 60),
            'soixante adresses au milieu de cinq cents lignes de prose sont un compte rendu'
        );
        $this->assertFalse(SpamCleaner::isSpamPage('Voir https://exemple.org', 0, 1));
        $this->assertTrue(
            SpamCleaner::isSpamPage('blog [[https://first42.fr/ ici]]', 0, 1, 'first42\\.fr'),
            'un domaine de campagne suffit, même sur une ligne'
        );
    }

    public function testAPageThatIsNothingButAStackOfLinksIsCleared(): void
    {
        $body = "====== Page Fan ======\n";
        for ($i = 0; $i < 12; $i++) {
            $body .= 'https://boutique' . $i . '.example/ Basket ' . $i . "\n";
        }
        $body = trim($body);

        $this->assertSame('====== Page Fan ======', SpamCleaner::strip($body));
    }

    public function testAPageThatIsOneLongListOfLinksIsClearedEvenWhenTheyAreLabelled(): void
    {
        $body = "====== Avocat ======\n";
        for ($i = 0; $i < 60; $i++) {
            $body .= '[[https://cabinet.example/article-' . $i . '/ conseil juridique numéro ' . $i . " en cas de litige]]\n";
        }
        $body = trim($body);

        $this->assertSame('====== Avocat ======', SpamCleaner::strip($body));
    }

    public function testAPageListingItsResourcesKeepsThem(): void
    {
        $body = "====== Nos ressources ======\n"
            . "Voici les pads que nous utilisons pour la formation de mai.\n"
            . "Formation BPJeps mai 2026 : [[https://pad.example.org/bpjeps le pad de la session]]\n"
            . "Formation ASEC juin 2026 : [[https://pad.example.org/asec le pad de la session]]\n"
            . 'Compte rendu de la réunion : [[https://pad.example.org/cr le pad de la réunion]]';

        $this->assertSame($body, SpamCleaner::strip($body));
    }

    public function testALineOfTheLexiconGoesAndTheRestOfThePageStays(): void
    {
        $this->assertSame(
            'Bonjour',
            SpamCleaner::strip("Bonjour\nBest escort services in Kolkata\ncasino jackpot ouvert")
        );
    }

    public function testTheSkeletonOfAWikiIsNeverDeletedOnlyCleaned(): void
    {
        foreach (['PagePrincipale', 'PageMenuHaut', 'PageHeader', 'pagefooter', 'BacASable'] as $tag) {
            $skeleton = false;
            foreach (SpamCleaner::SKELETON as $name) {
                $skeleton = $skeleton || str_starts_with(strtolower($tag), $name);
            }
            $this->assertTrue($skeleton, $tag);
        }
    }

    public function testASpammedWikiLosesTheRobotsPageAndTheSpamLinesOfItsOwn(): void
    {
        $this->farm();
        $dir = $this->service(WikiCreator::class)->createFromEntry($this->entry('spammed'), 'bf_dossier-wiki');
        $console = $this->service(InstanceConsole::class);
        $console->task($dir, 'append-to-page', ['tag' => 'CasinoBonus', 'content' => "Best online casino bonus\nTop escort jackpot\nhttp://a.tk/1 http://a.tk/2 http://a.tk/3 http://a.tk/4 http://a.tk/5"]);
        $console->task($dir, 'append-to-page', ['tag' => 'BacASable', 'content' => "Nous jouons ici.\nBest escort services in Kolkata, casino open"]);
        $console->task($dir, 'append-to-page', ['tag' => 'CompteRendu', 'content' => 'On a parlé du budget, voir [[https://exemple.org le dossier]].']);
        $cleaner = $this->service(SpamCleaner::class);

        $found = array_column($cleaner->inspect('spammed'), 'action', 'tag');
        $this->assertSame(['CasinoBonus' => 'delete', 'BacASable' => 'strip'], array_intersect_key($found, ['CasinoBonus' => 1, 'BacASable' => 1]));
        $this->assertArrayNotHasKey('CompteRendu', $found);

        $dry = $cleaner->clean('spammed', true);
        $this->assertNull($dry['dump']);
        $this->assertNotNull($this->service(WikiPages::class)->content('spammed', 'CasinoBonus'), 'a dry run touches nothing');

        $this->service(WikiHibernator::class)->hibernate('spammed');
        $report = $cleaner->clean('spammed', false);

        $pages = $this->service(WikiPages::class);
        $this->assertSame(1, $report['deleted']);
        $this->assertSame(1, $report['stripped']);
        $this->assertNull($pages->content('spammed', 'CasinoBonus'));
        $sandbox = (string)$pages->content('spammed', 'BacASable');
        $this->assertStringContainsString('Nous jouons ici.', $sandbox);
        $this->assertStringNotContainsString('escort', $sandbox);
        $this->assertStringContainsString('budget', (string)$pages->content('spammed', 'CompteRendu'));
        $this->assertNotNull($report['dump']);
        $this->assertFileExists($this->scratch . '/farm/' . $report['dump']);
        $this->assertStringStartsWith(FarmConfig::DATA_DIR . '/spam/', (string)$report['dump']);
        $this->assertSame('hibernate', $this->service(WikiHibernator::class)->statusOf('spammed'), 'woken for the cleaning, asleep again after');

        $this->service(WikiRemover::class)->destroy('spammed', null);
        $this->assertDirectoryDoesNotExist($dir);
    }
}
