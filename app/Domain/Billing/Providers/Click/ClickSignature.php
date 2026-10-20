<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers\Click;

/**
 * Click Merchant API request signature (docs/13 §4.3, §10):
 *
 *   Prepare  (action 0)  sign_string = md5(click_trans_id + service_id + SECRET_KEY + merchant_trans_id + amount + action + sign_time)
 *   Complete (action 1)  sign_string = md5(click_trans_id + service_id + SECRET_KEY + merchant_trans_id + merchant_prepare_id + amount + action + sign_time)
 *
 * The expected digest is computed with OUR `service_id` and secret and compared with `hash_equals`;
 * the request's `service_id` is additionally compared with `hash_equals` so that a call for another
 * service never verifies even in the (theoretical) event of a digest collision. `amount`, `action`
 * and `sign_time` enter the digest exactly as sent — the sender's strings, not our parsing of them.
 * An empty secret verifies nothing.
 */
final class ClickSignature
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $serviceId,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('billing.providers.click.secret_key', ''),
            (string) config('billing.providers.click.service_id', ''),
        );
    }

    public function verify(ClickRequest $request): bool
    {
        if ($this->secretKey === '' || $this->serviceId === '') {
            return false;
        }

        $expected = $this->digest($request->clickTransId, $request->merchantTransId, $request->merchantPrepareId, $request->amount, $request->actionRaw, $request->signTime);

        $serviceMatches = hash_equals($this->serviceId, $request->serviceId);
        $signMatches = hash_equals($expected, strtolower($request->signString));

        return $serviceMatches && $signMatches;
    }

    /**
     * The digest for the given fields; `$merchantPrepareId` null = Prepare, otherwise Complete.
     * Public so the fixtures of the sandbox smoke can be signed the same way.
     */
    public function digest(string $clickTransId, string $merchantTransId, ?string $merchantPrepareId, string $amount, string $action, string $signTime): string
    {
        return md5(implode('', [
            $clickTransId,
            $this->serviceId,
            $this->secretKey,
            $merchantTransId,
            $merchantPrepareId ?? '',
            $amount,
            $action,
            $signTime,
        ]));
    }
}
