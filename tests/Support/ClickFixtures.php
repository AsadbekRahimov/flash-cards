<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Billing\Providers\Click\ClickAmount;
use App\Domain\Billing\Providers\Click\ClickSignature;
use App\Models\PaymentOrder;
use Carbon\CarbonImmutable;

/**
 * Click Prepare / Complete forms for tests, from `tests/Fixtures/billing/click/*.json` (docs/13 §4.3),
 * rewritten for a concrete order and signed with the test secret the way Click signs them (the
 * digest is computed here independently of {@see ClickSignature}).
 * Overrides are applied BEFORE signing (so a wrong `amount` still carries a valid signature);
 * pass `sign_string` in `$after` to replace the signature itself (null removes a field).
 */
final class ClickFixtures
{
    public const SECRET = 'click-test-secret-key-0123456789';

    public const SERVICE_ID = '12345';

    public const MERCHANT_ID = '6789';

    public const CLICK_TRANS_ID = '1234567890';

    /** Puts the test service into config (secret, ids, enabled, no allowlist). */
    public static function configure(): void
    {
        config()->set('billing.providers.click.enabled', true);
        config()->set('billing.providers.click.secret_key', self::SECRET);
        config()->set('billing.providers.click.service_id', self::SERVICE_ID);
        config()->set('billing.providers.click.merchant_id', self::MERCHANT_ID);
        config()->set('billing.providers.click.merchant_user_id', '');
        config()->set('billing.providers.click.ip_allowlist', '');
    }

    /**
     * @param  array<string, mixed>  $overrides  signed
     * @param  array<string, mixed>  $after  applied after signing
     * @return array<string, string>
     */
    public static function prepare(PaymentOrder $order, array $overrides = [], array $after = [], string $clickTransId = self::CLICK_TRANS_ID): array
    {
        return self::form('prepare', [
            'click_trans_id' => $clickTransId,
            'merchant_trans_id' => $order->token,
            'amount' => ClickAmount::format($order->amount),
            'sign_time' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
            ...$overrides,
        ], $after);
    }

    /**
     * @param  array<string, mixed>  $overrides  signed
     * @param  array<string, mixed>  $after  applied after signing
     * @return array<string, string>
     */
    public static function complete(PaymentOrder $order, int|string $merchantPrepareId, array $overrides = [], array $after = [], string $clickTransId = self::CLICK_TRANS_ID): array
    {
        return self::form('complete', [
            'click_trans_id' => $clickTransId,
            'merchant_trans_id' => $order->token,
            'merchant_prepare_id' => (string) $merchantPrepareId,
            'amount' => ClickAmount::format($order->amount),
            'sign_time' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
            ...$overrides,
        ], $after);
    }

    /**
     * Click's digest over the form: `click_trans_id + service_id + SECRET + merchant_trans_id [+ merchant_prepare_id] + amount + action + sign_time`.
     *
     * @param  array<string, mixed>  $form
     */
    public static function sign(array $form, string $secret = self::SECRET): string
    {
        $parts = [$form['click_trans_id'] ?? '', $form['service_id'] ?? '', $secret, $form['merchant_trans_id'] ?? ''];
        if ((string) ($form['action'] ?? '0') === '1') {
            $parts[] = $form['merchant_prepare_id'] ?? '';
        }
        $parts = [...$parts, $form['amount'] ?? '', $form['action'] ?? '', $form['sign_time'] ?? ''];

        return md5(implode('', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $parts)));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $after
     * @return array<string, string>
     */
    private static function form(string $name, array $overrides, array $after): array
    {
        $path = base_path("tests/Fixtures/billing/click/{$name}.json");
        /** @var array<string, string> $form */
        $form = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach ($overrides as $key => $value) {
            $form[$key] = is_scalar($value) ? (string) $value : '';
        }
        $form['sign_string'] = self::sign($form);

        foreach ($after as $key => $value) {
            if ($value === null) {
                unset($form[$key]);
            } else {
                $form[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $form;
    }
}
