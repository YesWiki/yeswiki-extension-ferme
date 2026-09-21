<?php

namespace YesWiki\Ferme\Service;

/**
 * Turns stored numbers into what a row shows: a sparkline drawn server side, a
 * size a human reads, and an age rather than a timestamp.
 */
class StatsPresenter
{
    private const WIDTH = 4;
    private const GAP = 1;
    private const HEIGHT = 18;

    /**
     * Twelve bars of inline SVG in the current text colour, so it follows the theme
     * and prints. The numbers travel with it for whoever cannot see it.
     *
     * @param array<int,int> $months oldest first
     */
    public function sparkline(array $months): string
    {
        $months = array_map('intval', array_slice($months, -WikiStats::MONTHS));
        if (empty($months)) {
            return '';
        }

        $peak = max($months);
        $width = count($months) * (self::WIDTH + self::GAP) - self::GAP;
        $bars = '';

        foreach (array_values($months) as $index => $edits) {
            $height = $peak > 0 ? max(1, (int)round($edits / $peak * self::HEIGHT)) : 1;
            $bars .= '<rect x="' . $index * (self::WIDTH + self::GAP) . '" y="' . (self::HEIGHT - $height) . '"'
                . ' width="' . self::WIDTH . '" height="' . $height . '"'
                . ($edits > 0 ? '' : ' opacity="0.25"') . '/>';
        }

        $label = _t('FERME_STATS_SPARKLINE_LABEL', [
            'total' => array_sum($months),
            'last' => end($months),
        ]);

        return '<svg class="ferme-sparkline" role="img" aria-label="' . htmlspecialchars($label) . '"'
            . ' width="' . $width . '" height="' . self::HEIGHT . '" viewBox="0 0 ' . $width . ' ' . self::HEIGHT . '"'
            . ' fill="currentColor"><title>' . htmlspecialchars($label) . '</title>' . $bars . '</svg>';
    }

    /**
     * A year of edits as a grid of weeks, the way a calendar of contributions
     * reads: one column a week, one cell a day, darker where there was more.
     *
     * @param array<string,int> $edits keyed by Y-m-d
     */
    public function calendar(array $edits, int $weeks = 53): string
    {
        $cell = 10;
        $gap = 2;
        $peak = empty($edits) ? 0 : max($edits);
        $day = new \DateTimeImmutable('monday this week');
        $day = $day->modify('-' . ($weeks - 1) . ' weeks');
        $squares = '';
        $total = 0;

        for ($week = 0; $week < $weeks; $week++) {
            for ($weekday = 0; $weekday < 7; $weekday++) {
                $date = $day->modify('+' . ($week * 7 + $weekday) . ' days');
                if ($date > new \DateTimeImmutable('today')) {
                    continue;
                }
                $count = $edits[$date->format('Y-m-d')] ?? 0;
                $total += $count;
                $squares .= '<rect x="' . $week * ($cell + $gap) . '" y="' . $weekday * ($cell + $gap) . '"'
                    . ' width="' . $cell . '" height="' . $cell . '" rx="2"'
                    . ' opacity="' . $this->shade($count, $peak) . '"'
                    . '><title>' . $date->format('Y-m-d') . ': ' . $count . '</title></rect>';
            }
        }

        $width = $weeks * ($cell + $gap) - $gap;
        $height = 7 * ($cell + $gap) - $gap;
        $label = _t('FERME_STATS_CALENDAR_LABEL', ['total' => $total]);

        return '<svg class="ferme-calendar" role="img" aria-label="' . htmlspecialchars($label) . '"'
            . ' width="100%" viewBox="0 0 ' . $width . ' ' . $height . '" fill="currentColor">'
            . '<title>' . htmlspecialchars($label) . '</title>' . $squares . '</svg>';
    }

    private function shade(int $count, int $peak): string
    {
        if ($count === 0) {
            return '0.12';
        }
        $steps = [0.35, 0.55, 0.75, 1];
        $index = $peak <= 1 ? 3 : (int)min(3, floor(($count - 1) / max(1, $peak / 4)));

        return (string)$steps[$index];
    }

    public function size(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $unit = 0;
        $size = max(0, $bytes);

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string)(int)$size : number_format($size, $size < 10 ? 1 : 0, ',', ' ')) . ' ' . $units[$unit];
    }

    /**
     * How long ago, in the roughest unit that still says something.
     */
    public function age(?string $date): string
    {
        if (empty($date)) {
            return '';
        }

        $seconds = time() - (int)strtotime($date);
        if ($seconds < 86400) {
            return _t('FERME_AGE_TODAY');
        }

        $days = (int)floor($seconds / 86400);
        if ($days < 60) {
            return _t('FERME_AGE_DAYS', ['n' => $days]);
        }

        $months = (int)floor($days / 30);

        return $months < 24
            ? _t('FERME_AGE_MONTHS', ['n' => $months])
            : _t('FERME_AGE_YEARS', ['n' => (int)floor($days / 365)]);
    }
}
