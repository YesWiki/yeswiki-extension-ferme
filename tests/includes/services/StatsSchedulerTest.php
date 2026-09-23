<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\LifetimeSweeper;
use YesWiki\Ferme\Service\StatsRefresher;
use YesWiki\Ferme\Service\StatsScheduler;
use YesWiki\Ferme\Service\WikiFinder;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(StatsScheduler::class, 'isEnabled')]
#[CoversMethod(StatsScheduler::class, 'due')]
class StatsSchedulerTest extends YesWikiTestCase
{
    protected function setUp(): void
    {
        self::getWiki();
    }

    public function testTheTrickleIsOnUnlessTheFarmTurnedItOff()
    {
        $this->assertTrue($this->scheduler([])->isEnabled(), 'a farm that says nothing gets the trickle');
        $this->assertTrue($this->scheduler([], true)->isEnabled());
        $this->assertFalse($this->scheduler([], false)->isEnabled());
        $this->assertFalse($this->scheduler([], 'false')->isEnabled(), 'as written in a wakka.config.php');
        $this->assertFalse($this->scheduler([], '0')->isEnabled());
    }

    public function testTheWikisCheckedLongestAgoComeFirst()
    {
        $scheduler = $this->scheduler([
            'recent' => ['checkedAt' => date('Y-m-d H:i:s')],
            'vieux' => ['checkedAt' => '2020-01-01 00:00:00'],
            'moyen' => ['checkedAt' => date('Y-m-d H:i:s', strtotime('-2 days'))],
        ]);

        $this->assertSame(['vieux', 'moyen', 'recent'], $scheduler->due(5));
    }

    public function testAWikiNobodyEverMeasuredComesBeforeAnyMeasuredOne()
    {
        $scheduler = $this->scheduler([
            'mesure' => ['checkedAt' => '2020-01-01 00:00:00'],
            'jamais' => null,
        ]);

        $this->assertSame('jamais', $scheduler->due(1)[0]);
    }

    public function testOnlyAsManyAsTheFarmAllowsPerVisit()
    {
        $scheduler = $this->scheduler(['a' => null, 'b' => null, 'c' => null, 'd' => null]);

        $this->assertCount(2, $scheduler->due(2));
        $this->assertSame([], $scheduler->due(0));
    }

    public function testAFarmWithNoWikiAsksForNothing()
    {
        $this->assertSame([], $this->scheduler([])->due(3));
    }

    private function scheduler(array $wikis, $onVisit = null): StatsScheduler
    {
        $wiki = self::getWiki();
        if ($onVisit === null) {
            unset($wiki->config['yeswiki-farm-stats-on-visit']);
        } else {
            $wiki->config['yeswiki-farm-stats-on-visit'] = $onVisit;
        }

        $finder = $this->createStub(WikiFinder::class);
        $finder->method('find')->willReturn(array_map(function (string $folder) {
            return ['FOLDER' => $folder, 'PATH' => '/farm/' . $folder, 'URL' => 'https://exemple.org/' . $folder];
        }, array_keys($wikis)));

        $store = $this->createStub(WikiStatsStore::class);
        $store->method('readMany')->willReturn(array_filter($wikis, function ($stats) {
            return $stats !== null;
        }));

        return new StatsScheduler($wiki, $finder, $store, $this->createStub(StatsRefresher::class), $this->createStub(LifetimeSweeper::class));
    }
}
