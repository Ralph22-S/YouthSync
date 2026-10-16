<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Http\AdminGuard;
use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;
use YouthSync\Payments\PaymentService;

/**
 * Online subscription payments (PayMongo hosted checkout).
 * SK routes act only on the signed-in official's own organization; the
 * organization, user, price and currency are never taken from the request.
 */
final class PaymentController
{
    public function __construct(
        private SkGuard $guard,
        private AdminGuard $adminGuard,
        private PaymentService $payments,
    ) {
    }

    public function options(array $params = []): void
    {
        $this->guard->requireSkOfficial();
        Json::success($this->payments->availability());
    }

    public function createCheckout(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $body = Json::readBody();
        Json::success($this->payments->createCheckout(
            $ctx['organization_id'],
            $ctx['user'],
            [
                'plan' => $body['plan'] ?? null,
                'cycle' => $body['cycle'] ?? null,
                'method' => $body['method'] ?? null,
            ]
        ), 201);
    }

    public function showCheckout(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->payments->show($ctx['organization_id'], (int) $params['id']));
    }

    public function cancelCheckout(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->payments->cancel($ctx['organization_id'], $ctx['user'], (int) $params['id']));
    }

    public function myCheckouts(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->payments->listForOrganization($ctx['organization_id'], $_GET));
    }

    public function adminTransactions(array $params = []): void
    {
        $this->adminGuard->requireSystemAdmin();
        Json::success($this->payments->listForAdmin($_GET));
    }

    /** POST /admin/payment-transactions/{id}/reconcile - re-verify with PayMongo (System Admin, audited). */
    public function adminReconcile(array $params): void
    {
        $admin = $this->adminGuard->requireSystemAdmin();
        Json::success($this->payments->reconcile((int) $params['id'], $admin));
    }

    /** POST /admin/payment-transactions/reconcile {reference} - only references stored on a transaction. */
    public function adminReconcileByReference(array $params = []): void
    {
        $admin = $this->adminGuard->requireSystemAdmin();
        $body = Json::readBody();
        $reference = is_string($body['reference'] ?? null) ? trim($body['reference']) : '';
        if ($reference === '') {
            Json::validation(['reference' => 'Enter the YouthSync reference or the PayMongo checkout / payment id.']);
        }
        Json::success($this->payments->reconcileByReference(mb_substr($reference, 0, 80), $admin));
    }

    public function webhook(array $params = []): void
    {
        $raw = file_get_contents('php://input');
        $this->payments->handleWebhook(
            $raw === false ? '' : $raw,
            (string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '')
        );
    }
}
