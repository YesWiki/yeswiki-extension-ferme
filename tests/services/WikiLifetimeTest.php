<?php

namespace YesWiki\Test\Ferme\Services;

use YesWiki\Content\Service\PageManager;
use YesWiki\Ferme\Service\WikiLifetime;
use YesWiki\Files\Service\Storage;
use YesWiki\Kernel\Service\UrlFormatter;
use YesWiki\Test\Ferme\FarmTestCase;

require_once __DIR__ . '/../FarmTestCase.php';

/** How long a farm wiki lives, kept on its entry. */
class WikiLifetimeTest extends FarmTestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = new \DateTimeImmutable('2026-09-23');
    }

    public function testAQuickTestEndsAfterTheConfiguredNumberOfDays(): void
    {
        $terms = $this->lifetime(['yeswiki-farm-lifetime-short' => 30])->start(WikiLifetime::SHORT, $this->today);

        $this->assertSame('short', $terms[WikiLifetime::KIND]);
        $this->assertSame('2026-09-23', $terms[WikiLifetime::RENEWED_AT]);
        $this->assertSame('2026-10-23', $terms[WikiLifetime::EXPIRES_AT]);
    }

    public function testAnExtendedTestLastsAYearByDefault(): void
    {
        $terms = $this->lifetime()->start(WikiLifetime::LONG, $this->today);

        $this->assertSame('2027-09-23', $terms[WikiLifetime::EXPIRES_AT]);
    }

    public function testAPermanentWikiHasNoDeadline(): void
    {
        $terms = $this->lifetime()->start(WikiLifetime::PERMANENT, $this->today);

        $this->assertArrayNotHasKey(WikiLifetime::EXPIRES_AT, $terms);
        $this->assertFalse($this->lifetime()->describe($this->fiche($terms), $this->today)['canRenew'] ?? true);
    }

    public function testOnlyAnAdminIsOfferedAPermanentWiki(): void
    {
        $this->assertNotContains(WikiLifetime::PERMANENT, $this->lifetime()->choices(false));
        $this->assertContains(WikiLifetime::PERMANENT, $this->lifetime()->choices(true));
    }

    public function testRenewingOpensAMonthBeforeTheDeadline(): void
    {
        $lifetime = $this->lifetime();

        $early = $this->state($lifetime, $this->fiche([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2026-11-30']), $this->today);
        $due = $this->state($lifetime, $this->fiche([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2026-10-20']), $this->today);

        $this->assertSame(68, $early['daysLeft']);
        $this->assertFalse($early['canRenew']);
        $this->assertTrue($due['canRenew']);
        $this->assertTrue($due['expiring']);
    }

    public function testRenewingEarlyKeepsTheDaysThatWereLeft(): void
    {
        $entry = $this->fiche([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2026-10-20', WikiLifetime::REMINDED => '30']);

        $renewed = $this->lifetime()->renewed($entry, $this->today);

        $this->assertSame('2027-10-20', $renewed[WikiLifetime::EXPIRES_AT]);
        $this->assertSame('2026-09-23', $renewed[WikiLifetime::RENEWED_AT]);
        $this->assertSame('', $renewed[WikiLifetime::REMINDED]);
    }

    public function testChangingTheKindStartsTheNewPeriodToday(): void
    {
        $entry = $this->fiche([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2027-06-01']);

        $renewed = $this->lifetime()->renewed($entry, $this->today, WikiLifetime::SHORT);

        $this->assertSame('2026-12-22', $renewed[WikiLifetime::EXPIRES_AT]);
    }

    public function testAnArchivedWikiShowsWhenItGoesForGood(): void
    {
        $state = $this->state(
            $this->lifetime(['yeswiki-farm-lifetime-grace' => 180]),
            $this->fiche([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2026-09-01', WikiLifetime::ARCHIVED_AT => '2026-09-01']),
            $this->today
        );

        $this->assertTrue($state['archived']);
        $this->assertSame('2027-02-28', $state['purgeAt']);
        $this->assertFalse($state['canRenew']);
    }

    public function testAnEntryWithoutALifetimeIsLeftAlone(): void
    {
        $this->assertNull($this->lifetime()->describe($this->fiche([]), $this->today));
    }

    public function testTheRenewLinkOnlyWorksForTheCurrentDeadline(): void
    {
        $lifetime = $this->lifetime();
        $entry = $this->fiche([WikiLifetime::KIND => 'long', WikiLifetime::EXPIRES_AT => '2026-10-20']);
        $token = $lifetime->token('fiche-alpha', '2026-10-20');

        $this->assertTrue($lifetime->tokenIsValid($entry, $token));
        $this->assertFalse($lifetime->tokenIsValid(array_merge($entry, [WikiLifetime::EXPIRES_AT => '2027-10-20']), $token));
        $this->assertFalse($lifetime->tokenIsValid(array_merge($entry, ['tag' => 'fiche-beta']), $token));
        $this->assertFalse($lifetime->tokenIsValid($entry, ''));
    }

    public function testRemindersReadFromAListOrAString(): void
    {
        $this->assertSame([60, 14, 1], $this->lifetime(['yeswiki-farm-lifetime-reminders' => '14, 60,1'])->reminders());
        $this->assertSame([30, 7], $this->lifetime(['yeswiki-farm-lifetime-reminders' => []])->reminders());
        $this->assertSame(60, $this->lifetime(['yeswiki-farm-lifetime-reminders' => [60, 7]])->renewWindow());
    }

    /**
     * @param array<string, string> $terms
     *
     * @return array<string, string>
     */
    private function fiche(array $terms): array
    {
        return array_merge(['tag' => 'fiche-alpha', 'bf_dossier-wiki' => 'alpha'], $terms);
    }

    /** @param array<string, mixed> $config */
    private function lifetime(array $config = []): WikiLifetime
    {
        $farm = $this->farm($config + [
            'yeswiki-farm-lifetime-short' => 90,
            'yeswiki-farm-lifetime-long' => 365,
            'yeswiki-farm-lifetime-grace' => 180,
            'yeswiki-farm-lifetime-reminders' => [30, 7],
        ]);

        return new WikiLifetime($farm, $this->createStub(PageManager::class), self::getWiki()->services->get(UrlFormatter::class), Storage::rootedAt($this->scratch . '/farm'));
    }

    /**
     * @param array<string, string> $entry
     *
     * @return array<string, mixed>
     */
    private function state(WikiLifetime $lifetime, array $entry, \DateTimeImmutable $today): array
    {
        $state = $lifetime->describe($entry, $today);
        $this->assertNotNull($state);

        return $state;
    }
}
