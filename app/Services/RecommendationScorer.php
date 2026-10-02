<?php

namespace App\Services;

class RecommendationScorer
{
    /**
     * Same rule as the current platform: each answer is divided by 5,
     * the fractions are added, then multiplied by 10 and rounded.
     */
    public function score(array $ratings): int
    {
        $total = 0;
        foreach ($ratings as $rating) {
            $value = (int) $rating;
            if ($value < 1 || $value > 5) {
                continue;
            }
            $total += $value / 5;
        }

        return (int) round($total * 10);
    }
}
