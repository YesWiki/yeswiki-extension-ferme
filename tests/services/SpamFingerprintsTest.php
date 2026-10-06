<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\SpamCleaner;
use YesWiki\Ferme\Service\SpamFingerprints;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** The lines of links pasted across wikis, and the pages made of them. */
class SpamFingerprintsTest extends FarmTestCase
{
    public function testOnlyALineCarryingALinkIsIndexed(): void
    {
        $this->assertNull(SpamFingerprints::fingerprint('Nous nous réunissons le mardi soir.'));
        $this->assertNull(SpamFingerprints::fingerprint('   '));
        $this->assertNotNull(SpamFingerprints::fingerprint('https://exemple.org/ Exemple'));
    }

    public function testTheSameLineWrittenTwoWaysHasOneFingerprint(): void
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

    public function testALinePastedInEnoughWikisIsKnown(): void
    {
        $index = $this->index(['https://www.yeezyadidas.de/ Yeezy', 'https://www.jordan-1.org/ Jordan 1']);

        $this->assertTrue($index->isKnownLine('https://www.yeezyadidas.de/ Yeezy'));
        $this->assertFalse($index->isKnownLine('https://asso.example.org/ notre site'));
    }

    public function testAPageMadeOfSharedLinesIsACampaign(): void
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

    public function testAWikiThatCopiedTwoLinesFromANeighbourIsNot(): void
    {
        $index = $this->index(['https://colibris-wiki.org/ Colibris', 'https://ferme.yeswiki.net/ La ferme']);

        $body = "====== Nos partenaires ======\n"
            . "https://colibris-wiki.org/ Colibris\n"
            . "https://ferme.yeswiki.net/ La ferme\n"
            . "https://asso1.example.org/ Asso 1\n"
            . "https://asso2.example.org/ Asso 2\n"
            . 'https://asso3.example.org/ Asso 3';

        $this->assertFalse($index->isCampaignPage($body));
    }

    public function testTheCleaningTakesOutTheLinesTheFarmKnows(): void
    {
        $index = $this->index(['https://www.yeezyadidas.de/ Yeezy', 'https://www.jordan-1.org/ Jordan 1']);

        $body = "====== Notre association ======\n"
            . "https://www.yeezyadidas.de/ Yeezy\n"
            . "Nous nous réunissons le mardi soir.\n"
            . 'https://www.jordan-1.org/ Jordan 1';

        $this->assertSame($body, SpamCleaner::strip($body));
        $this->assertSame(
            "====== Notre association ======\nNous nous réunissons le mardi soir.",
            SpamCleaner::strip($body, '', $index)
        );
    }

    /** @param list<string> $lines */
    private function index(array $lines): SpamFingerprints
    {
        $prints = [];
        $samples = [];
        foreach ($lines as $line) {
            $print = (string)SpamFingerprints::fingerprint($line);
            $prints[$print] = SpamFingerprints::WIKIS_FOR_CAMPAIGN;
            $samples[$print] = $line;
        }

        $path = $this->scratch . '/farm/' . FarmConfig::DATA_DIR . '/' . SpamFingerprints::FILE;
        mkdir(dirname($path), 0o755, true);
        file_put_contents(
            $path,
            (string)json_encode(['builtAt' => date('Y-m-d H:i:s'), 'wikis' => 100, 'threshold' => SpamFingerprints::WIKIS_FOR_CAMPAIGN, 'lines' => $prints, 'samples' => $samples])
        );

        return $this->service(SpamFingerprints::class);
    }
}
