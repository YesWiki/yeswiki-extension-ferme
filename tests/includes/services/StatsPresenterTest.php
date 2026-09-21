<?php

namespace YesWiki\Test\Ferme\Service;

use PHPUnit\Framework\Attributes\CoversMethod;
use YesWiki\Ferme\Service\StatsPresenter;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

#[CoversMethod(StatsPresenter::class, 'sparkline')]
#[CoversMethod(StatsPresenter::class, 'size')]
#[CoversMethod(StatsPresenter::class, 'age')]
#[CoversMethod(StatsPresenter::class, 'calendar')]
class StatsPresenterTest extends YesWikiTestCase
{
    private StatsPresenter $presenter;

    protected function setUp(): void
    {
        self::getWiki();
        $this->presenter = new StatsPresenter();
    }

    public function testTheSparklineIsTwelveBarsScaledToTheBusiestMonth()
    {
        $svg = $this->presenter->sparkline([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 10, 20]);

        $this->assertSame(12, substr_count($svg, '<rect'));
        $this->assertStringContainsString('height="18"', $svg, 'the busiest month fills the height');
        $this->assertStringContainsString('height="9"', $svg, 'half as many edits is half as tall');
        $this->assertStringContainsString('fill="currentColor"', $svg, 'it follows the theme rather than fixing a colour');
    }

    public function testASilentMonthIsAFaintBaselineRatherThanNothing()
    {
        $svg = $this->presenter->sparkline(array_fill(0, 12, 0));

        $this->assertSame(12, substr_count($svg, '<rect'));
        $this->assertSame(12, substr_count($svg, 'opacity="0.25"'));
    }

    public function testTheNumbersTravelWithThePictureForWhoeverCannotSeeIt()
    {
        $svg = $this->presenter->sparkline([1, 2, 3, 0, 0, 0, 0, 0, 0, 0, 0, 44]);

        $this->assertStringContainsString('role="img"', $svg);
        $this->assertStringContainsString('<title>', $svg);
        $this->assertStringContainsString('50', $svg, 'the total is said');
        $this->assertStringContainsString('44', $svg, 'and so is the current month');
    }

    public function testTheCalendarCoversAYearUpToTodayAndNoFurther()
    {
        $svg = $this->presenter->calendar([date('Y-m-d') => 4]);

        $cells = substr_count($svg, '<rect');
        $this->assertGreaterThan(360, $cells);
        $this->assertLessThanOrEqual(371, $cells);
        $this->assertStringNotContainsString(date('Y-m-d', strtotime('+1 day')), $svg, 'tomorrow has no cell');
        $this->assertStringContainsString(date('Y-m-d') . ': 4', $svg, 'each day says its count');
    }

    public function testACalendarDayWithoutAnEditIsFaintAndABusyOneIsSolid()
    {
        $svg = $this->presenter->calendar([
            date('Y-m-d') => 40,
            date('Y-m-d', strtotime('-1 day')) => 1,
        ]);

        $this->assertStringContainsString('opacity="0.12"', $svg, 'a silent day');
        $this->assertStringContainsString('opacity="1"', $svg, 'the busiest day');
        $this->assertStringContainsString('41', $svg, 'the total is said for whoever cannot see it');
    }

    public function testSizesAreReadableAndKeepOneDecimalWhereItMatters()
    {
        $this->assertSame('0 o', $this->presenter->size(0));
        $this->assertSame('512 o', $this->presenter->size(512));
        $this->assertSame('1,0 Ko', $this->presenter->size(1024));
        $this->assertSame('1,5 Go', $this->presenter->size(1610612736));
        $this->assertSame('0 o', $this->presenter->size(-3), 'a negative size is not a size');
    }

    public function testAnAgeIsRoughAndNeverAnEmptyString()
    {
        $this->assertSame('', $this->presenter->age(null));
        $this->assertSame(_t('FERME_AGE_TODAY'), $this->presenter->age(date('Y-m-d H:i:s')));
        $this->assertStringContainsString('3', $this->presenter->age(date('Y-m-d H:i:s', strtotime('-3 days'))));
        $this->assertStringContainsString('2', $this->presenter->age(date('Y-m-d H:i:s', strtotime('-70 days'))));
    }

    public function testPastThreeMonthsTheDateItselfIsMoreUseThanTheAge()
    {
        foreach (['-4 months', '-13 months', '-3 years'] as $when) {
            $moment = date('Y-m-d H:i:s', strtotime($when));
            $this->assertSame(
                date(_t('FERME_DATE_FORMAT'), strtotime($when)),
                $this->presenter->age($moment),
                'pour ' . $when
            );
        }
    }
}
