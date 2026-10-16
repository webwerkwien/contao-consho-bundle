<?php declare(strict_types=1);

/*
 * Everything that matters here is a VALUE, not a pointer.
 *
 * If tomorrow the tax rate changes, the product is renamed or the price is
 * raised, today's invoice must stay exactly as it was. `product` is the one
 * reference, and it is allowed to dangle: deleting a product must not
 * invalidate a past order.
 */
$GLOBALS['TL_DCA']['tl_consho_order_item'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        'ptable' => 'tl_consho_order',
        /*
         * 🔴 Same finding as in tl_consho_number_range (2026-09-23): no
         * delete operation only hides the button, `notDeletable` is what
         * forbids the act — and "select multiple" offered it.
         *
         * The order itself has been `notDeletable` since C-7. Its lines being
         * deletable made that half a promise: the invoice would have stayed,
         * with lines missing from it, and the totals in the head would no
         * longer add up to the lines below them.
         */
        'notDeletable' => true,
        'enableVersioning' => true,
        // destinationCountry (block R, review F8): the OSS threshold asks for
        // orders that went abroad on every call of the start page.
        'sql' => ['keys' => ['id' => 'primary', 'pid' => 'index', 'destinationCountry' => 'index']],
    ],

    'list' => [
        'sorting' => [
            'mode' => 4,
            // Tie-break: sorting values collide after a paste, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['sorting', 'id'],
            'panelLayout' => 'limit',
            'headerFields' => ['orderNumber', 'lastname', 'grandTotal'],
            'defaultSearchField' => 'productName',
        ],
        'label' => ['fields' => ['productName', 'quantity', 'lineTotal'], 'format' => '%s × %s = %s'],
        'operations' => [
            // DL3. POST, with a question first: it cannot be taken back and
            // may end the right of withdrawal. Only on a released service
            // without subscription (ServicePerformedButtonListener).
            'performed' => ['href' => 'key=servicePerformed', 'icon' => 'ok.svg', 'method' => 'POST', 'primary' => true],
            'edit',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => ['default' => '{item_legend},lineType,productName,sku,quantity,kind,performedAt,subscriptionTerms;{price_legend},unitPrice,taxRate,taxCountry,destinationCountry,taxAmount,lineTotal,priceSource,customerGroup'],

    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            // Without it Contao shows pid through the FIRST list field of
            // tl_consho_order, which is `signals` — no column, so "Info" was
            // a 500 (ParentLabelIsAColumnTest, 2026-09-25).
            'foreignKey' => 'tl_consho_order.orderNumber',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_order'],
        ],
        'sorting' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * What this line IS (C-7). An order used to hold product lines only,
         * and anything counting "the goods" could simply count the rows. It
         * cannot any more: shipping and a payment discount sit here too, and a
         * shipping line must not be part of the base its own tax is shared
         * across.
         */
        'lineType' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'product'],
        ],

        // Deliberately no foreignKey and no relation: this reference may dangle.
        'product' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * Which shipping or payment method produced this line, 0 for a product.
         * Proportional shipping tax splits one method across several lines, so
         * the head's `shippingMethod` alone cannot say which line came from
         * where.
         *
         * Allowed to dangle like `product`: a deleted method must not
         * invalidate a past order. The delete guard (C-7) keeps a method that
         * occurs in an order from being deleted in the first place.
         */
        'source' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        'productName' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'sku' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'quantity' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '1.0000'],
        ],
        'unitPrice' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        /*
         * The tax class the rate was resolved from (C-10). Not shown: the
         * invoice states the rate, and the class is only needed to split a new
         * payment amount the way the purchase did. 0 on orders placed before
         * C-10 — those can change only between methods without an amount.
         *
         * Deliberately no foreignKey: a class deleted later must not touch a
         * past order.
         */
        'taxClass' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'taxRate' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 6, 'scale' => 4, 'default' => '0.0000'],
        ],
        // The country of that rate (DL4): the shop's for a service, the
        // delivery country otherwise.
        'taxCountry' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 2, 'default' => ''],
        ],
        /*
         * Where the supply went (block R, R4) — the customer's country the
         * tax rule was asked with. Equal to taxCountry unless the shop taxes
         * a distance sale in its own country (switch off); the OSS threshold
         * and the record keeping (Art. 63c DVO 282/2011) read this one.
         */
        'destinationCountry' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 2, 'default' => ''],
        ],
        'taxAmount' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        'lineTotal' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],

        // Carries the customer-group notice and the FAGG duty for personalised
        // pricing — see PriceSource. Frozen like everything else here.
        'priceSource' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => 'list'],
        ],

        /*
         * The customer group this line's price came from, 0 for a list price
         * (decision with Michael, 2026-09-23).
         *
         * It belongs to the LINE, not to the order: a member holds a set of
         * groups (C-4), the resolution runs per group and the cheapest offer
         * wins, so one line can come from group 1 and the next from group 2.
         * A single field on the head would answer "who was this", which the
         * member reference already does — not "where did this price come
         * from", which is the question a complaint asks.
         *
         * Allowed to dangle: a deleted group must not rewrite a past order.
         */
        'customerGroup' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],

        /*
         * Frozen at the click (F-1, F13, DL1): the ProductKind of this product
         * line — physical, digital or service. Delivery, withdrawal and the
         * tax country depend on it, and the product type may be switched
         * later. '' on charge lines, which are no good of any kind.
         */
        'kind' => [
            'inputType' => 'select',
            'options' => array_map(static fn (Webwerkwien\ContaoConshoBundle\Catalog\ProductKind $kind): string => $kind->value, Webwerkwien\ContaoConshoBundle\Catalog\ProductKind::cases()),
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_order_item']['kinds'],
            'eval' => ['disabled' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'physical'],
        ],

        /*
         * "Leistung erbracht" (DL3): when the operator reported a service
         * line as fully rendered. With the consumer's consent to the early
         * start that ends the right of withdrawal (DbalWithdrawalRepository).
         * Set once by ServicePerformed, never taken back.
         */
        'performedAt' => [
            'inputType' => 'text',
            'eval' => ['rgxp' => 'datim', 'disabled' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'notnull' => false],
        ],

        /*
         * The plan the line was bought in (Abo-2, P13): 0 = one-time sale and
         * every charge line. Allowed to dangle like `product` — the conditions
         * below are the frozen record, not the rule.
         */
        'subscriptionRule' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * The conditions as the buyer read them, in the order's language —
         * "1,00 € / Monat · Laufzeit 1 Jahr · …" (P13, A10). Frozen, because
         * the rule can change tomorrow and invoice and confirmation must still
         * say what was agreed today. '' on a one-time sale.
         */
        'subscriptionTerms' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'clr long'],
            'sql' => ['type' => 'string', 'length' => 512, 'default' => ''],
        ],

        /*
         * "Lizenz verlängern" (Abo-7b, P69): the subscription this line
         * continues — its entitlement and keys move on to the new one. 0 on
         * every other line. Allowed to dangle like `subscriptionRule`.
         */
        'renews' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],

        /*
         * What the purchase click took out of stock for this line (Block G,
         * G4). A cancellation or return gives back at most this — an order
         * from before stock was kept gives back nothing (G9). Never changed
         * afterwards: it is the cap of both ways back.
         *
         * stockCancelled: what the cancellation gave back (second review G,
         * S1). Kept apart so a return corrected AFTER the cancellation still
         * books right in both directions: its cap is stockBooked less this.
         */
        'stockBooked' => ['sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000']],
        'stockCancelled' => ['sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000']],

        /*
         * The net this goods line was given of the cart rule's discount at
         * the purchase (block H, H-5, R1), positive — frozen from the rule's
         * own split, because a rule with product conditions reduced only the
         * lines it named. A withdrawal of the line gives that part back
         * (DiscountShares). 0 on every line the rule did not reduce.
         */
        'discountNet' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000']],
        // Block Wertgutscheine (WG-4): what one voucher of this line is worth,
        // frozen at the purchase like the price — the product may change
        // before the money arrives. 0 on every other line, and on a voucher
        // without a value: then it is worth what was paid for it.
        'voucherValue' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000']],
        // Block GARAN (GA6): the manufacturer's durability guarantee and its
        // label as the customer saw them before the button — the confirmation
        // and the order page show these, not today's product (§ 7 Abs. 3
        // FAGG). The label as a file reference: the file is the operator's,
        // like the guarantee notice at the start point. 0 / NULL: none.
        // Whole or half years (GA9, GuaranteeYears).
        'durabilityYears' => ['sql' => ['type' => 'decimal', 'precision' => 3, 'scale' => 1, 'default' => '0.0']],
        'durabilityLabel' => ['sql' => ['type' => 'binary', 'length' => 16, 'notnull' => false]],
    ],
];
