<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\DeletionPlan;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(DeletionPlan::class, 'index')]
#[CoversMethod(DeletionPlan::class, 'others')]
#[CoversMethod(DeletionPlan::class, 'forget')]
class DeletionPlanTest extends YesWikiTestCase
{
    private DeletionPlan $plan;

    protected function setUp(): void
    {
        self::getWiki();
        $this->plan = new DeletionPlan();
        $this->plan->index([
            'LouisE' => ['id_fiche' => 'LouisE', 'bf_dossier-wiki' => 'louise'],
            'FicheUne' => ['id_fiche' => 'FicheUne', 'bf_dossier-wiki' => 'louise'],
            'MonWiki' => ['id_fiche' => 'MonWiki', 'bf_dossier-wiki' => 'monwiki'],
            'SansDossier' => ['id_fiche' => 'SansDossier', 'bf_dossier-wiki' => ''],
        ]);
    }

    public function testAFolderTwoEntriesClaimNamesTheOtherOne()
    {
        $this->assertSame(['FicheUne'], $this->plan->others('louise', 'LouisE'));
        $this->assertSame(['LouisE'], $this->plan->others('louise', 'FicheUne'));
    }

    public function testAFolderOneEntryClaimsIsClaimedByNobodyElse()
    {
        $this->assertSame([], $this->plan->others('monwiki', 'MonWiki'));
        $this->assertSame([], $this->plan->others('jamais-vu', 'Inconnue'));
    }

    public function testAnEntryAlreadyDeletedKeepsNothingAlive()
    {
        $this->plan->forget('LouisE');

        $this->assertSame(
            [],
            $this->plan->others('louise', 'FicheUne'),
            'the second of two entries on one folder must not be spared by the first, already gone'
        );
    }

    public function testAnEntryWithoutAFolderClaimsNothing()
    {
        $this->assertSame([], $this->plan->others('', 'SansDossier'));
    }
}
