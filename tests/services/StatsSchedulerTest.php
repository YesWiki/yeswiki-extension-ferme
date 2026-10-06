<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\LifetimeSweeper;
use YesWiki\Ferme\Service\StatsRefresher;
use YesWiki\Ferme\Service\StatsScheduler;
use YesWiki\Ferme\Service\WikiFinder;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** Which wikis the housekeeping measures, oldest check first. */
class StatsSchedulerTest extends FarmTestCase
{
    public function testTheTrickleIsOnUnlessTheFarmTurnedItOff(): void
    {
        $this->assertTrue($this->scheduler([])->isEnabled());
        $this->assertTrue($this->scheduler([], true)->isEnabled());
        $this->assertFalse($this->scheduler([], false)->isEnabled());
        $this->assertFalse($this->scheduler([], 'false')->isEnabled());
        $this->assertFalse($this->scheduler([], '0')->isEnabled());
    }

    public function testTheWikisCheckedLongestAgoComeFirst(): void
    {
        $scheduler = $this->scheduler([
            'recent' => ['checkedAt' => date('Y-m-d H:i:s')],
            'vieux' => ['checkedAt' => '2020-01-01 00:00:00'],
            'moyen' => ['checkedAt' => date('Y-m-d H:i:s', (int)strtotime('-2 days'))],
        ]);

        $this->assertSame(['vieux', 'moyen', 'recent'], $scheduler->due(5));
    }

    public function testAWikiNobodyEverMeasuredComesBeforeAnyMeasuredOne(): void
    {
        $this->assertSame('jamais', $this->scheduler(['mesure' => ['checkedAt' => '2020-01-01 00:00:00'], 'jamais' => null])->due(1)[0]);
    }

    public function testOnlyAsManyAsTheFarmAllowsPerVisit(): void
    {
        $scheduler = $this->scheduler(['a' => null, 'b' => null, 'c' => null, 'd' => null]);

        $this->assertCount(2, $scheduler->due(2));
        $this->assertSame([], $scheduler->due(0));
    }

    public function testAFarmWithNoWikiAsksForNothing(): void
    {
        $this->assertSame([], $this->scheduler([])->due(3));
    }

    /** @param array<string, array<string, string>|null> $wikis */
    private function scheduler(array $wikis, mixed $onVisit = null): StatsScheduler
    {
        $farm = $this->farm($onVisit === null ? [] : ['yeswiki-farm-stats-on-visit' => $onVisit]);

        $finder = $this->createStub(WikiFinder::class);
        $finder->method('find')->willReturn(array_map(static fn (string $folder): array => ['FOLDER' => $folder, 'PATH' => '/farm/' . $folder, 'URL' => 'https://exemple.org/' . $folder, 'VERSION' => '', 'RELEASE' => ''], array_keys($wikis)));

        $store = $this->createStub(WikiStatsStore::class);
        $store->method('readMany')->willReturn(array_filter($wikis, static fn (?array $stats): bool => $stats !== null));

        return new StatsScheduler($farm, $finder, $store, $this->createStub(StatsRefresher::class), $this->createStub(LifetimeSweeper::class));
    }
}
