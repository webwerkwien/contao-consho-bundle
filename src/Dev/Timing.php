<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Dev;

/**
 * Median and worst run (plan B-9, decision 3).
 *
 * Not the mean: it hides the outlier, and the outlier is what a visitor
 * notices.
 */
final class Timing
{
    /**
     * @param list<float> $milliseconds
     *
     * @return array{median: float, max: float}
     */
    public static function summarize(array $milliseconds): array
    {
        if ([] === $milliseconds) {
            return ['median' => 0.0, 'max' => 0.0];
        }

        sort($milliseconds);
        $count = \count($milliseconds);
        $middle = intdiv($count, 2);

        $median = 0 === $count % 2
            ? ($milliseconds[$middle - 1] + $milliseconds[$middle]) / 2
            : $milliseconds[$middle];

        return ['median' => $median, 'max' => $milliseconds[$count - 1]];
    }
}
