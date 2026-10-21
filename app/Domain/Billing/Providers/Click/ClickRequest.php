<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers\Click;

/**
 * One validated Prepare / Complete form (docs/13 §4.3). Every field is kept as the string Click
 * sent (the signature is computed over those strings); `action` and `error` are also parsed.
 * Shape errors are -8, a foreign `action` is -3, a malformed `sign_string` is -1.
 */
final readonly class ClickRequest
{
    public const ACTION_PREPARE = 0;

    public const ACTION_COMPLETE = 1;

    private const MAX_ID_LENGTH = 64;

    private const FIELDS = ['click_trans_id', 'service_id', 'click_paydoc_id', 'merchant_trans_id', 'merchant_prepare_id', 'amount', 'action', 'error', 'error_note', 'sign_time', 'sign_string'];

    /**
     * @param  array<string, mixed>  $raw  the request as received, minus `sign_string` (what `provider_transactions.raw` stores)
     */
    private function __construct(
        public string $clickTransId,
        public string $serviceId,
        public ?string $clickPaydocId,
        public string $merchantTransId,
        public ?string $merchantPrepareId,
        public string $amount,
        public int $action,
        public string $actionRaw,
        public int $error,
        public ?string $errorNote,
        public string $signTime,
        public string $signString,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input  the decoded form
     *
     * @throws ClickException -8 | -3 | -1
     */
    public static function parse(array $input, int $expectedAction): self
    {
        $clickTransId = self::id($input, 'click_trans_id');
        $serviceId = self::id($input, 'service_id');
        $merchantTransId = self::id($input, 'merchant_trans_id');
        $amount = self::string($input, 'amount', 32);
        $actionRaw = self::string($input, 'action', 4);
        $signTime = self::string($input, 'sign_time', 19);
        $signString = self::string($input, 'sign_string', 64);

        if (preg_match('/^-?\d{1,3}$/', $actionRaw) !== 1) {
            throw ClickException::requestError('action must be an integer');
        }
        $action = (int) $actionRaw;
        if ($action !== $expectedAction) {
            throw ClickException::actionNotFound($action);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $signTime) !== 1) {
            throw ClickException::requestError('sign_time must be YYYY-MM-DD HH:MM:SS');
        }

        if (preg_match('/^[A-Fa-f0-9]{32}$/', $signString) !== 1) {
            throw ClickException::signCheckFailed('sign_string is not an md5 digest');
        }

        $merchantPrepareId = null;
        if ($expectedAction === self::ACTION_COMPLETE) {
            $merchantPrepareId = self::string($input, 'merchant_prepare_id', 20);
            if (preg_match('/^\d{1,19}$/', $merchantPrepareId) !== 1) {
                throw ClickException::requestError('merchant_prepare_id must be an integer');
            }
        }

        $errorRaw = self::optionalString($input, 'error', 12) ?? '0';
        if (preg_match('/^-?\d{1,10}$/', $errorRaw) !== 1) {
            throw ClickException::requestError('error must be an integer');
        }

        return new self(
            clickTransId: $clickTransId,
            serviceId: $serviceId,
            clickPaydocId: self::optionalString($input, 'click_paydoc_id', self::MAX_ID_LENGTH),
            merchantTransId: $merchantTransId,
            merchantPrepareId: $merchantPrepareId,
            amount: $amount,
            action: $action,
            actionRaw: $actionRaw,
            error: (int) $errorRaw,
            errorNote: self::optionalString($input, 'error_note', 255),
            signTime: $signTime,
            signString: $signString,
            raw: self::rawOf($input),
        );
    }

    /**
     * The known fields of a form (strings only, `sign_string` dropped) — for `raw` and for error
     * answers to requests that did not parse.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, string>
     */
    public static function rawOf(array $input): array
    {
        $raw = [];
        foreach (self::FIELDS as $field) {
            if ($field === 'sign_string') {
                continue;
            }
            $value = $input[$field] ?? null;
            if (is_scalar($value) && ! is_bool($value)) {
                $raw[$field] = mb_substr((string) $value, 0, 255);
            }
        }

        return $raw;
    }

    /** @param array<array-key, mixed> $input */
    private static function id(array $input, string $key): string
    {
        $value = self::string($input, $key, self::MAX_ID_LENGTH);
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $value) !== 1) {
            throw ClickException::requestError("{$key} is malformed");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $input */
    private static function string(array $input, string $key, int $maxLength): string
    {
        $value = self::optionalString($input, $key, $maxLength);
        if ($value === null) {
            throw ClickException::requestError("{$key} is missing");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $input */
    private static function optionalString(array $input, string $key, int $maxLength): ?string
    {
        $value = $input[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_scalar($value) || is_bool($value)) {
            throw ClickException::requestError("{$key} must be a scalar");
        }

        $value = trim((string) $value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw ClickException::requestError("{$key} is empty or too long");
        }

        return $value;
    }
}
