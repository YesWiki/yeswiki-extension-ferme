<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Core\Service\DbService;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(WikiStatsStore::class, 'save')]
#[CoversMethod(WikiStatsStore::class, 'fail')]
#[CoversMethod(WikiStatsStore::class, 'read')]
#[CoversMethod(WikiStatsStore::class, 'readMany')]
#[CoversMethod(WikiStatsStore::class, 'forget')]
#[CoversMethod(WikiStatsStore::class, 'forgetOrphans')]
class WikiStatsStoreTest extends YesWikiTestCase
{
    private DbService $db;
    private WikiStatsStore $store;
    private array $folders = [];
    private int $triplesBefore;

    protected function setUp(): void
    {
        $this->db = self::getWiki()->services->get(DbService::class);
        $this->store = new WikiStatsStore($this->db);
        $this->triplesBefore = $this->countTriples();
    }

    protected function tearDown(): void
    {
        foreach ($this->folders as $folder) {
            $this->store->forget($folder);
        }

        $this->assertSame($this->triplesBefore, $this->countTriples(), 'a test must leave the triples table as it found it');
    }

    public function testWhatIsSavedComesBackTyped()
    {
        $folder = $this->folder();
        $this->store->save($folder, $this->measurements());

        $stats = $this->store->read($folder);

        $this->assertSame(740, $stats['users']);
        $this->assertSame(3873, $stats['entries']);
        $this->assertSame(1602000000, $stats['filesBytes']);
        $this->assertSame([0, 3, 17, 0, 0, 5, 9, 0, 0, 1, 2, 44], $stats['activity']);
        $this->assertSame('2026-09-20 18:30:00', $stats['lastActivity']);
        $this->assertSame(WikiStatsStore::STATUS_OK, $stats['status']);
        $this->assertNotEmpty($stats['computedAt']);
        $this->assertSame($stats['computedAt'], $stats['checkedAt']);
    }

    public function testAWikiThatWasNeverMeasuredReadsAsNothingRatherThanZeros()
    {
        $this->assertNull($this->store->read($this->folder()));
    }

    public function testAValueThatWasNotMeasuredIsNotStoredAtAll()
    {
        $folder = $this->folder();
        $this->store->save($folder, array_merge($this->measurements(), ['lastActivity' => null]));

        $stats = $this->store->read($folder);

        $this->assertArrayNotHasKey('lastActivity', $stats, 'never touched is not the same as touched at an unknown date');
        $this->assertArrayHasKey('users', $stats);
    }

    public function testSavingTwiceReplacesTheValuesInsteadOfPilingThemUp()
    {
        $folder = $this->folder();
        $this->store->save($folder, $this->measurements());
        $rows = $this->countTriples();

        $this->store->save($folder, array_merge($this->measurements(), ['users' => 741]));

        $this->assertSame($rows, $this->countTriples());
        $this->assertSame(741, $this->store->read($folder)['users']);
    }

    public function testTouchingMovesTheCheckDateAndNothingElse()
    {
        $folder = $this->folder();
        $this->store->save($folder, $this->measurements());
        $saved = $this->store->read($folder);
        $rows = $this->countTriples();

        sleep(1);
        $this->store->touch($folder);

        $touched = $this->store->read($folder);
        $this->assertNotSame($saved['checkedAt'], $touched['checkedAt'], 'the check date moved');
        $this->assertSame($saved['computedAt'], $touched['computedAt'], 'the numbers are still from when they were made');
        $this->assertSame($saved['entries'], $touched['entries']);
        $this->assertSame($rows, $this->countTriples(), 'and nothing was rewritten');
    }

    public function testTouchingAWikiNobodyMeasuredWritesNothing()
    {
        $folder = $this->folder();
        $rows = $this->countTriples();

        $this->store->touch($folder);

        $this->assertNull($this->store->read($folder));
        $this->assertSame($rows, $this->countTriples());
    }

    public function testAFailedRunKeepsTheNumbersOfTheLastGoodOne()
    {
        $folder = $this->folder();
        $this->store->save($folder, $this->measurements());
        $computedAt = $this->store->read($folder)['computedAt'];

        $this->store->fail($folder, 'Connection refused for "monwiki"');

        $stats = $this->store->read($folder);
        $this->assertSame(740, $stats['users'], 'the numbers of the last good run survive');
        $this->assertSame(WikiStatsStore::STATUS_ERROR, $stats['status']);
        $this->assertStringContainsString('Connection refused', $stats['error']);
        $this->assertSame($computedAt, $stats['computedAt'], 'computedAt dates the numbers, not the attempt');
        $this->assertNotEmpty($stats['checkedAt']);
    }

    public function testAGoodRunAfterAFailedOneClearsTheError()
    {
        $folder = $this->folder();
        $this->store->fail($folder, 'la base ne répond pas');
        $this->store->save($folder, $this->measurements());

        $stats = $this->store->read($folder);

        $this->assertSame(WikiStatsStore::STATUS_OK, $stats['status']);
        $this->assertArrayNotHasKey('error', $stats);
    }

    public function testManyWikisComeBackInOneRead()
    {
        $first = $this->folder();
        $second = $this->folder();
        $this->store->save($first, $this->measurements());
        $this->store->save($second, array_merge($this->measurements(), ['users' => 12]));

        $found = $this->store->readMany([$first, $second, 'jamaismesure' . bin2hex(random_bytes(3))]);

        $this->assertCount(2, $found, 'a wiki with no stats is absent, not empty');
        $this->assertSame(740, $found[$first]['users']);
        $this->assertSame(12, $found[$second]['users']);
    }

    public function testForgettingOneWikiLeavesTheOthersAlone()
    {
        $kept = $this->folder();
        $dropped = $this->folder();
        $this->store->save($kept, $this->measurements());
        $this->store->save($dropped, $this->measurements());

        $this->store->forget($dropped);

        $this->assertNull($this->store->read($dropped));
        $this->assertNotNull($this->store->read($kept));
    }

    public function testTheOrphanSweepOnlyDropsWikisThatAreGone()
    {
        $live = $this->folder();
        $gone = $this->folder();
        $this->store->save($live, $this->measurements());
        $this->store->save($gone, $this->measurements());

        $orphans = $this->store->forgetOrphans(array_merge($this->otherMeasuredFolders([$live, $gone]), [$live]));

        $this->assertSame([$gone], $orphans);
        $this->assertNull($this->store->read($gone));
        $this->assertNotNull($this->store->read($live));
    }

    public function testAnErrorCarryingQuotesIsStoredAsItIs()
    {
        $folder = $this->folder();
        $this->store->fail($folder, 'SQLSTATE "42S02": no such table `x_pages`, \'aborting\'');

        $this->assertSame('SQLSTATE "42S02": no such table `x_pages`, \'aborting\'', $this->store->read($folder)['error']);
    }

    public function testALongErrorIsCutRatherThanRefused()
    {
        $folder = $this->folder();
        $this->store->fail($folder, str_repeat('é', 400));

        $this->assertSame(255, mb_strlen($this->store->read($folder)['error']));
    }

    public function testTwoFoldersDifferingOnlyInCaseAreTwoWikis()
    {
        $lower = 'fermetest' . bin2hex(random_bytes(5));
        $upper = strtoupper($lower);
        $this->folders[] = $lower;
        $this->folders[] = $upper;

        $this->store->save($lower, array_merge($this->measurements(), ['entries' => 11]));
        $this->store->save($upper, array_merge($this->measurements(), ['entries' => 22]));

        $this->assertSame(11, $this->store->read($lower)['entries'], 'the second save must not have erased the first');
        $this->assertSame(22, $this->store->read($upper)['entries']);

        $both = $this->store->readMany([$lower, $upper]);
        $this->assertSame(11, $both[$lower]['entries']);
        $this->assertSame(22, $both[$upper]['entries']);

        $this->assertSame(11, $this->store->readMany([$lower])[$lower]['entries'] ?? null, 'asking for one must not answer with the other');
        $this->assertArrayNotHasKey($upper, $this->store->readMany([$lower]));
    }

    public function testForgettingOneCaseLeavesTheOtherAlone()
    {
        $lower = 'fermetest' . bin2hex(random_bytes(5));
        $upper = strtoupper($lower);
        $this->folders[] = $lower;
        $this->folders[] = $upper;
        $this->store->save($lower, $this->measurements());
        $this->store->save($upper, $this->measurements());

        $this->store->forget($upper);

        $this->assertNull($this->store->read($upper));
        $this->assertNotNull($this->store->read($lower), 'its twin is a different wiki');
    }

    public function testTouchingOneCaseDoesNotMoveTheOthersDate()
    {
        $lower = 'fermetest' . bin2hex(random_bytes(5));
        $upper = strtoupper($lower);
        $this->folders[] = $lower;
        $this->folders[] = $upper;
        $this->store->save($lower, $this->measurements());
        $this->store->save($upper, $this->measurements());
        $untouched = $this->store->read($upper)['checkedAt'];

        sleep(1);
        $this->store->touch($lower);

        $this->assertNotSame($untouched, $this->store->read($lower)['checkedAt']);
        $this->assertSame($untouched, $this->store->read($upper)['checkedAt']);
    }

    public function testAFolderNameThatIsNotOneIsRefused()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->save('../ailleurs', $this->measurements());
    }

    private function measurements(): array
    {
        return [
            'users' => 740,
            'forms' => 141,
            'entries' => 3873,
            'pages' => 4113,
            'lastPageId' => 14066,
            'lastActivity' => '2026-09-20 18:30:00',
            'activity' => [0, 3, 17, 0, 0, 5, 9, 0, 0, 1, 2, 44],
            'files' => 1022,
            'filesBytes' => 1602000000,
            'customBytes' => 89155429,
            'privateBytes' => 1399801609,
        ];
    }

    /**
     * @param array<int,string> $mine
     *
     * @return array<int,string> the folders another wiki of this farm may have stored
     */
    private function otherMeasuredFolders(array $mine): array
    {
        return array_values(array_diff($this->store->measuredFolders(), $mine));
    }

    private function folder(): string
    {
        $folder = 'fermetest' . bin2hex(random_bytes(5));
        $this->folders[] = $folder;

        return $folder;
    }

    private function countTriples(): int
    {
        return (int)($this->db->loadSingle('SELECT COUNT(*) AS n FROM ' . $this->db->prefixTable('triples'))['n'] ?? 0);
    }
}
