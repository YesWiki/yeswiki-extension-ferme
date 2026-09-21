<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\SpamCleaner;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(SpamCleaner::class, 'strip')]
class SpamCleanerTest extends YesWikiTestCase
{
    protected function setUp(): void
    {
        self::getWiki();
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
        $this->assertFalse(SpamCleaner::isSpamPage('Voir https://exemple.org', 0, 1));
        $this->assertTrue(
            SpamCleaner::isSpamPage('blog [[https://first42.fr/ ici]]', 0, 1, 'first42\\.fr'),
            'un domaine de campagne suffit, même sur une ligne'
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
