<?php

declare(strict_types=1);

namespace App\Http\Controllers\Org;

use App\Domain\Organizations\Exceptions\OrgBillingException;
use App\Domain\Organizations\Services\OrgBillingService;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /org/{center}/invoice/{order}` — the printable invoice of a center's order (docs/13 §6.5).
 * Behind the panel's auth + tenant middleware (a stranger to the center gets 404 from the tenant
 * check), the OWNER only (teacher 403); an order of another organization is 404. Not cacheable.
 */
final class OrgInvoiceController extends Controller
{
    public function __construct(private readonly OrgBillingService $billing) {}

    public function __invoke(Request $request): Response
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        abort_unless($user instanceof User && $tenant instanceof Organization, 404);

        // By name: the route also carries the `{tenant}` parameter, which must not shift the order id.
        $order = $request->route('order');

        try {
            $invoice = $this->billing->invoice($user, $tenant, is_numeric($order) ? (int) $order : 0);
        } catch (OrgBillingException $e) {
            abort($e->httpStatus());
        }

        $response = response()->view('org.invoice', ['invoice' => $invoice, 'organization' => $tenant, 'locale' => app()->getLocale()]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
