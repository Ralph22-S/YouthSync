<?php
declare(strict_types=1);

namespace YouthSync\Org;

final class PlanLimits
{
    private const PLANS = [
        'free' => [
            'code' => 'free',
            'name' => 'Free',
            'youth' => 20,
            'accounts' => 1,
            'programs' => 1,
            'qr' => 1,
            'assistance' => 1,
            'csv_import' => false,
        ],
        'basic' => [
            'code' => 'basic',
            'name' => 'Basic',
            'youth' => 250,
            'accounts' => 3,
            'programs' => null,
            'qr' => 3,
            'assistance' => 20,
            'csv_import' => true,
        ],
        'premium' => [
            'code' => 'premium',
            'name' => 'Premium',
            'youth' => null,
            'accounts' => null,
            'programs' => null,
            'qr' => null,
            'assistance' => null,
            'csv_import' => true,
        ],
    ];

    private const EFFECTIVE_FREE_STATUSES = ['expired', 'cancelled', 'payment_failed'];

    /**
     * @param array<string, mixed> $organizationRow membership/org row from Tenant
     * @return array{code:string,name:string,youth:?int,accounts:?int,programs:?int,qr:?int,assistance:?int,csv_import:bool}
     */
    public static function effective(array $organizationRow): array
    {
        $stored = strtolower((string) ($organizationRow['plan'] ?? 'free'));
        $sub = strtolower((string) ($organizationRow['sub_status'] ?? ''));
        if (in_array($sub, self::EFFECTIVE_FREE_STATUSES, true)) {
            return self::PLANS['free'];
        }
        return self::PLANS[$stored] ?? self::PLANS['free'];
    }

    /**
     * Catalog of stored plans (not effective/expired overlay).
     *
     * @return list<array{code:string,name:string,youth:?int,accounts:?int,programs:?int,qr:?int,assistance:?int,csv_import:bool}>
     */
    public static function catalog(): array
    {
        return array_values(self::PLANS);
    }

    public static function remaining(?int $limit, int $used): ?int
    {
        if ($limit === null) {
            return null;
        }
        return max(0, $limit - $used);
    }

    public static function storedPlanCode(array $organizationRow): string
    {
        $stored = strtolower((string) ($organizationRow['plan'] ?? 'free'));
        return isset(self::PLANS[$stored]) ? $stored : 'free';
    }

    public static function youthLimitMessage(array $plan): string
    {
        $n = number_format((int) $plan['youth']);
        return "You have reached the {$plan['name']} plan limit of {$n} youth records. "
            . 'Your existing data is untouched — upgrade your plan to add more.';
    }

    public static function accountsLimitMessage(array $plan): string
    {
        $n = number_format((int) $plan['accounts']);
        return "You have reached the {$plan['name']} plan limit of {$n} SK Official accounts. "
            . 'Your existing data is untouched — upgrade your plan to add more.';
    }

    public static function csvNotIncludedMessage(array $plan): string
    {
        return "CSV import is not included in the {$plan['name']} plan";
    }

    public static function csvOverflowMessage(int $count, int $remaining, array $plan): string
    {
        return "This import contains {$count} records but only {$remaining} slots remain on the {$plan['name']} plan.";
    }

    public static function programsLimitMessage(array $plan): string
    {
        $n = number_format((int) $plan['programs']);
        return "You have reached the {$plan['name']} plan limit of {$n} active programs and events. "
            . 'Your existing data is untouched — upgrade your plan to add more.';
    }

    public static function qrLimitMessage(array $plan): string
    {
        $n = (int) $plan['qr'];
        $word = $n === 1 ? 'scan' : 'scans';
        return "QR scan limit reached — the {$plan['name']} plan includes {$n} {$word}. "
            . 'Upgrade your plan for more QR scans.';
    }

    public static function assistanceLimitMessage(array $plan): string
    {
        $n = number_format((int) $plan['assistance']);
        return "You have reached the {$plan['name']} plan limit of {$n} active assistance programs. "
            . 'Your existing data is untouched — upgrade your plan to add more.';
    }
}
