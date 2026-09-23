<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Core\Service\PageManager;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversClass(WikiLifetime::class)]
class WikiLifetimeTest extends YesWikiTestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-09-23');
    }

    public function testAQuickTestEndsAfterTheConfiguredNumberOfDays()
    {
        $terms = $this->lifetime(['yeswiki-farm-lifetime-short' => 30])->start(WikiLifetime::SHORT, $this->today);

        $this->assertSame('short', $terms[WikiLifetime::KIND]);
        $this->assertSame('2026-09-23', $terms[WikiLifetime::RENEWED_AT]);
        $this->assertSame('2026-10-23', $terms[WikiLifetime::EXPIRES_AT]);
    }

    public function testAnExtendedTestLastsAYearByDefault()
    {
        $terms = $this->lifetime()->start(WikiLifetime::LONG, $this->today);

        $this->assertSame('2027-09-23', $terms[WikiLifetime::EXPIRES_AT]);
    }

    public function testAPermanentWikiHasNoDeadline()
    {
        $terms = $this->lifetime()->start(WikiLifetime::PERMANENT, $this->today);

        $this->assertArrayNotHasKey(WikiLifetime::EXPIRES_AT, $terms);
        $this->assertFalse($this->lifetime()->describe($this->entry($terms), $this->today)['canRenew']);
    }

    public function testOnlyAnAdminIsOfferedAPermanentWiki()
    {
        $this->assertNotContains(WikiLifetime::PERMANENT, $this->lifetime()->choices(false));
        $this->assertContains(WikiLifetime::PERMANENT, $this->lifetime()->choices(true));
    }

    public function testRenewingOpensAMonthBeforeTheDeadline()
    {
        $lifetime = $this->lifetime();

        $early = $lifetime->describe($this->entry([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2026-11-30']), $this->today);
        $due = $lifetime->describe($this->entry([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2026-10-20']), $this->today);

        $this->assertSame(68, $early['daysLeft']);
        $this->assertFalse($early['canRenew']);
        $this->assertTrue($due['canRenew']);
        $this->assertTrue($due['expiring']);
    }

    public function testRenewingEarlyKeepsTheDaysThatWereLeft()
    {
        $entry = $this->entry([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2026-10-20', WikiLifetime::REMINDED => '30']);

        $renewed = $this->lifetime()->renewed($entry, $this->today);

        $this->assertSame('2027-10-20', $renewed[WikiLifetime::EXPIRES_AT]);
        $this->assertSame('2026-09-23', $renewed[WikiLifetime::RENEWED_AT]);
        $this->assertSame('', $renewed[WikiLifetime::REMINDED], 'the reminders start over');
    }

    public function testChangingTheKindStartsTheNewPeriodToday()
    {
        $entry = $this->entry([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2027-06-01']);

        $renewed = $this->lifetime()->renewed($entry, $this->today, WikiLifetime::SHORT);

        $this->assertSame('2026-12-22', $renewed[WikiLifetime::EXPIRES_AT]);
    }

    public function testAnArchivedWikiShowsWhenItGoesForGood()
    {
        $state = $this->lifetime(['yeswiki-farm-lifetime-grace' => 180])->describe(
            $this->entry([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2026-09-01', WikiLifetime::ARCHIVED_AT => '2026-09-01']),
            $this->today
        );

        $this->assertTrue($state['archived']);
        $this->assertSame('2027-02-28', $state['purgeAt']);
        $this->assertFalse($state['canRenew']);
    }

    public function testAnEntryWithoutALifetimeIsLeftAlone()
    {
        $this->assertNull($this->lifetime()->describe($this->entry([]), $this->today));
    }

    public function testTheRenewLinkOnlyWorksForTheCurrentDeadline()
    {
        $lifetime = $this->lifetime();
        $entry = $this->entry([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2026-10-20']);
        $token = $lifetime->token('FicheAlpha', '2026-10-20');

        $this->assertTrue($lifetime->tokenIsValid($entry, $token));
        $this->assertFalse($lifetime->tokenIsValid(array_merge($entry, [WikiLifetime::EXPIRES_AT => '2027-10-20']), $token), 'once renewed, the old link is dead');
        $this->assertFalse($lifetime->tokenIsValid(array_merge($entry, ['id_fiche' => 'FicheBeta']), $token));
        $this->assertFalse($lifetime->tokenIsValid($entry, ''));
    }

    public function testRemindersReadFromAListOrAString()
    {
        $this->assertSame([60, 14, 1], $this->lifetime(['yeswiki-farm-lifetime-reminders' => '14, 60,1'])->reminders());
        $this->assertSame([30, 7], $this->lifetime(['yeswiki-farm-lifetime-reminders' => []])->reminders());
        $this->assertSame(60, $this->lifetime(['yeswiki-farm-lifetime-reminders' => [60, 7]])->renewWindow());
    }

    private function entry(array $terms): array
    {
        return array_merge(['id_fiche' => 'FicheAlpha', 'bf_dossier-wiki' => 'alpha'], $terms);
    }

    private function lifetime(array $config = []): WikiLifetime
    {
        $wiki = self::getWiki();
        foreach (['yeswiki-farm-lifetime-short', 'yeswiki-farm-lifetime-long', 'yeswiki-farm-lifetime-grace', 'yeswiki-farm-lifetime-reminders'] as $key) {
            unset($wiki->config[$key]);
        }
        $wiki->config = array_merge($wiki->config, $config);

        return new WikiLifetime($wiki, $this->createStub(EntryManager::class), $this->createStub(PageManager::class));
    }
}
