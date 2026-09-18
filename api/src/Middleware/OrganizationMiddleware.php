<?php
declare(strict_types=1);

namespace YouthSync\Middleware;

use YouthSync\Http\Json;
use YouthSync\Org\Tenant;

final class OrganizationMiddleware
{
    public function __construct(private Tenant $tenant)
    {
    }

    /**
     * Bind the request to the organization stored in the session.
     * Client-supplied organization_id is never read.
     */
    public function handle(int $userId): array
    {
        unset($_GET['organization_id'], $_POST['organization_id']);

        $membership = $this->tenant->membershipForUser($userId);
        if ($membership === null) {
            Json::error(
                'ORGANIZATION_REQUIRED',
                'Your organization account is not active. Contact the system administrator.',
                403
            );
        }

        $sessionOrg = isset($_SESSION[Tenant::SESSION_ORG_KEY])
            ? (int) $_SESSION[Tenant::SESSION_ORG_KEY]
            : 0;

        $membershipOrg = (int) $membership['organization_id'];
        if ($sessionOrg !== $membershipOrg) {
            Json::error('FORBIDDEN', 'The organization for this session is no longer valid.', 403);
        }

        if ($membership['status'] !== 'active') {
            Json::error(
                'ORGANIZATION_INACTIVE',
                'Your organization account is not active. Contact the system administrator.',
                403
            );
        }

        $_SESSION[Tenant::SESSION_ORG_KEY] = $membershipOrg;

        return $membership;
    }
}
