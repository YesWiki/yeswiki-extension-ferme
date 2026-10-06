<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Ferme\Exception\WikiStatsException;
use YesWiki\Ferme\Service\InstanceConsole;
use YesWiki\Ferme\Service\StatsRefresher;
use YesWiki\Ferme\Service\WikiCreator;
use YesWiki\Ferme\Service\WikiRemover;
use YesWiki\Ferme\Service\WikiStats;
use YesWiki\Ferme\Service\WikiStatsStore;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** What a wiki of the farm holds, read from its own database and folders. */
class WikiStatsTest extends FarmTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->farm(['yeswiki-farm-admin-name' => 'FarmTestAdmin']);
        $this->dir = $this->service(WikiCreator::class)->createFromEntry($this->entry('measured'), 'bf_dossier-wiki');
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            $this->service(WikiStatsStore::class)->forget('measured');
            if (is_dir($this->dir)) {
                $this->service(WikiRemover::class)->destroy('measured', null);
            }
        }
        parent::tearDown();
    }

    public function testAFreshWikiIsCountedFromItsRowsAndItsFolders(): void
    {
        $stats = $this->service(WikiStats::class)->compute('measured');

        $this->assertSame(1, $stats['users']);
        $this->assertGreaterThan(0, $stats['forms']);
        $this->assertSame(0, $stats['entries']);
        $this->assertGreaterThan(0, $stats['pages']);
        $this->assertSame('A farm test wiki', $stats['name']);
        $this->assertSame('yes', $stats['adminPresent'], 'the account the farm looks for is there');
        $this->assertSame(0, $stats['editedPages'], 'nothing written by hand since the install');
        $this->assertSame(0, $stats['files']);
        $this->assertSame(0, $stats['spamPages']);
    }

    public function testActivityHasTwelveMonthsOldestFirst(): void
    {
        $stats = $this->service(WikiStats::class)->fromDatabase('measured');

        $this->assertCount(12, $stats['activity']);
        $this->assertGreaterThan(0, end($stats['activity']), 'the install happened this month');
        $this->assertSame(0, $stats['activity'][0]);
        $this->assertArrayHasKey(date('Y-m-d'), $this->service(WikiStats::class)->daily('measured'));
    }

    public function testTheLastRevisionIsWhatTheProbeReports(): void
    {
        $stats = $this->service(WikiStats::class)->fromDatabase('measured');

        $this->assertGreaterThan(0, $stats['lastPageId']);
        $this->assertSame($stats['lastPageId'], $this->service(WikiStats::class)->probe('measured'));

        $this->service(InstanceConsole::class)->task($this->dir, 'append-to-page', ['tag' => 'NouvellePage', 'content' => 'Du nouveau']);

        $this->assertGreaterThan($stats['lastPageId'], $this->service(WikiStats::class)->probe('measured'));
    }

    public function testAnUploadMovesTheDiskProbeAndIsWeighed(): void
    {
        mkdir($this->dir . '/private/files/sub', 0o755, true);
        touch($this->dir . '/private/files', time() - 100);
        touch($this->dir . '/files', time() - 100);
        $before = $this->service(WikiStats::class)->diskProbe('measured');
        file_put_contents($this->dir . '/private/files/sub/photo.webp', str_repeat('x', 2048));
        file_put_contents($this->dir . '/private/files/doc.pdf', str_repeat('x', 1024));

        $disk = $this->service(WikiStats::class)->fromDisk('measured');

        $this->assertSame(2, $disk['files'], 'subfolders are weighed too');
        $this->assertSame(3072, $disk['filesBytes']);
        $this->assertNotSame($before, $this->service(WikiStats::class)->diskProbe('measured'));
    }

    public function testASpamPageIsCountedUnlessSomebodyVouchedForIt(): void
    {
        $this->service(InstanceConsole::class)->task($this->dir, 'append-to-page', ['tag' => 'CasinoBonus', 'content' => 'Best casino bonus, escort jackpot']);

        $this->assertSame(1, $this->service(WikiStats::class)->fromDatabase('measured')['spamPages']);
    }

    public function testAWikiThatCannotBeReadFailsWithAReasonRatherThanZeros(): void
    {
        rename($this->dir . '/private/yeswiki.db', $this->dir . '/private/elsewhere.db');
        file_put_contents($this->dir . '/private/yeswiki.db', 'not a database');

        try {
            $this->service(WikiStats::class)->fromDatabase('measured');
            $this->fail('an unreadable database is a failure');
        } catch (WikiStatsException $failed) {
            $this->assertSame('measured', $failed->getFolder());
            $this->assertNotSame('', $failed->getReason());
        } finally {
            rename($this->dir . '/private/elsewhere.db', $this->dir . '/private/yeswiki.db');
        }
        $this->assertNull($this->service(WikiStats::class)->probe('nowhere'));
    }

    public function testAMissingWikiOrConfigurationIsAFailure(): void
    {
        $this->expectException(WikiStatsException::class);
        $this->service(WikiStats::class)->fromDisk('nowhere');
    }

    public function testTheRefresherOnlyCountsAgainWhatMoved(): void
    {
        $refresher = $this->service(StatsRefresher::class);

        $first = $refresher->refresh('measured', ['force' => true]);
        $this->assertTrue($first['counted']);
        $this->assertTrue($first['walked']);
        $refresher->close();

        $second = $refresher->refresh('measured');
        $this->assertFalse($second['counted'], 'nothing written, nothing counted');
        $this->assertFalse($second['walked']);
        $refresher->close();

        $stored = $this->service(WikiStatsStore::class)->read('measured');
        $this->assertSame(1, $stored['users'] ?? null);
        $this->assertSame(WikiStatsStore::STATUS_OK, $stored['status'] ?? null);
    }
}
