<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Ferme\Service\FolderLock;
use YesWiki\Ferme\Service\SpamCleaner;
use YesWiki\Ferme\Service\SpamFingerprints;
use YesWiki\Ferme\Service\StatsRefresher;
use YesWiki\Ferme\Service\WikiDatabase;
use YesWiki\Ferme\Service\WikiHibernator;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(SpamCleaner::class, 'strip')]
#[CoversMethod(SpamCleaner::class, 'clean')]
class SpamCleanerTest extends YesWikiTestCase
{
    /** @var array<int,string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        self::getWiki();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            (new FileSystem())->rrmdir($path);
        }
        $this->temporary = [];
    }

    public function testTheLinesCarryingTheSpamGoAndTheRestStays()
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

    public function testAnHonestPageComesBackWhole()
    {
        $body = "====== Compte rendu ======\nOn a parlé du budget.\nVoir [[https://exemple.org le dossier]].";

        $this->assertSame($body, SpamCleaner::strip($body));
    }

    public function testAPageThatIsOnlySpamComesBackEmpty()
    {
        $body = "{{attach file=\"nude_porn_sexy.jpg\"}}\nBest online casino bonus\nhttp://a.tk/1 http://a.tk/2 http://a.tk/3 http://a.tk/4 http://a.tk/5";

        $this->assertSame('', SpamCleaner::strip($body));
    }

    public function testAHandfulOfLinksOnALineIsSomebodyWritingAndNotARobot()
    {
        $body = 'Voir [[https://exemple.org le site]], [[https://autre.org l\'autre]] et [[https://encore.org le troisième]]';

        $this->assertSame($body, SpamCleaner::strip($body), 'une page de liens honnête en aligne trois sans être du spam');
    }

    public function testAFarmCanNameTheHostsOneCampaignKeepsPointingAt()
    {
        $body = "====== présentation du blog [[https://first42.fr/ first42]] ======\nNotre compte rendu du mardi.";

        $this->assertSame($body, SpamCleaner::strip($body), 'sans la liste, un lien seul ne dit rien');
        $this->assertSame('Notre compte rendu du mardi.', SpamCleaner::strip($body, 'first42\\.fr|legeekdunet\\.com'));
    }

    public function testACyrillicOrChineseCharacterComesBackWhole()
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

    public function testWhatCountsAsASpammedPageIsOneRuleForEverybody()
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

    public function testAPageThatIsNothingButAStackOfLinksIsCleared()
    {
        $body = "====== Page Fan ======\n";
        for ($i = 0; $i < 12; $i++) {
            $body .= 'https://boutique' . $i . '.example/ Basket ' . $i . "\n";
        }
        $body = trim($body);

        $this->assertSame('====== Page Fan ======', SpamCleaner::strip($body));
    }

    public function testAPageThatIsOneLongListOfLinksIsClearedEvenWhenTheyAreLabelled()
    {
        $body = "====== Avocat ======\n";
        for ($i = 0; $i < 60; $i++) {
            $body .= '[[https://cabinet.example/article-' . $i . '/ conseil juridique numéro ' . $i . " en cas de litige]]\n";
        }
        $body = trim($body);

        $this->assertSame('====== Avocat ======', SpamCleaner::strip($body));
    }

    public function testAPageListingItsResourcesKeepsThem()
    {
        $body = "====== Nos ressources ======\n"
            . "Voici les pads que nous utilisons pour la formation de mai.\n"
            . "Formation BPJeps mai 2026 : [[https://pad.example.org/bpjeps le pad de la session]]\n"
            . "Formation ASEC juin 2026 : [[https://pad.example.org/asec le pad de la session]]\n"
            . 'Compte rendu de la réunion : [[https://pad.example.org/cr le pad de la réunion]]';

        $this->assertSame($body, SpamCleaner::strip($body));
    }

    public function testALineOfTheLexiconGoesAndTheRestOfThePageStays()
    {
        $this->assertSame(
            'Bonjour',
            SpamCleaner::strip("Bonjour\nBest escort services in Kolkata\ncasino jackpot ouvert")
        );
    }

    public function testASleepingWikiIsWokenForTheCleaningAndPutBackToSleepEvenOnAFailure()
    {
        $hibernator = $this->createMock(WikiHibernator::class);
        $hibernator->expects($this->once())->method('wake')->willReturn(['changed' => true, 'status' => 'running', 'before' => 'hibernate']);
        $hibernator->expects($this->once())->method('hibernate')->willReturn(['changed' => true, 'status' => 'hibernate', 'before' => 'running']);

        $database = $this->createStub(WikiDatabase::class);
        $database->method('connect')->willThrowException(new \RuntimeException('base injoignable'));

        $this->expectException(\RuntimeException::class);
        $this->cleaner($hibernator, 'hibernate', $database)->clean('monwiki', false);
    }

    public function testAWikiInServiceIsNeitherWokenNorPutToSleep()
    {
        $hibernator = $this->createMock(WikiHibernator::class);
        $hibernator->expects($this->never())->method('wake');
        $hibernator->expects($this->never())->method('hibernate');

        $database = $this->createStub(WikiDatabase::class);
        $database->method('connect')->willThrowException(new \RuntimeException('base injoignable'));

        $this->expectException(\RuntimeException::class);
        $this->cleaner($hibernator, '', $database)->clean('monwiki', false);
    }

    private function cleaner(WikiHibernator $hibernator, string $status, ?WikiDatabase $database = null): SpamCleaner
    {
        $tmp = sys_get_temp_dir() . '/ferme-nettoyage-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0777, true);
        $this->temporary[] = $tmp;

        $config = $this->createStub(FarmConfig::class);
        $config->method('wikiDir')->willReturn($tmp . '/monwiki/');
        $config->method('readWikiConfig')->willReturn(['table_prefix' => 'yw_', 'wiki_status' => $status]);

        $lock = new FolderLock();
        $lock->useDirectory($tmp . '/locks');

        if ($database === null) {
            $database = $this->createStub(WikiDatabase::class);
            $database->method('connect')->willThrowException(new \RuntimeException('base injoignable'));
        }

        return new SpamCleaner(
            self::getWiki(),
            $config,
            $database,
            $lock,
            $hibernator,
            $this->createStub(StatsRefresher::class),
            $this->createStub(SpamFingerprints::class)
        );
    }

    public function testTheSkeletonOfAWikiIsNeverDeletedOnlyCleaned()
    {
        foreach (['PagePrincipale', 'PageMenuHaut', 'PageHeader', 'pagefooter'] as $tag) {
            $this->assertTrue(
                in_array(strtolower($tag), SpamCleaner::SKELETON, true) || $this->startsWithSkeleton($tag),
                $tag . ' doit être reconnue comme une page du squelette'
            );
        }
    }

    private function startsWithSkeleton(string $tag): bool
    {
        foreach (SpamCleaner::SKELETON as $name) {
            if (str_starts_with(strtolower($tag), $name)) {
                return true;
            }
        }

        return false;
    }
}
