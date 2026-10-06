<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Content\Service\EntryManager;
use YesWiki\Content\Service\PageManager;
use YesWiki\Ferme\Service\FarmMailer;
use YesWiki\Ferme\Service\LifetimeSweeper;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Ferme\Service\WikiRemover;
use YesWiki\Files\Service\Storage;
use YesWiki\Kernel\Service\UrlFormatter;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** The walk over the farm's wikis with a lifetime: reminders, deletions, archives and purges. */
class LifetimeSweeperTest extends FarmTestCase
{
    private \DateTimeImmutable $today;

    /** @var list<array<string, mixed>> */
    private array $mails = [];

    /** @var array<string, array<string, mixed>> */
    private array $saved = [];

    /** @var list<list<string>> */
    private array $removed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = new \DateTimeImmutable('2026-09-23');
    }

    public function testAReminderGoesOutOnceWhenTheDeadlineComesInReach(): void
    {
        $entry = $this->fiche('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-10-20']);

        $report = $this->sweeper([$entry])->sweep($this->today);

        $this->assertSame(['alpha'], $report['reminded']);
        $this->assertCount(1, $this->mails);
        $this->assertStringContainsString('api/ferme/lifetime/renew', (string)$this->mails[0]['extra']['renewUrl']);
        $this->assertSame('20/10/2026', $this->mails[0]['extra']['expires']);
        $this->assertSame('30', $this->saved['fiche-alpha']['ferme_reminded']);

        $again = $this->sweeper([array_merge($entry, ['ferme_reminded' => '30'])])->sweep($this->today);
        $this->assertSame([], $again['reminded']);
    }

    public function testAMissedSweepSendsOneMailForEveryReminderItCrossed(): void
    {
        $this->sweeper([$this->fiche('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-09-26'])])->sweep($this->today);

        $this->assertCount(1, $this->mails);
        $this->assertSame('30,7', $this->saved['fiche-alpha']['ferme_reminded']);
    }

    public function testAWikiFarFromItsDeadlineIsLeftAlone(): void
    {
        $report = $this->sweeper([$this->fiche('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2027-06-01'])])->sweep($this->today);

        $this->assertSame([[], [], [], []], [$report['reminded'], $report['deleted'], $report['archived'], $report['purged']]);
        $this->assertSame([], $this->mails);
    }

    public function testAQuickTestIsDeletedWithoutBackupOnItsDeadline(): void
    {
        $report = $this->sweeper([$this->fiche('rapide', ['ferme_lifetime' => 'short', 'ferme_expires_at' => '2026-09-23'])])->sweep($this->today);

        $this->assertSame(['rapide'], $report['deleted']);
        $this->assertSame([['expire', 'fiche-rapide', 'rapide']], $this->removed);
    }

    public function testADryRunSaysWhatWouldHappenAndDoesNothing(): void
    {
        $report = $this->sweeper([
            $this->fiche('rapide', ['ferme_lifetime' => 'short', 'ferme_expires_at' => '2026-09-01']),
            $this->fiche('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-10-01']),
        ])->sweep($this->today, true);

        $this->assertSame(['rapide'], $report['deleted']);
        $this->assertSame(['alpha'], $report['reminded']);
        $this->assertSame([], $this->removed);
        $this->assertSame([], $this->mails);
        $this->assertSame([], $this->saved);
    }

    public function testAnArchivedWikiIsPurgedOnceTheGraceIsOver(): void
    {
        $archive = 'private/backups/ferme/expired/vieux.zip';
        mkdir($this->scratch . '/farm/private/backups/ferme/expired', 0o755, true);
        file_put_contents($this->scratch . '/farm/' . $archive, 'zip');
        $kept = $this->fiche('ancien', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-06-01', 'ferme_archived_at' => '2026-06-01']);
        $gone = $this->fiche('vieux', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-01-01', 'ferme_archived_at' => '2026-01-01', 'ferme_archive' => $archive]);

        $report = $this->sweeper([$kept, $gone])->sweep($this->today);

        $this->assertSame(['vieux'], $report['purged']);
        $this->assertSame([['forget', 'fiche-vieux']], $this->removed);
        $this->assertFileDoesNotExist($this->scratch . '/farm/' . $archive);
    }

    public function testPermanentWikisAndWikisWithoutALifetimeAreNeverTouched(): void
    {
        $report = $this->sweeper([
            $this->fiche('toujours', ['ferme_lifetime' => 'permanent']),
            $this->fiche('ancien', []),
        ])->sweep($this->today);

        $this->assertSame([], array_merge(...array_values($report)));
    }

    public function testTheArchiveMailTakesItsTextFromTheConfiguration(): void
    {
        $entry = array_merge($this->fiche('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-09-01']), ['bf_dossier-wiki' => '']);

        $report = $this->sweeper([$entry], [
            'yeswiki-farm-lifetime-archived-subject' => 'Fermé : {title}',
            'yeswiki-farm-lifetime-archived-body' => 'Bonjour,\\nà bientôt',
        ])->sweep($this->today);

        $this->assertSame(['fiche-alpha'], $report['archived']);
        $this->assertSame('Fermé : {title}', $this->mails[0]['subject']);
        $this->assertSame("Bonjour,\nà bientôt", $this->mails[0]['body']);
        $this->assertSame('2026-09-23', $this->saved['fiche-alpha']['ferme_archived_at']);
    }

    public function testASweepDoesNothingWhileAnotherOneRuns(): void
    {
        $sweeper = $this->sweeper([$this->fiche('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-10-01'])]);
        $other = $this->scratchLocks();
        $handle = $other->acquire(LifetimeSweeper::LOCK);
        $this->assertTrue($other->tryLock($handle));

        try {
            $this->assertNull($sweeper->sweepNow($this->today));
            $this->assertSame([], $this->mails);
        } finally {
            $other->release($handle);
        }

        $this->assertSame(['alpha'], $sweeper->sweepNow($this->today)['reminded'] ?? null);
        $this->assertCount(1, $this->mails);
        $this->assertNull($sweeper->sweepIfDue(), 'a sweep that just ran waits twelve hours');
    }

    public function testALimitCapsTheDeletionsOfOneSweep(): void
    {
        $report = $this->sweeper([
            $this->fiche('un', ['ferme_lifetime' => 'short', 'ferme_expires_at' => '2026-09-01']),
            $this->fiche('deux', ['ferme_lifetime' => 'short', 'ferme_expires_at' => '2026-09-01']),
        ])->sweep($this->today, false, 1);

        $this->assertSame(['un'], $report['deleted']);
    }

    /**
     * @param array<string, string> $terms
     *
     * @return array<string, string>
     */
    private function fiche(string $folder, array $terms): array
    {
        return array_merge([
            'tag' => 'fiche-' . $folder,
            'form_id' => '1100',
            'bf_titre' => 'Wiki ' . $folder,
            'bf_dossier-wiki' => $folder,
            'bf_mail' => $folder . '@exemple.org',
        ], $terms);
    }

    /**
     * @param list<array<string, string>> $entries
     * @param array<string, mixed>        $settings
     */
    private function sweeper(array $entries, array $settings = []): LifetimeSweeper
    {
        $farm = $this->farm($settings + [
            'bazar_farm_id' => '1100',
            'yeswiki-farm-lifetime' => true,
            'yeswiki-farm-lifetime-short' => 90,
            'yeswiki-farm-lifetime-long' => 365,
            'yeswiki-farm-lifetime-grace' => 180,
            'yeswiki-farm-lifetime-reminders' => [30, 7],
            'yeswiki-farm-lifetime-archived-subject' => '',
            'yeswiki-farm-lifetime-archived-body' => '',
            'yeswiki-farm-lifetime-mail-subject' => '',
            'yeswiki-farm-lifetime-mail-body' => '',
            'yeswiki-farm-donate-url' => '',
        ]);
        $byTag = array_column($entries, null, 'tag');

        $entryManager = $this->createStub(EntryManager::class);
        $entryManager->method('search')->willReturn($entries);

        $pageManager = $this->createStub(PageManager::class);
        $pageManager->method('getOne')->willReturnCallback(static fn ($tag): ?array => isset($byTag[$tag]) ? ['type' => 'entry', 'body' => $byTag[$tag]] : null);
        $pageManager->method('save')->willReturnCallback(function ($tag, array $body): int {
            $this->saved[(string)$tag] = $body;

            return 0;
        });

        $mailer = $this->createStub(FarmMailer::class);
        $mailer->method('send')->willReturnCallback(function (array $entry, string $subject, string $body, array $extra = []): string {
            $this->mails[] = ['to' => $entry['bf_mail'], 'subject' => $subject, 'body' => $body, 'extra' => $extra];

            return (string)$entry['bf_mail'];
        });

        $remover = $this->createStub(WikiRemover::class);
        $remover->method('expire')->willReturnCallback(function (string $tag, string $folder): void {
            $this->removed[] = ['expire', $tag, $folder];
        });
        $remover->method('forgetEntry')->willReturnCallback(function (string $tag): void {
            $this->removed[] = ['forget', $tag];
        });

        $storage = Storage::rootedAt($this->scratch . '/farm');

        return new LifetimeSweeper(
            $farm,
            $entryManager,
            new WikiLifetime($farm, $pageManager, self::getWiki()->services->get(UrlFormatter::class), $storage),
            $mailer,
            $remover,
            $this->scratchLocks(),
            $storage
        );
    }
}
