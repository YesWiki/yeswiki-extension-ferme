<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\FarmDashboard;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(FarmDashboard::class, 'select')]
#[CoversMethod(FarmDashboard::class, 'isDormant')]
#[CoversMethod(FarmDashboard::class, 'isToUpdate')]
class FarmDashboardTest extends YesWikiTestCase
{
    private FarmDashboard $dashboard;
    private array $current = ['version' => 'doryphore', 'release' => '4.6.7'];

    protected function setUp(): void
    {
        self::getWiki();
        $this->dashboard = new FarmDashboard();
    }

    public function testEachWikiCarriesItsStatsAndWhatTheySayAboutIt()
    {
        $page = $this->dashboard->select(
            [$this->fiche('alpha'), $this->fiche('beta')],
            ['alpha' => $this->stats(['entries' => 12])],
            $this->current
        );

        $alpha = $page['fiches'][0];
        $this->assertSame(12, $alpha['stats']['entries']);
        $this->assertSame(1500, $alpha['stats']['diskBytes'], 'disk is the three folders added up');
        $this->assertNull($page['fiches'][1]['stats'], 'a wiki nobody measured carries nothing, not zeros');
    }

    public function testTheSummaryCountsEveryStateBeforeAnyFilterIsApplied()
    {
        $page = $this->dashboard->select(
            [$this->fiche('avirer'), $this->fiche('dormant'), $this->fiche('lourd'), $this->fiche('casse'), $this->fiche('inconnu')],
            [
                'avirer' => $this->stats(['release' => '4.6.0']),
                'dormant' => $this->stats(['lastActivity' => date('Y-m-d H:i:s', strtotime('-8 months'))]),
                'lourd' => $this->stats(['privateBytes' => 3 * FarmDashboard::HEAVY_ARCHIVES]),
                'casse' => $this->stats(['status' => WikiStatsStore::STATUS_ERROR]),
            ],
            $this->current,
            '',
            'dormant'
        );

        $this->assertSame(['toUpdate' => 1, 'dormant' => 1, 'heavyArchives' => 1, 'failed' => 1, 'unmeasured' => 1], $page['counts']);
        $this->assertSame(5, $page['total']);
        $this->assertSame(1, $page['filtered'], 'the chip narrowed the list');
        $this->assertSame('dormant', $page['fiches'][0]['bf_dossier-wiki']);
    }

    public function testAWikiOnAnotherVersionOrAnOlderReleaseIsToUpdate()
    {
        $this->assertTrue($this->dashboard->isToUpdate(['version' => 'cercopitheque', 'release' => '4.4.5'], $this->current));
        $this->assertTrue($this->dashboard->isToUpdate(['version' => 'doryphore', 'release' => '4.6.0'], $this->current));
        $this->assertFalse($this->dashboard->isToUpdate(['version' => 'Doryphore', 'release' => '4.6.7'], $this->current));
        $this->assertFalse($this->dashboard->isToUpdate([], $this->current), 'an unmeasured wiki is not claimed to be behind');
    }

    public function testDormantMeansNoEditForSixMonths()
    {
        $this->assertTrue($this->dashboard->isDormant(['lastActivity' => date('Y-m-d H:i:s', strtotime('-7 months'))]));
        $this->assertFalse($this->dashboard->isDormant(['lastActivity' => date('Y-m-d H:i:s', strtotime('-5 months'))]));
        $this->assertFalse($this->dashboard->isDormant([]), 'a wiki nobody measured is not dormant, it is unknown');
    }

    public function testTotalsAddUpWhatTheFarmHolds()
    {
        $page = $this->dashboard->select(
            [$this->fiche('alpha'), $this->fiche('beta'), $this->fiche('inconnu')],
            [
                'alpha' => $this->stats(['users' => 10, 'entries' => 100, 'files' => 5]),
                'beta' => $this->stats(['users' => 7, 'entries' => 3, 'files' => 2]),
            ],
            $this->current
        );

        $this->assertSame(3, $page['totals']['wikis']);
        $this->assertSame(17, $page['totals']['users']);
        $this->assertSame(103, $page['totals']['entries']);
        $this->assertSame(7, $page['totals']['files']);
        $this->assertSame(3000, $page['totals']['diskBytes']);
    }

    public function testSortingOnAStatOrdersTheWholeFarmAndNotJustThePage()
    {
        $page = $this->dashboard->select(
            [$this->fiche('petit'), $this->fiche('gros'), $this->fiche('moyen')],
            [
                'petit' => $this->stats(['entries' => 1]),
                'gros' => $this->stats(['entries' => 900]),
                'moyen' => $this->stats(['entries' => 50]),
            ],
            $this->current,
            '',
            '',
            'entries',
            'desc'
        );

        $this->assertSame(['gros', 'moyen', 'petit'], array_column($page['fiches'], 'bf_dossier-wiki'));
    }

    public function testAWikiWithNoStatsSortsLastWhicheverWayTheColumnGoes()
    {
        foreach (['asc', 'desc'] as $direction) {
            $page = $this->dashboard->select(
                [$this->fiche('inconnu'), $this->fiche('connu')],
                ['connu' => $this->stats(['entries' => 5])],
                $this->current,
                '',
                '',
                'entries',
                $direction
            );

            $this->assertSame('connu', $page['fiches'][0]['bf_dossier-wiki'], 'direction ' . $direction);
        }
    }

    public function testSortingByTitleIgnoresCaseAndIsTheDefault()
    {
        $page = $this->dashboard->select(
            [$this->fiche('b', 'banana'), $this->fiche('a', 'Ananas')],
            [],
            $this->current
        );

        $this->assertSame(['Ananas', 'banana'], array_column($page['fiches'], 'bf_titre'));
    }

    public function testSearchLooksAtTitleOwnerMailAndFolder()
    {
        $fiches = [$this->fiche('alpha', 'Le premier'), $this->fiche('beta', 'Le second')];
        $fiches[1]['bf_mail'] = 'contact@exemple.org';

        foreach (['second', 'contact@', 'beta'] as $needle) {
            $page = $this->dashboard->select($fiches, [], $this->current, $needle);
            $this->assertSame(1, $page['filtered'], 'searching ' . $needle);
            $this->assertSame('beta', $page['fiches'][0]['bf_dossier-wiki']);
        }
    }

    public function testThePageIsASliceAndTheCountsStayWholeFarm()
    {
        $fiches = [];
        for ($i = 0; $i < 25; $i++) {
            $fiches[] = $this->fiche('wiki' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
        }

        $page = $this->dashboard->select($fiches, [], $this->current, '', '', 'title', 'asc', 20, 10);

        $this->assertCount(5, $page['fiches']);
        $this->assertSame(25, $page['total']);
        $this->assertSame(25, $page['filtered']);
        $this->assertSame(25, $page['counts']['unmeasured']);
    }

    public function testAnUnknownSortOrFilterFallsBackInsteadOfBreaking()
    {
        $page = $this->dashboard->select(
            [$this->fiche('b', 'beta'), $this->fiche('a', 'alpha')],
            [],
            $this->current,
            '',
            'pasunfiltre',
            'DROP TABLE'
        );

        $this->assertSame(2, $page['filtered']);
        $this->assertSame('alpha', $page['fiches'][0]['bf_titre']);
    }

    private function fiche(string $folder, ?string $title = null): array
    {
        return [
            'id_fiche' => 'Fiche' . ucfirst($folder),
            'bf_dossier-wiki' => $folder,
            'bf_titre' => $title ?? ucfirst($folder),
            'bf_referent' => 'Personne ' . $folder,
            'bf_mail' => $folder . '@exemple.org',
        ];
    }

    private function stats(array $override = []): array
    {
        return array_merge([
            'users' => 1,
            'forms' => 1,
            'entries' => 1,
            'pages' => 1,
            'files' => 1,
            'filesBytes' => 1000,
            'customBytes' => 500,
            'privateBytes' => 0,
            'lastActivity' => date('Y-m-d H:i:s'),
            'computedAt' => date('Y-m-d H:i:s'),
            'checkedAt' => date('Y-m-d H:i:s'),
            'status' => WikiStatsStore::STATUS_OK,
            'version' => 'doryphore',
            'release' => '4.6.7',
            'activity' => array_fill(0, 12, 0),
        ], $override);
    }
}
