<?php

namespace Vistik\LaravelCodeAnalytics\RiskScoring;

use InvalidArgumentException;

class RiskScore
{
    /** @var list<array{name: string, score: int, maxScore: int}> */
    public readonly array $factors;

    /**
     * @param  int  $score  Normalized risk score (0–100)
     * @param  list<array{name: string, score: int, maxScore: int}>  $factors
     */
    public function __construct(
        public readonly int $score,
        array $factors = [],
    ) {
        if ($score < 0 || $score > 100) {
            throw new InvalidArgumentException("Risk score must be between 0 and 100, got {$score}.");
        }

        $this->factors = $factors;
    }

    /**
     * @return array{score: int, factors: list<array{name: string, score: int, maxScore: int}>}
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'factors' => $this->factors,
        ];
    }
}
