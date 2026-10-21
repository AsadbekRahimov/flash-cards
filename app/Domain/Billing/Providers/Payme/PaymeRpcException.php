<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers\Payme;

use App\Domain\Billing\Services\OrganizationOrderGuard;
use RuntimeException;

/**
 * A JSON-RPC error of the Payme Merchant API (docs/13 §4.2). Carries the Payme code, the
 * localized `message` object (ru / uz / en — Payme requires all three) and the optional `data`
 * (the name of the offending `account` field for the -31050…-31099 range). Always answered with
 * HTTP 200: the HTTP status is not part of the Payme protocol, the body is.
 *
 *   -32700  parse error             body is not JSON
 *   -32600  invalid request         params of the wrong shape
 *   -32601  method not found        unknown / unsupported method (SetFiscalData, ChangePassword)
 *   -32504  insufficient privileges wrong or missing Basic auth
 *   -32400  system error            an unexpected exception (answered with HTTP 500 so Payme retries)
 *   -31001  wrong amount            `amount` ≠ `payment_orders.amount` (or not an integer)
 *   -31003  transaction not found   unknown `id` in Perform / Cancel / Check
 *   -31007  cannot cancel           a performed transaction is outside the refund window
 *   -31008  cannot perform          the transaction state forbids the operation (canceled, timed out, dead order)
 *   -31050  order not found         `account.order_id` matches no order              (data: order_id)
 *   -31051  order not payable       the order is paid / canceled / expired / not a Payme order (data: order_id)
 *   -31052  order busy              another open transaction already holds the order   (data: order_id)
 *   -31053  tier changed            an organization order of a tier other than the live one (data: order_id)
 *   -31054  organization suspended  the order's organization is not active               (data: order_id)
 *   -31055  already subscribed      the learner's Telegram Stars subscription still renews; cancel it first (data: order_id)
 */
final class PaymeRpcException extends RuntimeException
{
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INSUFFICIENT_PRIVILEGES = -32504;

    public const SYSTEM_ERROR = -32400;

    public const WRONG_AMOUNT = -31001;

    public const TRANSACTION_NOT_FOUND = -31003;

    public const CANNOT_CANCEL = -31007;

    public const CANNOT_PERFORM = -31008;

    public const ORDER_NOT_FOUND = -31050;

    public const ORDER_NOT_PAYABLE = -31051;

    public const ORDER_BUSY = -31052;

    public const TIER_CHANGED = -31053;

    public const ORGANIZATION_SUSPENDED = -31054;

    public const ALREADY_SUBSCRIBED = -31055;

    /** The `account` field our checkout link carries (`ac.order_id`), reported in `error.data`. */
    public const ACCOUNT_FIELD = 'order_id';

    /** @var array<int, array{ru: string, uz: string, en: string}> */
    private const MESSAGES = [
        self::PARSE_ERROR => ['ru' => 'Ошибка разбора JSON.', 'uz' => 'JSON tahlilida xatolik.', 'en' => 'Parse error.'],
        self::INVALID_REQUEST => ['ru' => 'Неверный запрос.', 'uz' => "Noto'g'ri so'rov.", 'en' => 'Invalid request.'],
        self::METHOD_NOT_FOUND => ['ru' => 'Запрашиваемый метод не найден.', 'uz' => "So'ralgan metod topilmadi.", 'en' => 'Method not found.'],
        self::INSUFFICIENT_PRIVILEGES => ['ru' => 'Недостаточно привилегий для выполнения метода.', 'uz' => 'Metodni bajarish uchun huquq yetarli emas.', 'en' => 'Insufficient privileges to perform this method.'],
        self::SYSTEM_ERROR => ['ru' => 'Внутренняя ошибка сервера.', 'uz' => 'Serverning ichki xatosi.', 'en' => 'Internal server error.'],
        self::WRONG_AMOUNT => ['ru' => 'Неверная сумма.', 'uz' => "Noto'g'ri summa.", 'en' => 'Wrong amount.'],
        self::TRANSACTION_NOT_FOUND => ['ru' => 'Транзакция не найдена.', 'uz' => 'Tranzaksiya topilmadi.', 'en' => 'Transaction not found.'],
        self::CANNOT_CANCEL => ['ru' => 'Невозможно отменить транзакцию: заказ выполнен.', 'uz' => 'Tranzaksiyani bekor qilish mumkin emas: buyurtma bajarilgan.', 'en' => 'Cannot cancel the transaction: the order is complete.'],
        self::CANNOT_PERFORM => ['ru' => 'Невозможно выполнить операцию.', 'uz' => 'Operatsiyani bajarish mumkin emas.', 'en' => 'Unable to perform the operation.'],
        self::ORDER_NOT_FOUND => ['ru' => 'Заказ не найден.', 'uz' => 'Buyurtma topilmadi.', 'en' => 'Order not found.'],
        self::ORDER_NOT_PAYABLE => ['ru' => 'Заказ уже оплачен, отменён или просрочен.', 'uz' => "Buyurtma allaqachon to'langan, bekor qilingan yoki muddati o'tgan.", 'en' => 'The order is already paid, canceled or expired.'],
        self::ORDER_BUSY => ['ru' => 'По этому заказу уже есть ожидающая оплаты транзакция.', 'uz' => "Bu buyurtma bo'yicha to'lov kutayotgan tranzaksiya mavjud.", 'en' => 'Another transaction is already pending for this order.'],
        self::TIER_CHANGED => ['ru' => 'Тариф центра изменился. Создайте новый заказ в кабинете.', 'uz' => "Markaz tarifi o'zgardi. Kabinetda yangi buyurtma yarating.", 'en' => 'The center\'s tariff has changed. Create a new order in the cabinet.'],
        self::ORGANIZATION_SUSPENDED => ['ru' => 'Центр приостановлен. Обратитесь в поддержку.', 'uz' => "Markaz to'xtatilgan. Qo'llab-quvvatlash xizmatiga murojaat qiling.", 'en' => 'The center is suspended. Contact support.'],
        self::ALREADY_SUBSCRIBED => ['ru' => 'У вас включено автопродление Telegram Stars. Отключите его в боте (/pro) и создайте заказ заново.', 'uz' => "Sizda Telegram Stars avtoyangilash yoqilgan. Uni botda (/pro) o'chiring va buyurtmani qaytadan yarating.", 'en' => 'Telegram Stars auto-renewal is on. Turn it off in the bot (/pro) and create the order again.'],
    ];

    public function __construct(
        public readonly int $rpcCode,
        public readonly ?string $data = null,
        ?string $detail = null,
    ) {
        parent::__construct(trim(sprintf('Payme RPC error %d: %s%s', $rpcCode, self::MESSAGES[$rpcCode]['en'] ?? 'Unknown error.', $detail === null ? '' : " ({$detail})")));
    }

    public static function parseError(): self
    {
        return new self(self::PARSE_ERROR);
    }

    public static function invalidRequest(string $detail): self
    {
        return new self(self::INVALID_REQUEST, null, $detail);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(self::METHOD_NOT_FOUND, null, $method);
    }

    public static function insufficientPrivileges(): self
    {
        return new self(self::INSUFFICIENT_PRIVILEGES);
    }

    public static function systemError(): self
    {
        return new self(self::SYSTEM_ERROR);
    }

    public static function wrongAmount(): self
    {
        return new self(self::WRONG_AMOUNT);
    }

    public static function transactionNotFound(string $id): self
    {
        return new self(self::TRANSACTION_NOT_FOUND, null, $id);
    }

    public static function cannotCancel(string $detail): self
    {
        return new self(self::CANNOT_CANCEL, null, $detail);
    }

    public static function cannotPerform(string $detail): self
    {
        return new self(self::CANNOT_PERFORM, null, $detail);
    }

    public static function orderNotFound(): self
    {
        return new self(self::ORDER_NOT_FOUND, self::ACCOUNT_FIELD);
    }

    public static function orderNotPayable(string $detail): self
    {
        return new self(self::ORDER_NOT_PAYABLE, self::ACCOUNT_FIELD, $detail);
    }

    public static function orderBusy(string $otherTransactionId): self
    {
        return new self(self::ORDER_BUSY, self::ACCOUNT_FIELD, $otherTransactionId);
    }

    public static function tierChanged(): self
    {
        return new self(self::TIER_CHANGED, self::ACCOUNT_FIELD);
    }

    public static function organizationSuspended(): self
    {
        return new self(self::ORGANIZATION_SUSPENDED, self::ACCOUNT_FIELD);
    }

    public static function alreadySubscribed(): self
    {
        return new self(self::ALREADY_SUBSCRIBED, self::ACCOUNT_FIELD);
    }

    /** The account error for an OrganizationOrderGuard refusal; `order_not_payable` for anything else. */
    public static function forRefusal(string $reason): self
    {
        return match ($reason) {
            OrganizationOrderGuard::REFUSAL_TIER_CHANGED => self::tierChanged(),
            OrganizationOrderGuard::REFUSAL_ORGANIZATION_SUSPENDED => self::organizationSuspended(),
            OrganizationOrderGuard::REFUSAL_ALREADY_SUBSCRIBED => self::alreadySubscribed(),
            default => self::orderNotPayable($reason),
        };
    }

    /**
     * The JSON-RPC `error` member.
     *
     * @return array{code: int, message: array{ru: string, uz: string, en: string}, data?: string}
     */
    public function toArray(): array
    {
        $message = self::MESSAGES[$this->rpcCode] ?? self::MESSAGES[self::SYSTEM_ERROR];

        $error = ['code' => $this->rpcCode, 'message' => $message];
        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return $error;
    }
}
