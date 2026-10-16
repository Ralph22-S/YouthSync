<?php
declare(strict_types=1);

namespace YouthSync\Org;

use DateTimeImmutable;
use PDO;
use YouthSync\Auth\AuthService;
use YouthSync\Http\Json;

/**
 * The one authoritative plan catalog: limits, feature gates, status rules and
 * the display data (list prices, taglines, feature bullets) the SK and admin
 * screens show. There is no plans table and no payment gateway - prices are
 * reference list prices only; payments are recorded by the administrator.
 */
final class PlanLimits
{
    /** Resources with a numeric limit, in display order. */
    public const RESOURCES = ['youth', 'accounts', 'programs', 'assistance', 'qr'];

    public const STATUSES = ['free', 'trial', 'active', 'expired', 'cancelled', 'payment_failed'];

    public const STATUS_LABELS = [
        'free' => 'Free',
        'trial' => 'Trial',
        'active' => 'Active',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
        'payment_failed' => 'Payment failed',
    ];

    /** Paid billing cycles an administrator can assign, in months. */
    public const CYCLES = ['monthly' => 1, 'yearly' => 12];

    public const CYCLE_LABELS = [
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'trial' => 'Trial',
        'none' => 'None',
    ];

    public const TRIAL_DAYS = 7;

    /** Statuses whose remaining term can be extended (expired/payment_failed reactivate). */
    public const EXTENDABLE_STATUSES = ['trial', 'active', 'expired', 'payment_failed'];

    public const PRICE_NOTE = 'Reference list prices in Philippine pesos. Online payment through PayMongo is not '
        . 'configured on this deployment; the system administrator applies plan changes.';

    /**
     * Display-only data per plan (moved here from the frontend mock catalog).
     * Bullets list only what the backend enforces or what is true on every plan.
     */
    private const DISPLAY = [
        'free' => [
            'tagline' => 'Start managing your SK digitally.',
            'priceMonthly' => 0,
            'priceYearly' => 0,
            'popular' => false,
        ],
        'basic' => [
            'tagline' => 'Everything you need for everyday SK operations.',
            'priceMonthly' => 999,
            'priceYearly' => 9990,
            'popular' => false,
        ],
        'premium' => [
            'tagline' => 'Complete tools for advanced SK management.',
            'priceMonthly' => 1999,
            'priceYearly' => 19990,
            'popular' => true,
        ],
    ];

    private const RESOURCE_LABELS = [
        'youth' => ['youth record', 'youth records'],
        'accounts' => ['SK Official account', 'SK Official accounts'],
        'programs' => ['active program or event', 'active programs and events'],
        'assistance' => ['active assistance program', 'active assistance programs'],
        'qr' => ['QR attendance scan (lifetime total)', 'QR attendance scans (lifetime total)'],
    ];

    /** @var array<string, string> per-request cache of the database date */
    private static array $today = [];

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
            'excel_export' => false,
            // UI gate for the SK Activity page only (kept from the old frontend
            // catalog); GET /sk/activity itself is not plan-gated.
            'activity_logs' => false,
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
            'excel_export' => true,
            'activity_logs' => true,
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
            'excel_export' => true,
            'activity_logs' => true,
        ],
    ];

    private const EFFECTIVE_FREE_STATUSES = ['expired', 'cancelled', 'payment_failed'];

    /**
     * @param array<string, mixed> $organizationRow membership/org row from Tenant
     * @return array{code:string,name:string,youth:?int,accounts:?int,programs:?int,qr:?int,assistance:?int,csv_import:bool,excel_export:bool}
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
     * @return list<array{code:string,name:string,youth:?int,accounts:?int,programs:?int,qr:?int,assistance:?int,csv_import:bool,excel_export:bool}>
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

    /**
     * Persist expired status when expires_at is already past.
     * Additive only: never clears cancelled/payment_failed, never extends dates.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function applyExpiry(\PDO $pdo, array $row, string $idKey = 'id'): array
    {
        $id = (int) ($row[$idKey] ?? $row['organization_id'] ?? 0);
        $expires = $row['expires_at'] ?? null;
        $sub = strtolower((string) ($row['sub_status'] ?? ''));
        if ($id < 1 || !is_string($expires) || $expires === '') {
            return $row;
        }
        if (in_array($sub, self::EFFECTIVE_FREE_STATUSES, true)) {
            return $row;
        }

        // Same rule as the SQL below: DATE(expires_at) < CURDATE(), using the database date.
        if (substr($expires, 0, 10) >= self::today($pdo)) {
            return $row;
        }

        $upd = $pdo->prepare(
            "UPDATE organizations
             SET sub_status = 'expired'
             WHERE id = :id
               AND expires_at IS NOT NULL
               AND DATE(expires_at) < CURDATE()
               AND sub_status NOT IN ('expired', 'cancelled', 'payment_failed')"
        );
        $upd->execute(['id' => $id]);
        $row['sub_status'] = 'expired';
        return $row;
    }

    public static function storedPlanCode(array $organizationRow): string
    {
        $stored = strtolower((string) ($organizationRow['plan'] ?? 'free'));
        return isset(self::PLANS[$stored]) ? $stored : 'free';
    }

    public static function isPlan(string $code): bool
    {
        return isset(self::PLANS[$code]);
    }

    /**
     * Server-side price of a paid plan and cycle in integer centavos (PHP), from the
     * same catalog the plan pages show. Null for Free or an unknown plan/cycle.
     * Online checkout uses only this value; a client-sent amount is never read.
     */
    public static function priceCentavos(string $plan, string $cycle): ?int
    {
        if ($plan === 'free' || !isset(self::DISPLAY[$plan]) || !isset(self::CYCLES[$cycle])) {
            return null;
        }
        $pesos = (int) self::DISPLAY[$plan][$cycle === 'yearly' ? 'priceYearly' : 'priceMonthly'];
        return $pesos > 0 ? $pesos * 100 : null;
    }

    /**
     * @return array{code:string,name:string,youth:?int,accounts:?int,programs:?int,qr:?int,assistance:?int,csv_import:bool,excel_export:bool}
     */
    public static function plan(string $code): array
    {
        return self::PLANS[$code] ?? self::PLANS['free'];
    }

    /**
     * Public catalog with display data, for GET /sk/subscription and the admin console.
     *
     * @return list<array<string, mixed>>
     */
    public static function publicCatalog(): array
    {
        $out = [];
        foreach (self::PLANS as $code => $plan) {
            $display = self::DISPLAY[$code];
            $limits = [];
            foreach (self::RESOURCES as $resource) {
                $limits[$resource] = $plan[$resource];
            }
            $out[] = [
                'code' => $code,
                'name' => $plan['name'],
                'tagline' => $display['tagline'],
                'currency' => 'PHP',
                'priceMonthly' => $display['priceMonthly'],
                'priceYearly' => $display['priceYearly'],
                'popular' => $display['popular'],
                'limits' => $limits,
                'features' => [
                    'csvImport' => $plan['csv_import'],
                    'excelExport' => $plan['excel_export'],
                    'activityLogs' => $plan['activity_logs'],
                ],
                'bullets' => self::bullets($plan),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<string>
     */
    private static function bullets(array $plan): array
    {
        $lines = [];
        foreach (self::RESOURCES as $resource) {
            [$one, $many] = self::RESOURCE_LABELS[$resource];
            $limit = $plan[$resource];
            $lines[] = $limit === null
                ? 'Unlimited ' . $many
                : number_format((int) $limit) . ' ' . ((int) $limit === 1 ? $one : $many);
        }
        $lines[] = $plan['csv_import'] ? 'CSV youth import' : 'Youth records entered one at a time (no CSV import)';
        $lines[] = $plan['excel_export'] ? 'CSV and Excel (.xlsx) report export' : 'CSV report export (Excel export not included)';
        return $lines;
    }

    /**
     * The database date (CURDATE()), cached per request so every calculation
     * in one response uses the same "today" as the SQL expiry rule.
     */
    public static function today(PDO $pdo): string
    {
        $key = spl_object_hash($pdo);
        if (!isset(self::$today[$key])) {
            self::$today[$key] = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
        }
        return self::$today[$key];
    }

    /**
     * Signed whole days from the database date to expires_at (MySQL DATEDIFF
     * semantics), or null when there is no expiry date.
     */
    public static function daysUntil(PDO $pdo, mixed $expiresAt): ?int
    {
        if (!is_string($expiresAt) || $expiresAt === '') {
            return null;
        }
        $today = new DateTimeImmutable(self::today($pdo));
        $expires = new DateTimeImmutable(substr($expiresAt, 0, 10));
        $diff = $today->diff($expires);
        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * Shared status / expiry view used by GET /sk/subscription and every admin
     * organization payload. Applies (and persists) the expiry rule first, so a
     * lapsed organization always reads as expired - never its old status.
     *
     * daysRemaining is only set while a term is running (trial/active);
     * expiresIn keeps the raw signed day difference for reference.
     *
     * @param array<string, mixed> $row organizations row (id, plan, sub_status, cycle, started_on, expires_at)
     * @return array<string, mixed>
     */
    public static function state(PDO $pdo, array $row, string $idKey = 'id'): array
    {
        $row = self::applyExpiry($pdo, $row, $idKey);
        $stored = self::storedPlanCode($row);
        $status = strtolower((string) ($row['sub_status'] ?? ''));
        if ($status === '') {
            $status = 'free';
        }
        $cycle = (string) ($row['cycle'] ?? 'none');
        $expiresAt = $row['expires_at'] ?? null;
        $expiresAt = is_string($expiresAt) && $expiresAt !== '' ? substr($expiresAt, 0, 10) : null;
        $expiresIn = self::daysUntil($pdo, $expiresAt);
        $running = in_array($status, ['trial', 'active'], true);
        $effective = self::effective($row);

        return [
            'plan' => $stored,
            'planName' => self::PLANS[$stored]['name'],
            'status' => $status,
            'statusLabel' => self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status)),
            'cycle' => $cycle,
            'cycleLabel' => self::CYCLE_LABELS[$cycle] ?? ucfirst($cycle),
            'startedOn' => isset($row['started_on']) && $row['started_on'] !== '' ? (string) $row['started_on'] : null,
            'expiresAt' => $expiresAt,
            'expiresIn' => $expiresIn,
            'daysRemaining' => $running && $expiresIn !== null ? max(0, $expiresIn) : null,
            'effectivePlan' => [
                'code' => $effective['code'],
                'name' => $effective['name'],
                'csv_import' => $effective['csv_import'],
                'excel_export' => $effective['excel_export'],
            ],
            'restrictedToFree' => in_array($status, self::EFFECTIVE_FREE_STATUSES, true) && $stored !== 'free',
        ];
    }

    /**
     * Set-based form of applyExpiry (same rule) for admin lists and the
     * "run subscription cycle" action.
     *
     * @return list<int> ids that were moved to expired
     */
    public static function expireDue(PDO $pdo): array
    {
        $ids = array_map('intval', $pdo->query(
            "SELECT id FROM organizations
             WHERE expires_at IS NOT NULL
               AND DATE(expires_at) < CURDATE()
               AND sub_status NOT IN ('expired', 'cancelled', 'payment_failed')"
        )->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return [];
        }
        $pdo->exec(
            "UPDATE organizations
             SET sub_status = 'expired'
             WHERE id IN (" . implode(',', $ids) . ")
               AND expires_at IS NOT NULL
               AND DATE(expires_at) < CURDATE()
               AND sub_status NOT IN ('expired', 'cancelled', 'payment_failed')"
        );
        return $ids;
    }

    /**
     * Canonical usage count for one limited resource. The create-time checks
     * and GET /sk/subscription both use this, so usage always equals what the
     * limit is enforced against.
     */
    public static function count(PDO $pdo, string $resource, int $organizationId): int
    {
        $sql = match ($resource) {
            'youth' => 'SELECT COUNT(*) FROM youth WHERE organization_id = :org AND archived = 0',
            'accounts' => 'SELECT COUNT(*) FROM organization_users ou
                 INNER JOIN users u ON u.id = ou.user_id
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE ou.organization_id = :org
                   AND ou.status = \'active\'
                   AND r.code = \'' . AuthService::ROLE_SK_OFFICIAL . '\'',
            'programs' => "SELECT COUNT(*) FROM programs
                 WHERE organization_id = :org AND status IN ('draft', 'published', 'ongoing')",
            'assistance' => "SELECT COUNT(*) FROM assistance_programs
                 WHERE organization_id = :org AND status IN ('draft', 'open', 'full')",
            'qr' => 'SELECT qr_uses FROM organizations WHERE id = :org',
            default => throw new \InvalidArgumentException('Unknown plan resource: ' . $resource),
        };
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['org' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    public static function usage(PDO $pdo, int $organizationId): array
    {
        $out = [];
        foreach (self::RESOURCES as $resource) {
            $out[$resource] = self::count($pdo, $resource, $organizationId);
        }
        return $out;
    }

    /**
     * Serialise limit checks for one organization: opens a transaction when
     * none is active and takes a row lock on the organization, so two
     * concurrent creates cannot both pass a count-then-insert check.
     *
     * @return bool true when this call started the transaction (pass to releaseLock)
     */
    public static function lockOrganization(PDO $pdo, int $organizationId): bool
    {
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        $stmt = $pdo->prepare('SELECT id FROM organizations WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $organizationId]);
        return $started;
    }

    public static function releaseLock(PDO $pdo, bool $started): void
    {
        if ($started && $pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    /**
     * 403 PLAN_LIMIT_REACHED unless $usage + $adding fits the plan limit.
     * Rolls back any open transaction first, so nothing partial is written.
     *
     * @param array<string, mixed> $plan effective plan
     * @param array<string, mixed> $extra
     */
    public static function assertRoom(PDO $pdo, string $resource, array $plan, int $usage, int $adding = 1, ?string $message = null, array $extra = []): void
    {
        $limit = $plan[$resource] ?? null;
        if ($limit === null || $usage + $adding <= (int) $limit) {
            return;
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Json::error('PLAN_LIMIT_REACHED', $message ?? self::limitMessage($resource, $plan), 403, array_merge([
            'resource' => $resource,
            'usage' => $usage,
            'limit' => (int) $limit,
            'plan' => (string) $plan['code'],
        ], $extra));
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function limitMessage(string $resource, array $plan): string
    {
        return match ($resource) {
            'youth' => self::youthLimitMessage($plan),
            'accounts' => self::accountsLimitMessage($plan),
            'programs' => self::programsLimitMessage($plan),
            'assistance' => self::assistanceLimitMessage($plan),
            'qr' => self::qrLimitMessage($plan),
            default => 'You have reached a limit of the ' . $plan['name'] . ' plan.',
        };
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
