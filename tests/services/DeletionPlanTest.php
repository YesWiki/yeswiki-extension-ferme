<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Service\DeletionPlan;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** Which entries claim which folder, so a duplicate entry never takes its wiki along. */
class DeletionPlanTest extends FarmTestCase
{
    private DeletionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = new DeletionPlan();
        $this->plan->index([
            'louise' => ['tag' => 'louise', 'bf_dossier-wiki' => 'louise'],
            'fiche-une' => ['tag' => 'fiche-une', 'bf_dossier-wiki' => 'louise'],
            'mon-wiki' => ['tag' => 'mon-wiki', 'bf_dossier-wiki' => 'monwiki'],
            'sans-dossier' => ['tag' => 'sans-dossier', 'bf_dossier-wiki' => ''],
            'pas-un-tableau',
        ]);
    }

    public function testAFolderTwoEntriesClaimNamesTheOtherOne(): void
    {
        $this->assertSame(['fiche-une'], $this->plan->others('louise', 'louise'));
        $this->assertSame(['louise'], $this->plan->others('louise', 'fiche-une'));
    }

    public function testAFolderOneEntryClaimsIsClaimedByNobodyElse(): void
    {
        $this->assertSame([], $this->plan->others('monwiki', 'mon-wiki'));
        $this->assertSame([], $this->plan->others('jamais-vu', 'inconnue'));
    }

    public function testAnEntryAlreadyDeletedKeepsNothingAlive(): void
    {
        $this->plan->forget('louise');

        $this->assertSame([], $this->plan->others('louise', 'fiche-une'));
    }

    public function testAnEntryWithoutAFolderClaimsNothing(): void
    {
        $this->assertSame([], $this->plan->others('', 'sans-dossier'));
    }
}
