<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\ImportFilter;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** Which wikis found on disk get an entry. */
class ImportFilterTest extends FarmTestCase
{
    private ImportFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new ImportFilter();
    }

    public function testWithoutACriterionEveryWikiMissingFromTheFarmIsACandidate(): void
    {
        $selection = $this->filter->apply([
            $this->wiki('deja', ['existsInBazar' => true]),
            $this->wiki('orphelin'),
            $this->wiki('jamaismesure', ['stats' => null]),
        ], []);

        $this->assertSame(['orphelin', 'jamaismesure'], array_column($selection['keep'], 'folder'));
        $this->assertSame(['deja'], $selection['left']['inBazar']);
    }

    public function testAThresholdOnEntriesLeavesTheFreshOnesBehind(): void
    {
        $selection = $this->filter->apply([
            $this->wiki('vrai', ['stats' => $this->stats(['entries' => 170])]),
            $this->wiki('sortidumodele', ['stats' => $this->stats(['entries' => 9])]),
        ], ['minEntries' => 15]);

        $this->assertSame(['vrai'], array_column($selection['keep'], 'folder'));
        $this->assertSame(['sortidumodele'], $selection['left']['entries']);
    }

    public function testPagesAndAccountsFilterToo(): void
    {
        $wikis = [
            $this->wiki('petit', ['stats' => $this->stats(['pages' => 40, 'users' => 1])]),
            $this->wiki('grand', ['stats' => $this->stats(['pages' => 400, 'users' => 12])]),
        ];

        $this->assertSame(['grand'], array_column($this->filter->apply($wikis, ['minPages' => 100])['keep'], 'folder'));
        $this->assertSame(['grand'], array_column($this->filter->apply($wikis, ['minUsers' => 3])['keep'], 'folder'));
    }

    public function testAnIdleWikiIsLeftOut(): void
    {
        $selection = $this->filter->apply([
            $this->wiki('vivant', ['stats' => $this->stats(['lastActivity' => date('Y-m-d H:i:s', strtotime('-10 days'))])]),
            $this->wiki('endormi', ['stats' => $this->stats(['lastActivity' => date('Y-m-d H:i:s', strtotime('-400 days'))])]),
        ], ['activeSince' => 180 * 86400]);

        $this->assertSame(['vivant'], array_column($selection['keep'], 'folder'));
        $this->assertSame(['endormi'], $selection['left']['idle']);
    }

    public function testANameThatMatchesTheExpressionIsLeftOut(): void
    {
        $selection = $this->filter->apply([
            $this->wiki('assocdupont'),
            $this->wiki('33winsurf'),
            $this->wiki('Papergraders'),
        ], ['nameExcludes' => 'bet|win|casino|essay|paper']);

        $this->assertSame(['assocdupont'], array_column($selection['keep'], 'folder'));
        $this->assertSame(['33winsurf', 'Papergraders'], $selection['left']['name']);
    }

    public function testAWikiThatLooksLikeSpamIsLeftOutWhenAsked(): void
    {
        $wikis = [
            $this->wiki('assoc', ['stats' => $this->stats(['suspect' => 2])]),
            $this->wiki('33winsurf', ['stats' => $this->stats(['suspect' => 8])]),
        ];

        $selection = $this->filter->apply($wikis, ['skipSuspect' => 3]);

        $this->assertSame(['assoc'], array_column($selection['keep'], 'folder'));
        $this->assertSame(['33winsurf'], $selection['left']['suspect']);
        $this->assertCount(2, $this->filter->apply($wikis, [])['keep']);
    }

    public function testAWikiNobodyMeasuredCannotBeJudgedSoItIsLeftOut(): void
    {
        $selection = $this->filter->apply([$this->wiki('inconnu', ['stats' => null])], ['minEntries' => 15]);

        $this->assertSame([], $selection['keep']);
        $this->assertSame(['inconnu'], $selection['left']['unmeasured']);
    }

    public function testABrokenExpressionDoesNotThrowAndKeepsTheWiki(): void
    {
        $selection = @$this->filter->apply([$this->wiki('assoc')], ['nameExcludes' => '([']);

        $this->assertSame(['assoc'], array_column($selection['keep'], 'folder'));
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function wiki(string $folder, array $override = []): array
    {
        return array_merge([
            'folder' => $folder,
            'existsInBazar' => false,
            'stats' => $this->stats(),
        ], $override);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function stats(array $override = []): array
    {
        return array_merge([
            'pages' => 128,
            'entries' => 9,
            'users' => 1,
            'lastActivity' => date('Y-m-d H:i:s'),
        ], $override);
    }
}
