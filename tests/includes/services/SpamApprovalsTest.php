<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Core\Service\DbService;
use YesWiki\Ferme\Service\SpamApprovals;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(SpamApprovals::class, 'approve')]
#[CoversMethod(SpamApprovals::class, 'forget')]
#[CoversMethod(SpamApprovals::class, 'isApproved')]
#[CoversMethod(SpamApprovals::class, 'all')]
class SpamApprovalsTest extends YesWikiTestCase
{
    private WikiStatsStore $store;
    private int $triplesBefore;

    protected function setUp(): void
    {
        $this->store = new WikiStatsStore(self::getWiki()->services->get(DbService::class));
        $this->triplesBefore = $this->countTriples();
    }

    protected function tearDown(): void
    {
        $this->store->forget('monwiki');
        $this->assertSame($this->triplesBefore, $this->countTriples(), 'un test laisse la table des triples comme il l\'a trouvée');
    }

    public function testAPageSomebodyVouchedForStopsCounting()
    {
        $body = "====== Nos ressources ======\nhttps://pad.example.org/un le pad";
        $approvals = new SpamApprovals($this->store);

        $this->assertFalse($approvals->isApproved('monwiki', 'Ressources', $body));

        $approvals->approve('monwiki', 'Ressources', $body);

        $this->assertTrue($approvals->isApproved('monwiki', 'Ressources', $body));
        $this->assertSame(['Ressources'], array_keys($approvals->all('monwiki')));
    }

    public function testTheMarkFollowsTheTextAndNotTheName()
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

    public function testAValidationCanBeWithdrawn()
    {
        $body = 'Nos ressources https://pad.example.org/un';
        $approvals = new SpamApprovals($this->store);
        $approvals->approve('monwiki', 'Ressources', $body);

        $this->assertTrue($approvals->forget('monwiki', 'Ressources'));
        $this->assertFalse($approvals->forget('monwiki', 'Ressources'), 'la deuxième fois il n\'y a plus rien');
        $this->assertFalse($approvals->isApproved('monwiki', 'Ressources', $body));
    }

    public function testAValidationOutlivesAMeasurement()
    {
        $body = 'Nos ressources https://pad.example.org/un';
        $approvals = new SpamApprovals($this->store);
        $approvals->approve('monwiki', 'Ressources', $body);

        $this->store->save('monwiki', ['pages' => 12, 'entries' => 3]);

        $fresh = new SpamApprovals($this->store);
        $this->assertTrue($fresh->isApproved('monwiki', 'Ressources', $body), 'une mesure ne doit pas effacer ce qu\'une personne a décidé');
        $this->assertSame(12, $this->store->read('monwiki')['pages']);
    }

    public function testAValidationDoesNotPretendTheWikiWasMeasured()
    {
        $this->store->save('monwiki', ['pages' => 12]);
        $measured = $this->store->read('monwiki')['computedAt'];

        (new SpamApprovals($this->store))->approve('monwiki', 'Ressources', 'du texte');

        $this->assertSame($measured, $this->store->read('monwiki')['computedAt']);
    }

    private function countTriples(): int
    {
        $db = self::getWiki()->services->get(DbService::class);

        return (int)($db->loadSingle('SELECT COUNT(*) AS n FROM ' . $db->prefixTable('triples'))['n'] ?? 0);
    }
}
