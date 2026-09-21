<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FileSystem;
use YesWiki\Ferme\Service\SpamCleaner;
use YesWiki\Ferme\Service\SpamFingerprints;
use YesWiki\Ferme\Service\WikiDatabase;
use YesWiki\Ferme\Service\WikiFinder;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(SpamFingerprints::class, 'fingerprint')]
#[CoversMethod(SpamFingerprints::class, 'isKnownLine')]
#[CoversMethod(SpamFingerprints::class, 'isCampaignPage')]
#[CoversMethod(SpamFingerprints::class, 'measurePage')]
class SpamFingerprintsTest extends YesWikiTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        self::getWiki();
        $this->tmp = sys_get_temp_dir() . '/ferme-empreintes-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/spam', 0777, true);
    }

    protected function tearDown(): void
    {
        (new FileSystem())->rrmdir($this->tmp);
    }

    public function testOnlyALineCarryingALinkIsIndexed()
    {
        $this->assertNull(SpamFingerprints::fingerprint('Nous nous réunissons le mardi soir.'));
        $this->assertNull(SpamFingerprints::fingerprint('   '));
        $this->assertNotNull(SpamFingerprints::fingerprint('https://exemple.org/ Exemple'));
    }

    public function testTheSameLineWrittenTwoWaysHasOneFingerprint()
    {
        $this->assertSame(
            SpamFingerprints::fingerprint('https://www.yeezyadidas.de/ Yeezy'),
            SpamFingerprints::fingerprint("  https://www.yeezyadidas.DE/   Yeezy\t")
        );
        $this->assertNotSame(
            SpamFingerprints::fingerprint('https://www.yeezyadidas.de/ Yeezy'),
            SpamFingerprints::fingerprint('https://www.yeezys.co/ Yeezys')
        );
    }

    public function testALinePastedInEnoughWikisIsKnown()
    {
        $index = $this->index(['https://www.yeezyadidas.de/ Yeezy', 'https://www.jordan-1.org/ Jordan 1']);

        $this->assertTrue($index->isKnownLine('https://www.yeezyadidas.de/ Yeezy'));
        $this->assertFalse($index->isKnownLine('https://asso.example.org/ notre site'));
    }

    public function testAPageMadeOfSharedLinesIsACampaign()
    {
        $lines = [];
        for ($i = 0; $i < 8; $i++) {
            $lines[] = 'https://spam' . $i . '.example/ Achetez';
        }
        $index = $this->index($lines);

        $body = "====== Page Fan ======\n" . implode("\n", $lines);

        $this->assertTrue($index->isCampaignPage($body));
        $this->assertSame(8, $index->measurePage($body)['known']);
    }

    public function testAWikiThatCopiedTwoLinesFromANeighbourIsNot()
    {
        $index = $this->index(['https://colibris-wiki.org/ Colibris', 'https://ferme.yeswiki.net/ La ferme']);

        $body = "====== Nos partenaires ======\n"
            . "https://colibris-wiki.org/ Colibris\n"
            . "https://ferme.yeswiki.net/ La ferme\n"
            . "https://asso1.example.org/ Asso 1\n"
            . "https://asso2.example.org/ Asso 2\n"
            . 'https://asso3.example.org/ Asso 3';

        $this->assertFalse($index->isCampaignPage($body), 'deux lignes partagées ne font pas une campagne');
    }

    public function testTheCleaningTakesOutTheLinesTheFarmKnows()
    {
        $index = $this->index(['https://www.yeezyadidas.de/ Yeezy', 'https://www.jordan-1.org/ Jordan 1']);

        $body = "====== Notre association ======\n"
            . "https://www.yeezyadidas.de/ Yeezy\n"
            . "Nous nous réunissons le mardi soir.\n"
            . 'https://www.jordan-1.org/ Jordan 1';

        $this->assertSame($body, SpamCleaner::strip($body), 'sans index, une ligne par lien ne se voit pas');
        $this->assertSame(
            "====== Notre association ======\nNous nous réunissons le mardi soir.",
            SpamCleaner::strip($body, '', $index)
        );
    }

    /**
     * @param array<int,string> $lines
     */
    private function index(array $lines): SpamFingerprints
    {
        $prints = [];
        $samples = [];
        foreach ($lines as $line) {
            $print = SpamFingerprints::fingerprint($line);
            $prints[$print] = SpamFingerprints::WIKIS_FOR_CAMPAIGN;
            $samples[$print] = $line;
        }

        file_put_contents(
            $this->tmp . '/spam/' . SpamFingerprints::FILE,
            (string)json_encode(['builtAt' => date('Y-m-d H:i:s'), 'wikis' => 100, 'threshold' => SpamFingerprints::WIKIS_FOR_CAMPAIGN, 'lines' => $prints, 'samples' => $samples])
        );

        $config = $this->createStub(FarmConfig::class);
        $config->method('ensureBackupDir')->willReturn($this->tmp . '/spam');

        return new SpamFingerprints($config, $this->createStub(WikiFinder::class), $this->createStub(WikiDatabase::class));
    }
}
