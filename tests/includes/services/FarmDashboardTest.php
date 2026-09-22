<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\FarmDashboard;
use YesWiki\Ferme\Service\SpamApprovals;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(FarmDashboard::class, 'select')]
#[CoversMethod(FarmDashboard::class, 'isDormant')]
#[CoversMethod(FarmDashboard::class, 'isToUpdate')]
#[CoversMethod(FarmDashboard::class, 'wasNeverEdited')]
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
        $page = $this->select(
            [$this->fiche('alpha'), $this->fiche('beta')],
            ['alpha' => $this->stats(['entries' => 12])],
        );

        $alpha = $page['fiches'][0];
        $this->assertSame(12, $alpha['stats']['entries']);
        $this->assertSame(1500, $alpha['stats']['diskBytes'], 'disk is the three folders added up');
        $this->assertNull($page['fiches'][1]['stats'], 'a wiki nobody measured carries nothing, not zeros');
    }

    public function testTheSummaryCountsEveryStateBeforeAnyFilterIsApplied()
    {
        $page = $this->select(
            [$this->fiche('avirer'), $this->fiche('dormant'), $this->fiche('lourd'), $this->fiche('casse'), $this->fiche('inconnu')],
            [
                'avirer' => $this->stats(['release' => '4.6.0']),
                'dormant' => $this->stats(['lastActivity' => date('Y-m-d H:i:s', strtotime('-8 months'))]),
                'lourd' => $this->stats(['privateBytes' => 3 * FarmDashboard::HEAVY_ARCHIVES]),
                'casse' => $this->stats(['status' => WikiStatsStore::STATUS_ERROR]),
            ],
            [],
            '',
            'dormant'
        );

        $this->assertSame(
            ['toUpdate' => 1, 'dormant' => 1, 'neverEdited' => 0, 'heavyArchives' => 1, 'suspect' => 0, 'spammed' => 0, 'failed' => 1, 'unmeasured' => 1, 'hibernating' => 0, 'running' => 5],
            $page['counts']
        );
        $this->assertSame(5, $page['total']);
        $this->assertSame(1, $page['filtered'], 'the chip narrowed the list');
        $this->assertSame('dormant', $page['fiches'][0]['bf_dossier-wiki']);
    }

    public function testAWikiScoredAboveTheThresholdIsCountedAsSuspectAndTheChipSelectsIt()
    {
        $page = $this->dashboard->select(
            [$this->fiche('spam'), $this->fiche('sain')],
            [
                'spam' => $this->stats(['suspect' => 5, 'suspectWhy' => 'words,untouched']),
                'sain' => $this->stats(['suspect' => 2, 'suspectWhy' => 'untouched']),
            ],
            ['current' => $this->current, 'spamThreshold' => 3],
            ['filter' => 'suspect']
        );

        $this->assertSame(1, $page['counts']['suspect'], 'deux points ne suffisent pas');
        $this->assertSame(['spam'], array_column($page['fiches'], 'bf_dossier-wiki'));
        $this->assertSame(['words', 'untouched'], $page['fiches'][0]['stats']['suspectWhy']);
    }

    public function testTheSuspicionThresholdIsTheFarmsToSet()
    {
        $fiches = [$this->fiche('limite')];
        $stats = ['limite' => $this->stats(['suspect' => 3])];

        $strict = $this->dashboard->select($fiches, $stats, ['current' => $this->current, 'spamThreshold' => 3]);
        $laxe = $this->dashboard->select($fiches, $stats, ['current' => $this->current, 'spamThreshold' => 6]);

        $this->assertSame(1, $strict['counts']['suspect']);
        $this->assertSame(0, $laxe['counts']['suspect']);
    }

    public function testAnEntryWhoseWikiIsGoneCountsAsBrokenAndNotAsUnmeasured()
    {
        $page = $this->select(
            [$this->fiche('vivant'), $this->fiche('disparu')],
            ['vivant' => $this->stats()],
            ['vivant' => true, 'disparu' => false]
        );

        $this->assertSame(1, $page['counts']['failed'], 'the entry with no wiki behind it');
        $this->assertSame(0, $page['counts']['unmeasured'], 'and it is not merely waiting to be measured');
        $this->assertTrue($page['fiches'][0]['problems']['missingWiki'] ?? $page['fiches'][1]['problems']['missingWiki']);
    }

    public function testTwoEntriesClaimingTheSameFolderAreBothFlagged()
    {
        $fiches = [$this->fiche('memedossier', 'Premier'), $this->fiche('memedossier', 'Second'), $this->fiche('seul')];

        $page = $this->select($fiches, [], ['memedossier' => true, 'seul' => true]);

        $this->assertSame(2, $page['counts']['failed']);
        foreach ($page['fiches'] as $fiche) {
            $expected = $fiche['bf_dossier-wiki'] === 'memedossier';
            $this->assertSame($expected, $fiche['problems']['duplicateFolder'], $fiche['bf_titre']);
        }
    }

    public function testTheErrorChipSelectsBrokenEntriesAndFailedMeasurements()
    {
        $page = $this->select(
            [$this->fiche('disparu'), $this->fiche('casse'), $this->fiche('sain')],
            [
                'casse' => $this->stats(['status' => WikiStatsStore::STATUS_ERROR]),
                'sain' => $this->stats(),
            ],
            ['disparu' => false, 'casse' => true, 'sain' => true],
            '',
            'failed'
        );

        $this->assertSame(2, $page['filtered']);
        $this->assertSame(['casse', 'disparu'], array_column($page['fiches'], 'bf_dossier-wiki'), 'sorted by title, as always');
    }

    public function testAnEntryWithNoFolderAtAllIsBrokenToo()
    {
        $orphan = $this->fiche('sansdossier');
        $orphan['bf_dossier-wiki'] = '';

        $page = $this->select([$orphan], [], []);

        $this->assertTrue($page['fiches'][0]['problems']['noFolder']);
        $this->assertSame(1, $page['counts']['failed']);
    }

    public function testAFolderNobodyCheckedOnDiskIsNotAccused()
    {
        $page = $this->select([$this->fiche('alpha')], [], []);

        $this->assertFalse($page['fiches'][0]['problems']['missingWiki'], 'not knowing is not the same as missing');
        $this->assertSame(1, $page['counts']['unmeasured']);
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
        $page = $this->select(
            [$this->fiche('alpha'), $this->fiche('beta'), $this->fiche('inconnu')],
            [
                'alpha' => $this->stats(['users' => 10, 'entries' => 100, 'files' => 5]),
                'beta' => $this->stats(['users' => 7, 'entries' => 3, 'files' => 2]),
            ],
        );

        $this->assertSame(3, $page['totals']['wikis']);
        $this->assertSame(2, $page['totals']['measured'], 'the third wiki was never measured');
        $this->assertSame(17, $page['totals']['users']);
        $this->assertSame(103, $page['totals']['entries']);
        $this->assertSame(7, $page['totals']['files']);
        $this->assertSame(3000, $page['totals']['diskBytes']);
    }

    public function testAFarmNobodyMeasuredYetSaysSoRatherThanAddingUpToZero()
    {
        $page = $this->select([$this->fiche('alpha'), $this->fiche('beta')], []);

        $this->assertSame(2, $page['totals']['wikis']);
        $this->assertSame(0, $page['totals']['measured'], 'which is what lets the page show a question mark');
        $this->assertSame(0, $page['totals']['entries']);
    }

    public function testSortingOnAStatOrdersTheWholeFarmAndNotJustThePage()
    {
        $page = $this->select(
            [$this->fiche('petit'), $this->fiche('gros'), $this->fiche('moyen')],
            [
                'petit' => $this->stats(['entries' => 1]),
                'gros' => $this->stats(['entries' => 900]),
                'moyen' => $this->stats(['entries' => 50]),
            ],
            [],
            '',
            '',
            'entries',
            'desc'
        );

        $this->assertSame(['gros', 'moyen', 'petit'], array_column($page['fiches'], 'bf_dossier-wiki'));
    }

    public function testSortingByTheYearsActivityAddsUpTheTwelveMonths()
    {
        $page = $this->select(
            [$this->fiche('regulier'), $this->fiche('unecoupdefeu'), $this->fiche('mort')],
            [
                'regulier' => $this->stats(['activity' => array_fill(0, 12, 20)]),
                'unecoupdefeu' => $this->stats(['activity' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 300]]),
                'mort' => $this->stats(['activity' => array_fill(0, 12, 0)]),
            ],
            [],
            '',
            '',
            'activity',
            'desc'
        );

        $this->assertSame(['unecoupdefeu', 'regulier', 'mort'], array_column($page['fiches'], 'bf_dossier-wiki'));
    }

    public function testAWikiWithNoStatsSortsLastWhicheverWayTheColumnGoes()
    {
        foreach (['asc', 'desc'] as $direction) {
            $page = $this->select(
                [$this->fiche('inconnu'), $this->fiche('connu')],
                ['connu' => $this->stats(['entries' => 5])],
                [],
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
        $page = $this->select(
            [$this->fiche('b', 'banana'), $this->fiche('a', 'Ananas')],
            [],
        );

        $this->assertSame(['Ananas', 'banana'], array_column($page['fiches'], 'bf_titre'));
    }

    public function testSearchLooksAtTitleOwnerMailAndFolder()
    {
        $fiches = [$this->fiche('alpha', 'Le premier'), $this->fiche('beta', 'Le second')];
        $fiches[1]['bf_mail'] = 'contact@exemple.org';

        foreach (['second', 'contact@', 'beta'] as $needle) {
            $page = $this->select($fiches, [], [], $needle);
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

        $page = $this->select($fiches, [], [], '', '', 'title', 'asc', 20, 10);

        $this->assertCount(5, $page['fiches']);
        $this->assertSame(25, $page['total']);
        $this->assertSame(25, $page['filtered']);
        $this->assertSame(25, $page['counts']['unmeasured']);
    }

    public function testAnUnknownSortOrFilterFallsBackInsteadOfBreaking()
    {
        $page = $this->select(
            [$this->fiche('b', 'beta'), $this->fiche('a', 'alpha')],
            [],
            [],
            '',
            'pasunfiltre',
            'DROP TABLE'
        );

        $this->assertSame(2, $page['filtered']);
        $this->assertSame('alpha', $page['fiches'][0]['bf_titre']);
    }

    /**
     * The dashboard's own signature groups its arguments; the tests keep reading
     * as a list of what varies.
     */
    public function testASleepingWikiIsCountedAndCanBeSingledOut()
    {
        $fiches = [
            $this->fiche('dormeur', null, 'hibernate'),
            $this->fiche('archive', null, 'archiving'),
            $this->fiche('actif'),
            $this->fiche('actifaussi', null, 'running'),
        ];
        $stats = ['dormeur' => $this->stats(), 'archive' => $this->stats(), 'actif' => $this->stats(), 'actifaussi' => $this->stats()];

        $page = $this->select($fiches, $stats);
        $this->assertSame(2, $page['counts']['hibernating'], 'archiving refuses writes just as hibernation does');

        $asleep = $this->select($fiches, $stats, [], '', 'hibernating');
        $this->assertSame(2, $asleep['filtered']);
        $this->assertSame(['archive', 'dormeur'], array_column($asleep['fiches'], 'bf_dossier-wiki'));

        $awake = $this->select($fiches, $stats, [], '', 'running');
        $this->assertSame(2, $awake['filtered'], 'et le clic sur « en service » montre les autres');
        $this->assertSame(['actif', 'actifaussi'], array_column($awake['fiches'], 'bf_dossier-wiki'));
    }

    public function testTheSummarySaysHowManyAreInServiceAndHowManyAsleep()
    {
        $page = $this->select(
            [
                $this->fiche('dormeur', null, 'hibernate'),
                $this->fiche('archive', null, 'archiving'),
                $this->fiche('actif'),
                $this->fiche('actifaussi', null, 'running'),
            ],
            []
        );

        $this->assertSame(4, $page['totals']['wikis']);
        $this->assertSame(2, $page['totals']['running']);
        $this->assertSame(2, $page['totals']['hibernating']);
    }

    public function testAWikiPutToSleepIsNotAlsoCalledDormant()
    {
        $vieux = date('Y-m-d H:i:s', strtotime('-8 months'));
        $fiches = [$this->fiche('oublie'), $this->fiche('endormi', null, 'hibernate')];
        $stats = [
            'oublie' => $this->stats(['lastActivity' => $vieux]),
            'endormi' => $this->stats(['lastActivity' => $vieux]),
        ];

        $page = $this->select($fiches, $stats);

        $this->assertSame(1, $page['counts']['dormant'], 'seul celui que personne n\'a endormi');
        $this->assertSame(1, $page['counts']['hibernating']);
        $byFolder = array_column($page['fiches'], 'stats', 'bf_dossier-wiki');
        $this->assertTrue($byFolder['oublie']['dormant']);
        $this->assertFalse($byFolder['endormi']['dormant'], 'il dort parce qu\'on l\'a voulu');
    }

    public function testTheSleepingOnesAreCountedEvenWhenTheyAreNotOnThePage()
    {
        $fiches = [];
        for ($i = 1; $i <= 120; $i++) {
            $fiches[] = $this->fiche('wiki' . str_pad((string)$i, 3, '0', STR_PAD_LEFT));
        }
        $statuses = ['wiki119' => 'hibernate', 'wiki120' => 'hibernate'];

        $page = $this->dashboard->select($fiches, [], ['statuses' => $statuses], ['start' => 0, 'length' => 10]);

        $this->assertCount(10, $page['fiches'], 'une page de dix');
        $this->assertSame(2, $page['totals']['hibernating'], 'les dormeurs des pages suivantes comptent aussi');
        $this->assertSame(118, $page['totals']['running']);
        $this->assertSame(2, $page['counts']['hibernating']);
    }

    public function testASleepingWikiIsStillCountedAmongTheBrokenOrTheUnmeasured()
    {
        $page = $this->select(
            [$this->fiche('dormeurcasse', null, 'hibernate'), $this->fiche('dormeurjamaisvu', null, 'hibernate')],
            ['dormeurcasse' => $this->stats(['status' => WikiStatsStore::STATUS_ERROR])]
        );

        $this->assertSame(2, $page['counts']['hibernating']);
        $this->assertSame(1, $page['counts']['failed']);
        $this->assertSame(1, $page['counts']['unmeasured']);
    }

    public function testAWikiNobodyWroteInSinceItsInstallIsCountedAndFiltered()
    {
        $page = $this->select(
            [$this->fiche('neuf'), $this->fiche('vivant')],
            [
                'neuf' => $this->stats(['editedPages' => 0]),
                'vivant' => $this->stats(['editedPages' => 12, 'lastEdit' => date('Y-m-d H:i:s')]),
            ],
            [],
            '',
            'neverEdited'
        );

        $this->assertSame(1, $page['counts']['neverEdited']);
        $this->assertSame(1, $page['filtered']);
        $this->assertSame('neuf', $page['fiches'][0]['bf_dossier-wiki']);
        $this->assertTrue($page['fiches'][0]['stats']['neverEdited']);
    }

    public function testAWikiSomebodyTriedOutAndLeftCountsAsUntouched()
    {
        $long = date('Y-m-d H:i:s', strtotime('-8 months'));

        $this->assertTrue(
            $this->dashboard->wasNeverEdited($this->stats(['editedPages' => 5, 'lastEdit' => $long])),
            'cinq pages touchées il y a huit mois, et plus rien'
        );
        $this->assertFalse(
            $this->dashboard->wasNeverEdited($this->stats(['editedPages' => 6, 'lastEdit' => $long])),
            'une page de plus et ce n\'est plus un essai'
        );
        $this->assertFalse(
            $this->dashboard->wasNeverEdited($this->stats([
                'editedPages' => 2,
                'lastEdit' => date('Y-m-d H:i:s', strtotime('-2 months')),
            ])),
            'quelqu\'un y est revenu ce semestre'
        );
    }

    public function testAWikiIsGivenAMonthBeforeBeingCalledUntouched()
    {
        $this->assertFalse(
            $this->dashboard->wasNeverEdited($this->stats([
                'firstActivity' => date('Y-m-d H:i:s', strtotime('-3 days')),
                'editedPages' => 0,
            ])),
            'installé cette semaine, il n\'a pas encore eu sa chance'
        );
    }

    public function testAWikiNobodyMeasuredIsNotClaimedUntouched()
    {
        $this->assertFalse($this->dashboard->wasNeverEdited([]));
        $this->assertFalse(
            $this->dashboard->wasNeverEdited(['lastActivity' => date('Y-m-d H:i:s')]),
            'sans date d\'installation on ne sait rien'
        );
        $this->assertFalse(
            $this->dashboard->wasNeverEdited(['firstActivity' => date('Y-m-d H:i:s', strtotime('-2 years'))]),
            'mesuré avant que la ferme compte les pages écrites'
        );
    }

    public function testAWikiWhoseSpamWasAllApprovedStillCarriesTheApprovals()
    {
        $page = $this->select(
            [$this->fiche('propre'), $this->fiche('sale')],
            [
                'propre' => $this->stats([
                    'spamPages' => 0,
                    SpamApprovals::KEY => json_encode(['Ressources' => 'abcd', 'LiensUtiles' => 'efgh']),
                ]),
                'sale' => $this->stats(['spamPages' => 2]),
            ]
        );

        $propre = $page['fiches'][0]['stats'];
        $this->assertFalse($propre['spammed'], 'il ne reste rien à nettoyer');
        $this->assertSame(2, $propre['approvedPages'], 'de quoi revenir sur la validation');
        $this->assertSame(0, $page['fiches'][1]['stats']['approvedPages']);
        $this->assertSame(1, $page['counts']['spammed']);
    }

    private function select(
        array $fiches,
        array $stats,
        array $onDisk = [],
        string $search = '',
        string $filter = '',
        string $sort = 'title',
        string $direction = 'asc',
        int $start = 0,
        int $length = 100
    ): array {
        return $this->dashboard->select(
            $fiches,
            $stats,
            ['current' => $this->current, 'onDisk' => $onDisk],
            compact('search', 'filter', 'sort', 'direction', 'start', 'length')
        );
    }

    private function fiche(string $folder, ?string $title = null, string $status = ''): array
    {
        return [
            'id_fiche' => 'Fiche' . ucfirst($folder),
            'bf_dossier-wiki' => $folder,
            'bf_titre' => $title ?? ucfirst($folder),
            'bf_referent' => 'Personne ' . $folder,
            'bf_mail' => $folder . '@exemple.org',
            'status' => $status,
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
            'firstActivity' => date('Y-m-d H:i:s', strtotime('-2 years')),
            'editedPages' => 30,
            'lastEdit' => date('Y-m-d H:i:s'),
        ], $override);
    }
}
