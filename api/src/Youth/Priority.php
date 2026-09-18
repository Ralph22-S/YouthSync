<?php
declare(strict_types=1);

namespace YouthSync\Youth;

final class Priority
{
    /**
     * Same recommendation rules as the frontend evaluatePriority().
     *
     * @param array<string, mixed> $youth
     * @return array{level:string,score:int,reasons:list<string>}
     */
    public static function evaluate(array $youth): array
    {
        $score = 0;
        $reasons = [];
        $income = (float) ($youth['familyIncome'] ?? 0);
        $members = (int) ($youth['familyMembers'] ?? 0);
        $perCapita = $members > 0 ? $income / $members : $income;
        $studying = (bool) ($youth['studying'] ?? false);
        $skills = $youth['skills'] ?? [];

        if ($income > 0 && $income <= 12000) {
            $score += 3;
            $reasons[] = 'Low family income (₱' . number_format($income) . '/month)';
        } elseif ($income > 0 && $income <= 20000) {
            $score += 1;
            $reasons[] = 'Modest family income (₱' . number_format($income) . '/month)';
        }

        if ($members >= 6 && $perCapita > 0 && $perCapita < 3000) {
            $score += 2;
            $reasons[] = "Large household with low income per member ({$members} members)";
        }
        if (($youth['guardianEmployment'] ?? '') === 'Unemployed') {
            $score += 3;
            $reasons[] = 'Parent or guardian unemployed';
        }

        if (($youth['employment'] ?? '') === 'Unemployed' && !$studying) {
            $score += 3;
            $reasons[] = 'Out of school and unemployed';
        } elseif (($youth['employment'] ?? '') === 'Unemployed') {
            $score += 1;
            $reasons[] = 'Currently unemployed';
        }

        if (!$studying && ($youth['education'] ?? '') !== 'College') {
            $score += 2;
            $reasons[] = 'Not currently enrolled in school';
        }

        if (empty($youth['previousScholarship']) && empty($youth['previousAssistance'])) {
            $score += 1;
            $reasons[] = 'Has not yet received SK assistance';
        } else {
            $reasons[] = 'Previously received SK assistance';
        }

        if (!is_array($skills) || $skills === []) {
            $score += 1;
            $reasons[] = 'No recorded skills or training';
        }

        return [
            'level' => $score >= 6 ? 'High' : 'Medium',
            'score' => $score,
            'reasons' => array_slice($reasons, 0, 5),
        ];
    }
}
