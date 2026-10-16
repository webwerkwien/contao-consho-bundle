<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Tax\TaxClassReferences;

/**
 * Who still uses a product type, a manufacturer, a property or one of its
 * values (Connect Stufe 2, CW-4b, H4) — and, since CW-5, a shop, an order
 * status or a mail configuration.
 *
 * Asked by the delete voters of the mask and by the delete commands alike
 * (H5): "in use" is a fact about the catalogue, not about who asks, so it
 * must not depend on a logged-in user.
 *
 * ── Why these cannot simply go ───────────────────────────────────────────
 *
 * Nothing breaks loudly, and that is the problem. A deleted product type
 * leaves its products with a type that no longer exists, and
 * ProductKind::stored() reads that as physical — an e-book would need
 * shipping and keep stock by hand, in silence. A deleted value leaves its
 * product and variant rows pointing at nothing; a variant loses the axis that
 * told it from its sisters. A rule or a listing preset that named it narrows
 * by one value less than its editor chose.
 *
 * Facet pages are not a reason to refuse: they name values by alias, and the
 * spec decided that one whose combination no longer resolves answers 404
 * (block B, 4.5). Custom fields are not covered (G3: their values have no
 * reader yet).
 *
 * ── The shop setup (CW-5, I6) ────────────────────────────────────────────
 *
 * Same rule for a shop, an order status and a mail configuration. A shop
 * takes its order statuses and number ranges along when it goes (ctable):
 * with orders that is an invoice series nobody can continue, and the orders
 * themselves keep a shop that no longer exists. A status named by an order or
 * by its shop's automatic changes, and a mail configuration a shop sends
 * through, are the same kind of silent hole.
 *
 * Not counted: documents (they exist with an order, which already counts),
 * the visibility projection (rebuilt from tl_consho_product_shop), "notify
 * me" wishes (dropped once sent) and baskets (NOT_COUNTED).
 *
 * ── The list is not maintained by hand ───────────────────────────────────
 *
 * CatalogReferencesTest derives it from the DCAs: every field whose
 * `foreignKey` or `relation.table` names one of the covered tables, minus the
 * parent relation of child rows and NOT_COUNTED. Only the references without
 * either are written down here, with their reason (WITHOUT_DCA_LINK).
 */
final class CatalogReferences
{
    private const ONE = 'one';
    private const LIST = 'list';

    /**
     * Referenced table => "table.column" => one id, or a serialized list.
     */
    public const REFERENCES = [
        'tl_consho_product_type' => [
            'tl_consho_product.productType' => self::ONE,
            'tl_consho_price_rule.productTypes' => self::LIST,
            'tl_consho_cart_rule.productTypes' => self::LIST,
        ],
        'tl_consho_manufacturer' => [
            'tl_consho_product.manufacturer' => self::ONE,
            // Another manufacturer named as the responsible person in the EU.
            'tl_consho_manufacturer.euResponsible' => self::ONE,
            'tl_consho_price_rule.manufacturers' => self::LIST,
            'tl_consho_cart_rule.manufacturers' => self::LIST,
        ],
        'tl_consho_property_group' => [
            'tl_consho_product_type.propertyGroups' => self::LIST,
        ],
        'tl_consho_property_option' => [
            'tl_consho_product_property.optionId' => self::ONE,
            'tl_consho_product_option.optionId' => self::ONE,
            'tl_consho_price_rule.propertyOptions' => self::LIST,
            'tl_content.conshoFilter' => self::LIST,
        ],
        // CW-5 (I6): the shop setup.
        'tl_consho_shop' => [
            'tl_consho_order.shop' => self::ONE,
            'tl_consho_subscription.shop' => self::ONE,
            'tl_consho_voucher.shop' => self::ONE,
            'tl_consho_voucher.shops' => self::LIST,
            'tl_consho_cart_rule.shops' => self::LIST,
            'tl_consho_product_shop.shop' => self::ONE,
            'tl_page.consho_shop' => self::ONE,
        ],
        'tl_consho_order_status' => [
            'tl_consho_order.orderStatus' => self::ONE,
            // The automatic changes of its own shop (D-1).
            'tl_consho_shop.statusAfterPayment' => self::ONE,
            'tl_consho_shop.statusAfterDelivery' => self::ONE,
            'tl_consho_shop.statusWhenSettled' => self::ONE,
        ],
        'tl_consho_mail_transport' => [
            'tl_consho_shop.mailTransport' => self::ONE,
        ],
        /*
         * A method an order names (research, conclusion 5; spec 5.1). The
         * order keeps a copy of the title, so the invoice would survive — but
         * the reference is what a later payment attempt, a switch of payment
         * method (C23) or a report follows. Unpublishing retires a method.
         */
        'tl_consho_shipping_method' => [
            'tl_consho_order.shippingMethod' => self::ONE,
        ],
        'tl_consho_payment_method' => [
            'tl_consho_order.paymentMethod' => self::ONE,
            // The method that charges a running subscription's follow-up
            // payments (Abo-4b). Until CW-5 not counted: deleting it left the
            // next bill without a way to pay.
            'tl_consho_subscription.mandateMethod' => self::ONE,
        ],
        /*
         * A subscription rule a saved price row names (Abo-1): the row would
         * stay behind as the price of a contract nobody can read any more.
         */
        'tl_consho_subscription_rule' => [
            'tl_consho_price.subscriptionRule' => self::ONE,
        ],
    ];

    /**
     * Only rows meeting this hold a record. A price mask opened and never
     * saved is no price (tstamp 0, DbalPriceRepository::findCandidates()); an
     * ended subscription is never charged again (as in TaxClassReferences).
     */
    private const CONDITIONS = [
        'tl_consho_price.subscriptionRule' => 'tstamp > 0',
        'tl_consho_subscription.mandateMethod' => "state <> 'ended'",
    ];

    /**
     * References whose field names no table in its DCA: the options come
     * from a callback (PriceRuleOptionsListener, the listing element's
     * filter, OrderStatusListener), so the DCA cannot say what they point at.
     */
    public const WITHOUT_DCA_LINK = [
        'tl_consho_price_rule.propertyOptions',
        'tl_content.conshoFilter',
        'tl_consho_shop.statusAfterPayment',
        'tl_consho_shop.statusAfterDelivery',
        'tl_consho_shop.statusWhenSettled',
        // The order and the price name their method and rule without a
        // foreignKey (they read it themselves).
        'tl_consho_order.shippingMethod',
        'tl_consho_order.paymentMethod',
        'tl_consho_price.subscriptionRule',
    ];

    /**
     * Links the DCAs do name that deliberately do not hold a record.
     *
     * A basket is a visitor's scratch pad that expires on its own; one left
     * open in some browser must not keep its shop alive forever.
     */
    public const NOT_COUNTED = [
        'tl_consho_cart.shop',
        // A product's selection of rules: a deleted rule simply drops out when
        // SaleModes reads it (Abo-1).
        'tl_consho_product.subscriptionRules',
    ];

    /**
     * Relation tables, named by the product they belong to (their pid).
     */
    private const JOINS = [
        'tl_consho_product_property' => 'tl_consho_product',
        'tl_consho_product_option' => 'tl_consho_product',
        'tl_consho_product_shop' => 'tl_consho_product',
    ];

    /**
     * How many users a refusal names at most.
     */
    private const NAMED = 5;

    private readonly TaxClassReferences $taxClasses;

    public function __construct(private readonly Connection $connection, TaxClassReferences|null $taxClasses = null)
    {
        $this->taxClasses = $taxClasses ?? new TaxClassReferences($connection);
    }

    /**
     * Whether usedBy() knows the table. The tax class keeps its own list in
     * TaxClassReferences — it also answers consho:tax:diagnose, and its
     * subscription reference has a condition of its own.
     */
    public static function covers(string $table): bool
    {
        return isset(self::REFERENCES[$table]) || 'tl_consho_tax_class' === $table;
    }

    public function isInUse(string $table, int $id): bool
    {
        return [] !== $this->usedBy($table, $id);
    }

    /**
     * @return list<string> who uses the record, as "table id", at most five
     */
    public function usedBy(string $table, int $id): array
    {
        if ($id < 1 || !self::covers($table)) {
            return [];
        }

        if ('tl_consho_tax_class' === $table) {
            return $this->taxClasses->usedBy($id, self::NAMED);
        }

        $users = $this->direct($table, $id);

        // A property goes into the undo bin with its values (ctable): it is in
        // use as soon as one of them is.
        if ('tl_consho_property_group' === $table) {
            $values = $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_property_option WHERE pid = ? ORDER BY id', [$id]);

            foreach ($values as $value) {
                $users = [...$users, ...$this->direct('tl_consho_property_option', (int) $value)];
            }
        }

        return \array_slice(array_values(array_unique($users)), 0, self::NAMED);
    }

    /**
     * @return list<string>
     */
    private function direct(string $table, int $id): array
    {
        $users = [];

        foreach (self::REFERENCES[$table] as $reference => $kind) {
            [$from, $column] = explode('.', $reference, 2);
            $owner = self::JOINS[$from] ?? null;

            // A manufacturer naming itself as responsible does not hold itself.
            $also = ($from === $table ? ' AND id <> :self' : '').(isset(self::CONDITIONS[$reference]) ? ' AND '.self::CONDITIONS[$reference] : '');
            $self = $from === $table ? ['self' => $id] : [];

            if (self::ONE === $kind) {
                $rows = $this->connection->fetchFirstColumn(
                    \sprintf('SELECT DISTINCT %s FROM %s WHERE %s = :id%s ORDER BY 1 LIMIT %d', null === $owner ? 'id' : 'pid', $from, $column, $also, self::NAMED),
                    ['id' => $id, ...$self],
                );
            } else {
                // Candidates by text, confirmed by reading the list: "5" is
                // also part of "15" and of the index "i:5;".
                $candidates = $this->connection->fetchAllKeyValue(
                    \sprintf('SELECT id, %s FROM %s WHERE %s LIKE :text%s ORDER BY id', $column, $from, $column, $also),
                    ['text' => '%'.$id.'%', ...$self],
                );
                $rows = array_keys(array_filter(
                    $candidates,
                    static fn (mixed $list): bool => \in_array($id, array_map(intval(...), StringUtil::deserialize($list, true)), true),
                ));
            }

            foreach ($rows as $row) {
                $users[] = ($owner ?? $from).' '.$row;
            }
        }

        return $users;
    }
}
