<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Service\PageManager;
use YesWiki\Ferme\Service\FarmConfig;
use YesWiki\Ferme\Service\FarmMailer;
use YesWiki\Ferme\Service\LifetimeSweeper;
use YesWiki\Ferme\Service\WikiArchiver;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Ferme\Service\WikiRemover;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversClass(LifetimeSweeper::class)]
class LifetimeSweeperTest extends YesWikiTestCase
{
    private \DateTimeImmutable $today;
    private array $mails = [];
    private array $saved = [];
    private array $removed = [];

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-09-23');
        $this->mails = [];
        $this->saved = [];
        $this->removed = [];
    }

    public function testAReminderGoesOutOnceWhenTheDeadlineComesInReach()
    {
        $entry = $this->entry('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-10-20']);

        $report = $this->sweeper([$entry])->sweep($this->today);

        $this->assertSame(['alpha'], $report['reminded']);
        $this->assertCount(1, $this->mails);
        $this->assertStringContainsString('api/ferme/lifetime/renew', $this->mails[0]['extra']['renewUrl']);
        $this->assertSame('20/10/2026', $this->mails[0]['extra']['expires']);
        $this->assertSame('30', $this->saved['FicheAlpha']['ferme_reminded']);

        $again = $this->sweeper([array_merge($entry, ['ferme_reminded' => '30'])])->sweep($this->today);
        $this->assertSame([], $again['reminded'], 'the same reminder is not sent twice');
    }

    public function testAMissedSweepSendsOneMailForEveryReminderItCrossed()
    {
        $entry = $this->entry('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-09-26']);

        $this->sweeper([$entry])->sweep($this->today);

        $this->assertCount(1, $this->mails);
        $this->assertSame('30,7', $this->saved['FicheAlpha']['ferme_reminded']);
    }

    public function testAWikiFarFromItsDeadlineIsLeftAlone()
    {
        $report = $this->sweeper([$this->entry('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2027-06-01'])])->sweep($this->today);

        $this->assertSame([[], [], [], []], [$report['reminded'], $report['deleted'], $report['archived'], $report['purged']]);
        $this->assertSame([], $this->mails);
    }

    public function testAQuickTestIsDeletedWithoutBackupOnItsDeadline()
    {
        $report = $this->sweeper([$this->entry('rapide', ['ferme_lifetime' => 'short', 'ferme_expires_at' => '2026-09-23'])])->sweep($this->today);

        $this->assertSame(['rapide'], $report['deleted']);
        $this->assertSame([['expire', 'FicheRapide', 'rapide']], $this->removed);
    }

    public function testADryRunSaysWhatWouldHappenAndDoesNothing()
    {
        $report = $this->sweeper([
            $this->entry('rapide', ['ferme_lifetime' => 'short', 'ferme_expires_at' => '2026-09-01']),
            $this->entry('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-10-01']),
        ])->sweep($this->today, true);

        $this->assertSame(['rapide'], $report['deleted']);
        $this->assertSame(['alpha'], $report['reminded']);
        $this->assertSame([], $this->removed);
        $this->assertSame([], $this->mails);
        $this->assertSame([], $this->saved);
    }

    public function testAnArchivedWikiIsPurgedOnceTheGraceIsOver()
    {
        $kept = $this->entry('ancien', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-06-01', 'ferme_archived_at' => '2026-06-01']);
        $gone = $this->entry('vieux', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-01-01', 'ferme_archived_at' => '2026-01-01']);

        $report = $this->sweeper([$kept, $gone])->sweep($this->today);

        $this->assertSame(['vieux'], $report['purged']);
        $this->assertSame([['forget', 'FicheVieux']], $this->removed);
    }

    public function testPermanentWikisAndWikisWithoutALifetimeAreNeverTouched()
    {
        $report = $this->sweeper([
            $this->entry('toujours', ['ferme_lifetime' => 'permanent']),
            $this->entry('ancien', []),
        ])->sweep($this->today);

        $this->assertSame([], array_merge(...array_values($report)));
    }

    public function testTheArchiveMailTakesItsTextFromTheConfiguration()
    {
        $entry = array_merge($this->entry('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-09-01']), ['bf_dossier-wiki' => '']);
        $sweeper = $this->sweeper([$entry]);
        $wiki = self::getWiki();
        $wiki->config['yeswiki-farm-lifetime-archived-subject'] = 'Fermé : {title}';
        $wiki->config['yeswiki-farm-lifetime-archived-body'] = 'Bonjour,\\nà bientôt';

        $report = $sweeper->sweep($this->today);
        unset($wiki->config['yeswiki-farm-lifetime-archived-subject'], $wiki->config['yeswiki-farm-lifetime-archived-body']);

        $this->assertSame(['FicheAlpha'], $report['archived']);
        $this->assertSame('Fermé : {title}', $this->mails[0]['subject']);
        $this->assertSame("Bonjour,\nà bientôt", $this->mails[0]['body']);
        $this->assertSame('2026-09-23', $this->saved['FicheAlpha']['ferme_archived_at']);
    }

    public function testASweepWaitsForNobodyAndDoesNothingWhileAnotherOneRuns()
    {
        $sweeper = $this->sweeper([$this->entry('alpha', ['ferme_lifetime' => 'long', 'ferme_expires_at' => '2026-10-01'])]);
        @mkdir(dirname(LifetimeSweeper::LAST_RUN), 0777, true);
        $other = fopen(LifetimeSweeper::LAST_RUN, 'c');
        flock($other, LOCK_EX);

        try {
            $this->assertNull($sweeper->sweepNow($this->today));
            $this->assertSame([], $this->mails);
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }

        $this->assertSame(['alpha'], $sweeper->sweepNow($this->today)['reminded']);
        $this->assertCount(1, $this->mails);
    }

    public function testALimitCapsTheDeletionsOfOneSweep()
    {
        $report = $this->sweeper([
            $this->entry('un', ['ferme_lifetime' => 'short', 'ferme_expires_at' => '2026-09-01']),
            $this->entry('deux', ['ferme_lifetime' => 'short', 'ferme_expires_at' => '2026-09-01']),
        ])->sweep($this->today, false, 1);

        $this->assertSame(['un'], $report['deleted']);
    }

    private function entry(string $folder, array $terms): array
    {
        return array_merge([
            'id_fiche' => 'Fiche' . ucfirst($folder),
            'id_typeannonce' => '1100',
            'bf_titre' => 'Wiki ' . $folder,
            'bf_dossier-wiki' => $folder,
            'bf_mail' => $folder . '@exemple.org',
        ], $terms);
    }

    private function sweeper(array $entries): LifetimeSweeper
    {
        $wiki = self::getWiki();
        $wiki->config['bazar_farm_id'] = '1100';
        $wiki->config['yeswiki-farm-lifetime'] = true;
        foreach (['yeswiki-farm-lifetime-short', 'yeswiki-farm-lifetime-long', 'yeswiki-farm-lifetime-grace', 'yeswiki-farm-lifetime-reminders'] as $key) {
            unset($wiki->config[$key]);
        }

        $byId = array_column($entries, null, 'id_fiche');
        $entryManager = $this->createStub(EntryManager::class);
        $entryManager->method('search')->willReturn($entries);
        $entryManager->method('isEntry')->willReturnCallback(function ($id) use ($byId) {
            return isset($byId[$id]);
        });
        $entryManager->method('getOne')->willReturnCallback(function ($id) use ($byId) {
            return $byId[$id] ?? null;
        });

        $pageManager = $this->createStub(PageManager::class);
        $pageManager->method('save')->willReturnCallback(function ($tag, $body) {
            $this->saved[$tag] = json_decode($body, true);

            return 0;
        });

        $mailer = $this->createStub(FarmMailer::class);
        $mailer->method('send')->willReturnCallback(function (array $entry, string $subject, string $body, array $extra = []) {
            $this->mails[] = ['to' => $entry['bf_mail'], 'subject' => $subject, 'body' => $body, 'extra' => $extra];

            return $entry['bf_mail'];
        });

        $remover = $this->createStub(WikiRemover::class);
        $remover->method('expire')->willReturnCallback(function ($id, $folder) {
            $this->removed[] = ['expire', $id, $folder];
        });
        $remover->method('forgetEntry')->willReturnCallback(function ($id) {
            $this->removed[] = ['forget', $id];
        });

        $config = $this->createStub(FarmConfig::class);
        $config->method('ensureBackupDir')->willReturn(sys_get_temp_dir());

        return new LifetimeSweeper(
            $wiki,
            $entryManager,
            new WikiLifetime($wiki, $entryManager, $pageManager),
            $mailer,
            $remover,
            $this->createStub(WikiArchiver::class),
            $config
        );
    }
}
