<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;

/**
 * The payment methods a shop selected, published, in the selected order —
 * the counterpart of DbalShippingMethodRepository. Titles and descriptions
 * are in the main language; the checkout translates them (C-5). The
 * `instructions` come in the language asked for (C-9, see forShop()).
 */
final class DbalPaymentMethodRepository implements PaymentMethodRepositoryInterface, PaymentMethodLookupInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Published or not, selected by a shop or not. The instructions in the
     * main language: nothing that asks by id shows them to a buyer.
     */
    public function byId(int $methodId): PaymentMethod|null
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id, type, title, instructions, jsonData, feeTaxMode, feeTaxClass FROM tl_consho_payment_method WHERE id = ? AND type != ''",
            [$methodId],
        );

        if (false === $row) {
            return null;
        }

        return new PaymentMethod(
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['title'],
            [...MethodSettings::decode($row['jsonData'] ?? null), 'instructions' => (string) ($row['instructions'] ?? '')],
            ShippingTaxMode::tryFrom((string) ($row['feeTaxMode'] ?? '')),
            (int) ($row['feeTaxClass'] ?? 0),
        );
    }

    /**
     * @return list<PaymentMethod>
     */
    public function forShop(int $shopId, string $language = '', string $fallback = ''): array
    {
        $ids = MethodSettings::selectedIds($this->connection->fetchOne('SELECT paymentMethods FROM tl_consho_shop WHERE id = ?', [$shopId]));

        if ([] === $ids) {
            return [];
        }

        /*
         * 🔴 The instructions in the ORDER's language (C-9). The purchase
         * freezes them on the order, and until then this read them in the
         * main language while `$language` and `$fallback` sat unused — the
         * class comment promised a translation nobody did. Same rule as
         * everywhere: a non-empty translation, otherwise the main text.
         */
        $join = new TranslationJoin('tl_consho_payment_method', $language, $fallback, 'm');

        $rows = $this->connection->fetchAllAssociativeIndexed(
            // type != '': act=toggle publishes a row without running the
            // mask's mandatory checks, so a never-saved record can be
            // 'published' with an empty type.
            \sprintf(
                "SELECT m.id, m.type, m.title, %s AS instructions, m.jsonData, m.feeTaxMode, m.feeTaxClass FROM tl_consho_payment_method m %s
                  WHERE m.published = 1 AND m.type != '' AND m.id IN (:ids)",
                $join->coalesce('instructions'),
                $join->sql(),
            ),
            ['ids' => $ids, ...$join->params()],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $methods = [];

        foreach ($ids as $id) {
            if (!isset($rows[$id])) {
                continue;
            }

            $row = $rows[$id];

            $methods[] = new PaymentMethod(
                $id,
                (string) $row['type'],
                (string) $row['title'],
                // Core key last: `instructions` is reserved.
                [...MethodSettings::decode($row['jsonData'] ?? null), 'instructions' => (string) ($row['instructions'] ?? '')],
                ShippingTaxMode::tryFrom((string) ($row['feeTaxMode'] ?? '')),
                (int) ($row['feeTaxClass'] ?? 0),
            );
        }

        return $methods;
    }
}
