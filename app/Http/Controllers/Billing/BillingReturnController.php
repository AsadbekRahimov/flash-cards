<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Site\Services\SiteLinks;
use App\Http\Controllers\Controller;
use App\Models\PaymentOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /billing/return?order=<token>&lang=<ru|uz>` — where Payme (and later Click) send the payer
 * back after the checkout page (docs/13 §4.2). A static status page: the order's status by its
 * token (the capability the payer was given), a line of explanation and a button back to the bot.
 * No session, no cookies, nothing personal: an unknown or missing token shows a neutral text.
 */
final class BillingReturnController extends Controller
{
    /** The statuses the page distinguishes; anything else (`failed`, unknown) falls back to `unknown`. */
    private const KNOWN = [PaymentOrder::STATUS_PAID, PaymentOrder::STATUS_PENDING, PaymentOrder::STATUS_EXPIRED, PaymentOrder::STATUS_CANCELED];

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly LocaleResolver $locales,
        private readonly SiteLinks $links,
    ) {}

    public function __invoke(Request $request): Response
    {
        $token = $request->query('order');
        $order = is_string($token) ? $this->checkout->findByToken($token) : null;

        $lang = $request->query('lang');
        $locale = match (true) {
            is_string($lang) && $this->locales->isSupported($lang) => $lang,
            $order !== null && is_string($order->meta['learner_locale'] ?? null) && $this->locales->isSupported($order->meta['learner_locale']) => $order->meta['learner_locale'],
            default => $this->locales->fromAcceptLanguage($request->getLanguages()),
        };

        $status = $order === null ? 'unknown' : (in_array($order->status, self::KNOWN, true) ? $order->status : 'unknown');

        $response = response()->view('billing.return', [
            'locale' => $locale,
            'status' => $status,
            'botUrl' => $this->links->botUrl(),
            'contact' => $this->links->supportContact(),
        ]);

        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
