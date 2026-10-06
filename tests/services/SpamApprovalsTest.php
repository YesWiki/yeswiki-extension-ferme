<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\SpamApprovals;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Kernel\Service\DbService;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** The pages somebody vouched for, kept next to the numbers. */
class SpamApprovalsTest extends FarmTestCase
{
    private WikiStatsStore $store;
    private int $triplesBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new WikiStatsStore(self::getWiki()->services->get(DbService::class));
        $this->triplesBefore = $this->countTriples();
    }

    protected function tearDown(): void
    {
        $this->store->forget('monwiki');
        $this->assertSame($this->triplesBefore, $this->countTriples());
        parent::tearDown();
    }

    public function testAPageSomebodyVouchedForStopsCounting(): void
    {
        $body = "====== Nos ressources ======\nhttps://pad.example.org/un le pad";
        $approvals = new SpamApprovals($this->store);

        $this->assertFalse($approvals->isApproved('monwiki', 'Ressources', $body));

        $approvals->approve('monwiki', 'Ressources', $body);

        $this->assertTrue($approvals->isApproved('monwiki', 'Ressources', $body));
        $this->assertSame(['Ressources'], array_keys($approvals->all('monwiki')));
    }

    public function testTheMarkFollowsTheTextAndNotTheName(): void
    {
        $body = "Nos ressources\nhttps://pad.example.org/un le pad";
        $approvals = new SpamApprovals($this->store);
        $approvals->approve('monwiki', 'Ressources', $body);

        $this->assertTrue($approvals->isApproved('monwiki', 'Ressources', "Nos ressources\n  https://pad.example.org/un   le pad  "), 'les espaces ne comptent pas');
        $this->assertFalse(
            $approvals->isApproved('monwiki', 'Ressources', $body . "\nhttps://casino.example/ jackpot"),
            'le robot revient : la validation ne vaut plus'
        );
    }

    public function testAValidationCanBeWithdrawn(): void
    {
        $body = 'Nos ressources https://pad.example.org/un';
        $approvals = new SpamApprovals($this->store);
        $approvals->approve('monwiki', 'Ressources', $body);

        $this->assertTrue($approvals->forget('monwiki', 'Ressources'));
        $this->assertFalse($approvals->forget('monwiki', 'Ressources'), 'la deuxième fois il n\'y a plus rien');
        $this->assertFalse($approvals->isApproved('monwiki', 'Ressources', $body));
    }

    public function testAValidationOutlivesAMeasurement(): void
    {
        $body = 'Nos ressources https://pad.example.org/un';
        $approvals = new SpamApprovals($this->store);
        $approvals->approve('monwiki', 'Ressources', $body);

        $this->store->save('monwiki', ['pages' => 12, 'entries' => 3]);

        $fresh = new SpamApprovals($this->store);
        $this->assertTrue($fresh->isApproved('monwiki', 'Ressources', $body), 'une mesure ne doit pas effacer ce qu\'une personne a décidé');
        $this->assertSame(12, $this->stored('monwiki')['pages']);
    }

    public function testAValidationDoesNotPretendTheWikiWasMeasured(): void
    {
        $this->store->save('monwiki', ['pages' => 12]);
        $measured = $this->stored('monwiki')['computedAt'];

        (new SpamApprovals($this->store))->approve('monwiki', 'Ressources', 'du texte');

        $this->assertSame($measured, $this->stored('monwiki')['computedAt']);
    }

    private function countTriples(): int
    {
        $db = self::getWiki()->services->get(DbService::class);

        return (int)($db->loadSingle('SELECT COUNT(*) AS n FROM ' . $db->prefixTable('triples'))['n'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function stored(string $folder): array
    {
        $stats = $this->store->read($folder);
        $this->assertNotNull($stats);

        return $stats;
    }
}
