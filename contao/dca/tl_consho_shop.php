<?php declare(strict_types=1);

use Webwerkwien\ContaoConshoBundle\Checkout\BuyButtonLabel;

/*
 * Shop configuration.
 *
 * An entity of its own; root pages select one. Several root pages may point at
 * the same shop — a multilingual site is several root pages in Contao, but one
 * shop, and its order numbers have to be shared.
 * See docs/block-a-fundament.md, section 4.
 *
 * The palette is deliberately small. Tax configuration, number ranges and
 * mail transports arrived with A-3, A-6 and A-9, shipping and payment
 * methods with C-2; each of those plans appends its own legend.
 */
$GLOBALS['TL_DCA']['tl_consho_shop'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        // ⚠️ tl_consho_facet_page must NOT be listed here any more. Landing
        // pages hang off tl_page since B-4 (see contao/dca/tl_page.php), and
        // DC_Table::deleteChildren() selects child rows by pid alone unless the
        // child table has dynamicPtable. Left in, deleting shop 12 would delete
        // the landing pages of PAGE 12 — and copying shop 12 would copy them.
        //
        // tl_consho_translation since C-8 (invoice texts). Safe despite the
        // note above: it HAS dynamicPtable, so its children are selected by
        // ptable and pid together (TranslationDcaTest).
        'ctable' => ['tl_consho_order_status', 'tl_consho_number_range', 'tl_consho_translation'],
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 1,
            // Tie-break: titles are free text, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['title', 'id'],
            'flag' => 1,
            'panelLayout' => 'filter;search,limit',

            // Names the record in the breadcrumb; see tl_consho_product.
            'defaultSearchField' => 'title',
        ],
        // The currencies the shop accepts — not `currency`, the transitional
        // column no mask or command sets since C-1c (a new shop showed none).
        'label' => [
            'fields' => ['title', 'currencies'],
            'format' => '%s <span class="tl_gray">[%s]</span>',
        ],
        'operations' => [
            // Own operation, pencil keeps editing the record — see tl_consho_product.
            // `primary` keeps the jumps in the row — see tl_consho_product.
            'status' => ['href' => 'table=tl_consho_order_status', 'icon' => 'header.svg', 'primary' => true],
            'numbers' => ['href' => 'table=tl_consho_number_range', 'icon' => 'featured.svg', 'primary' => true],
            // Block I (I5): the setup page of this shop, a view of the dashboard —
            // the address is set by SetupButtonListener.
            'setup' => ['icon' => 'wrench.svg', 'primary' => true],

            'edit',
            '!copy',
            '!delete',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => [
        '__selector__' => ['invoiceArchive'],
        'default' => '{title_legend},title;{currency_legend},currencies;{language_legend},fallbackLanguage;{country_legend},country,deliveryCountries;{tax_legend},vatId,taxAtDestination;{customergroup_legend},customerGroup,netPriceGroups,ageVerifiedGroups;{methods_legend},shippingMethods,paymentMethods;{status_legend:hide},statusAfterPayment,statusAfterDelivery,statusWhenSettled;{checkout_legend},guestCheckout,termsRequired,buyButtonLabel,afterAddToCart;{withdrawal_legend},withdrawalDays,withdrawalGraceDays;{privacy_legend},retentionYears,autoAnonymize;{invoice_legend},invoiceCompany,invoiceLegalForm,invoiceStreet,invoicePostal,invoiceCity,invoiceCountry,invoiceContactEmail,invoiceLogo,deliveryDaysMin,deliveryDaysMax,remainderDeliveryDays,invoiceFooter,invoiceReverseChargeNote,eInvoice,invoiceArchive;{mail_legend},operatorName,operatorAddress,mailTransport',
    ],

    'subpalettes' => [
        'invoiceArchive' => 'invoiceArchiveFolder',
    ],

    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'title' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        /*
         * Which currencies this shop can accept (C-1c, spec 4.0). NOT which
         * one a visitor sees — that is a property of the audience and sits on
         * the start page, the same place the language does.
         *
         * The whole ISO 4217 list here, only the currencies actually sold in
         * the price mask (CoupledPriceInputListener::currencyOptions()). That
         * is the division of labour, not a contradiction: this field says what
         * is possible, the price mask follows from it. See ShopCurrenciesListener.
         */
        'currencies' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['multiple' => true, 'chosen' => true, 'mandatory' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        /*
         * ⚠️ Out of the palette since C-1c, still in the table on purpose.
         *
         * C-1c task 3 copies the value into `currencies` and into the start
         * pages; the column is dropped only after the acceptance run on c5 —
         * same course as `gross` on tl_consho_price in C-1b. A migration that
         * removes the source in the same release leaves nothing to fall back
         * on if the copy turns out wrong on real data.
         *
         * Everything still reading it (DbalMarketRepository, VisibilityProjector,
         * the cart and detail controllers) is rewritten in tasks 2 and 3, not
         * here. It stays editable through neither mask in the meantime.
         *
         * ⚠️ The default is '' since C-1c, no longer 'EUR' (Michael, 2026-09-21:
         * "lieber laut als still, ich denke das wäre auch internationaler").
         * Both halves of that matter, and the second outlives this column:
         *
         * LOUD — a shop created before task 3 fills `currencies` now has no
         * single currency, and '' says so. DbalMarketRepository filters
         * `WHERE currency <> ''`, so such a shop drops out of the markets
         * visibly instead of quietly selling in euro that nobody chose.
         *
         * INTERNATIONAL — a built-in 'EUR' is a eurocentric default in a
         * product whose entire purpose is handling several currencies. That
         * argument holds regardless of task 10 dropping the column.
         */
        'currency' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'maxlength' => 3, 'rgxp' => 'alpha', 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 3, 'default' => ''],
        ],
        /*
         * The language the texts sit in on the record itself. Does not follow
         * from tl_page.fallback — that applies per domain, but a shop may
         * serve several domains. The selection therefore comes from this
         * shop's root pages, see ShopLanguageOptionsListener.
         */
        'fallbackLanguage' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 20, 'default' => ''],
        ],
        /*
         * The home country (C22, C25). Two jobs: it is where the shop is
         * established — domestic or cross-border for reverse charge — and it
         * is the country whose rate turns net into the gross a visitor sees
         * while no delivery country is known yet.
         *
         * Mandatory in the mask, but '' in the column: a shop saved before
         * C-1a has none, and the start page says so (HomeCountryMissing)
         * rather than the listing silently showing nothing.
         */
        'country' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'chosen' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 2, 'default' => ''],
        ],
        /*
         * The whitelist, not the final list. What the customer gets to pick
         * at checkout is this intersected with the countries every tax class
         * in use actually has a rate for — see DeliveryCountryProvider. A shop
         * cannot accidentally sell into a country it has no rate for.
         */
        'deliveryCountries' => [
            'inputType' => 'checkboxWizard',
            'exclude' => true,
            'eval' => ['multiple' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        /*
         * The shop's OWN VAT ID, and it earns its place through one thing:
         * VIES only issues a consultation number when the query names the
         * requester. Without it a check still says valid or invalid, but
         * produces no evidence — and evidence is the whole reason the result
         * is stored. Measured on 2026-09-09 against the live service.
         */
        'vatId' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 32, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
        ],
        /*
         * Block R (O1, O3, R1): distance sales to consumers in other member
         * states are taxed in THEIR country. Off by default — every business
         * starts below the 10,000 euro threshold (Art. 59c MwStSystRL), and
         * whether it is over it or opted out is the operator's to know.
         *
         * It belongs to the business, not the shop: saving it here sets it
         * on every shop with the same VAT ID (TaxAtDestinationListener).
         */
        'taxAtDestination' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50 m12'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        /*
         * Which member groups are shown net prices (C20, C-4).
         *
         * Two things sit in one field, and they are deliberately not the same
         * question:
         *
         *   - WHICH PRICE applies comes from tl_consho_price.customerGroup and
         *     needs no setting here at all. A group price works the moment the
         *     operator enters one.
         *   - WHETHER THE VISITOR READS NET is this field. A wholesale group may
         *     well pay the list price and still want to see net amounts.
         *
         * Hence a shop setting and not a flag on tl_member_group: the same
         * member can be a trade customer of one shop and a private buyer of
         * another, and a flag on the group would decide for both. A shop is one
         * invoicing entity (C-3b); this is a statement about its own audience.
         *
         * Guests are never in this list and therefore always see gross. That is
         * not a default but a rule: before a price display can be net, someone
         * has to be liable for the VAT they then have to add themselves.
         */
        /*
         * The base group of the shop's customers (D-4, decision of
         * 2026-09-25 in spec C): accounts WITHOUT an order show up in the
         * customer list through it. Members with an order and guests come from
         * the orders themselves.
         *
         * Mandatory without a condition (Michael: „Bei Betreiber müsste das
         * dann ein Pflichtfeld sein", „ich bin auch eher gegen automatisch
         * anlegen"): accounts also come from Contao's registration, which
         * assigns its own groups, so Consho cannot know whether a shop has
         * accounts at all. The operator creates the group and sets the
         * registration to it.
         */
        'customerGroup' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_member_group.name',
            // The three group fields side by side (Michael, 2026-09-28:
            // „Gruppenfelder 3 spaltig bitte im BE").
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w33 clr'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'netPriceGroups' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_member_group.name',
            'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'w33'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        /*
         * Members whose age the operator has verified outside the shop —
         * by ID at the counter, by a Post-Ident, by whatever the operator
         * answers for (C19). Consho does not verify anything itself and never
         * writes to tl_member_group; it only reads which groups count.
         *
         * One of TWO ways to reach an article from 16 or 18 (Michael,
         * 2026-09-22: "Einer genügt"). The other is a shipping method that
         * checks on hand-over. Either one opens the order; with neither
         * configured the article cannot be ordered at all, which is the
         * correct default — an unverifiable age is not a formality.
         */
        'ageVerifiedGroups' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_member_group.name',
            'eval' => ['multiple' => true, 'chosen' => true, 'tl_class' => 'w33'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        /*
         * Ordering without an account, on by default (C14). Off leaves only
         * signing in — the login element the operator puts on the checkout
         * page, which is Contao's own and needs nothing from Consho.
         */
        'guestCheckout' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'default' => true,
            'eval' => ['tl_class' => 'w50 clr'],
            'sql' => ['type' => 'boolean', 'default' => true],
        ],
        /*
         * The terms tick box (C18). Off by default: neither the FAGG nor the
         * BGB demands one, and a box that has to be ticked is one more way for
         * an order to fail. Where a shop wants it, the moment of the tick is
         * kept at the order as termsAcceptedAt (C-7).
         *
         * There is deliberately NO privacy tick box: the order rests on the
         * contract (Art. 6 Abs. 1 lit. b DSGVO), not on consent, and asking
         * for consent that is not the legal basis makes the basis look wrong.
         */
        'termsRequired' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        /*
         * What the buy button says. The three allowed wordings and the reason
         * they are an enum are in Checkout\BuyButtonLabel.
         */
        'buyButtonLabel' => [
            'inputType' => 'select',
            'exclude' => true,
            'options' => array_column(BuyButtonLabel::cases(), 'value'),
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_shop']['buyButtonLabelRef'],
            'eval' => ['tl_class' => 'w50 clr'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => 'paymentRequired'],
        ],
        /*
         * The shop's methods, in checkout order; the top one available is the
         * preselection (C2). A checkboxWizard stores the ticked ids in the
         * order dragged. Two half-width columns: shipping left, payment right.
         * Deleted or unpublished methods are skipped when read.
         */
        'shippingMethods' => [
            'inputType' => 'checkboxWizard',
            'exclude' => true,
            'eval' => ['multiple' => true, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        'paymentMethods' => [
            'inputType' => 'checkboxWizard',
            'exclude' => true,
            'eval' => ['multiple' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        /*
         * Automatic status changes (D-1, spec D 5.3). All three optional: a
         * shop that sets its statuses by hand leaves them empty.
         *
         * A change happens only where the target status's preconditions hold
         * (StatusRequirement) and the current status does not hold the order
         * (isCancelled, isCompleted, locksChanges). Options: the statuses of
         * THIS shop — they are its children (ctable).
         */
        'statusAfterPayment' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'statusAfterDelivery' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'statusWhenSettled' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        /*
         * WHO the shop is, not who it writes as. The sender lives on the mail
         * configuration, because the sender domain is what SPF and DMARC are
         * checked against — see tl_consho_mail_transport.
         *
         * This address is where the OPERATOR's copies go. Under the old name
         * one field meant both things at once, which is how a shop ends up
         * writing to itself under a domain it never authenticated.
         */
        'mailTransport' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_consho_mail_transport.title',

            // clr: the operator pair fills the row above, this starts a new one.
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        /*
         * What happens after "add to basket" (C12). Straight to the basket is
         * the default: it is the one answer that always tells the visitor what
         * happened, with or without a mini basket on the page.
         *
         * "Back to the page" needs a mini basket somewhere, otherwise adding
         * looks like nothing happened — DbalMiniCartIssueSource says so in the
         * back end.
         */
        'afterAddToCart' => [
            'inputType' => 'select',
            'exclude' => true,
            'options' => ['cart', 'page'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_shop']['afterAddToCartOptions'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'cart'],
        ],
        /*
         * The invoice sender (9, 11.1) — who issues the bill, in separate
         * fields (Michael, 2026-09-22: "Einzelfelder").
         *
         * Not a text block, although one would be quicker to fill in: a
         * structured invoice (ZUGFeRD/Factur-X) needs the parts apart, and
         * they cannot be recovered from a block afterwards. It also lets the
         * finding check the individual entries instead of only whether
         * anything is there at all.
         *
         * ⚠️ This is NOT the shop's address for the imprint and not the
         * operator's mail address — see operatorAddress below, which despite
         * its name holds an e-mail.
         *
         * Nothing here is `mandatory`: a shop that is still being set up must
         * stay savable. What is missing blocks the checkout through
         * DbalLegalReadinessIssueSource (11.3), where the operator can see all of
         * it at once.
         */
        'invoiceCompany' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        /*
         * Optional, and the only one of the six that is: in Austria and
         * Germany the legal form is usually part of the registered name
         * already ("Muster GmbH"), and demanding it twice would be wrong more
         * often than right.
         */
        'invoiceLegalForm' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 128, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
        ],
        'invoiceStreet' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'invoicePostal' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 32, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
        ],
        'invoiceCity' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'invoiceCountry' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['chosen' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 2, 'default' => ''],
        ],
        /*
         * Block R (R10, R12): the seller's contact on an e-invoice (EN 16931
         * BG-6), frozen with the invoice. Required before e-invoices can be
         * switched on (EInvoiceListener).
         */
        'invoiceContactEmail' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'email', 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        /*
         * The logo on the invoice (C-8, Michael 2026-09-24).
         *
         * The snapshot keeps the file's UUID AND its hash, not the image: a
         * UUID belongs to the path, and Contao keeps it when a file is
         * overwritten under the same name (Dbafs, "hash changed" is an update
         * of the same row). A regenerated invoice prints the logo only while
         * both still match, and otherwise prints none and says so — see
         * InvoiceLogo.
         *
         * PNG and JPEG only: dompdf's SVG support is a subset, and a logo that
         * renders differently in the PDF than in the file manager is worse
         * than a clear "not this format". InvoiceLogoFieldListener narrows
         * this further to what the installation allows as images
         * (contao.image.valid_extensions). FileTree::checkValue() enforces
         * `extensions` on save, not only in the picker.
         */
        'invoiceLogo' => [
            'inputType' => 'fileTree',
            'exclude' => true,
            'eval' => ['filesOnly' => true, 'fieldType' => 'radio', 'extensions' => 'png,jpg,jpeg', 'tl_class' => 'clr'],
            'sql' => ['type' => 'binary', 'length' => 16, 'notnull' => false],
        ],
        /*
         * The expected delivery time in working days (C-8, Michael 2026-09-24:
         * "Lieferzeit-Spanne am Shop").
         *
         * § 11 Abs 1 Z 3 lit d UStG asks for the day of delivery, and a
         * prepayment invoice is written at the purchase, before anything is
         * shipped. UStR 2000 Rz 1524 (only via a secondary source) asks such an
         * invoice for the expected date or period — so the invoice states the
         * period these two numbers give, counted from the day of issue in
         * working days (Monday to Friday; public holidays are NOT counted out).
         *
         * Empty means none is stated; DbalInvoiceIssueSource warns when the
         * shop issues invoices at the purchase. `natural` without a `mandatory`
         * so that a shop being set up stays savable.
         */
        'deliveryDaysMin' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        'deliveryDaysMax' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        /*
         * The rest after a partial shipment (E-6b, Michael 2026-09-28: „1.
         * Sendung plus x Tage, x soll der Betreiber ausfüllen pro Bestellung,
         * ggf. mit globalen Grundwert"). The default; an order may carry its own.
         */
        'remainderDeliveryDays' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        /*
         * How long the withdrawal function stays open (D-2, W4).
         *
         * The operator sets it, the statutory 14 days are the default
         * (Michael, 2026-09-27). 0 means no withdrawal function at all — a shop
         * that only delivers outside the EU. A period under 14 days while a
         * delivery country lies in the EU/EEA is a warning, never a block
         * (DbalConfigurationIssueSource).
         *
         * The grace days cover the way from the last shipment to the
         * customer: the period runs from RECEIPT, and Consho only knows the
         * shipping date. The window ends at the end of the day of last
         * shipment + period + grace (WithdrawalWindow); since WA a later
         * withdrawal is still taken and marked `afterWindow`.
         *
         * `mandatory` is off on purpose: 0 is a legitimate answer, and
         * Contao's mandatory check refuses "0" as empty.
         */
        'withdrawalDays' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 14],
        ],
        'withdrawalGraceDays' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 7],
        ],
        /*
         * D-5 (L2): how long an order and its documents are kept, in years,
         * counted from the end of the calendar year of its latest document.
         * Mandatory WITHOUT a default: the number is a legal judgement of the
         * operator's — AT and DE differ (the help names the primary texts),
         * and Consho sets no legal wording (Michael, 2026-09-22). 0 in the
         * column means "not set", never "keep nothing".
         */
        'retentionYears' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['mandatory' => true, 'rgxp' => 'natural', 'minval' => 1, 'maxlength' => 2, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        /*
         * D-5 (L5): anonymise orders once their period has ended, without a
         * request (Art. 5 Abs. 1 lit. e DSGVO, storage limitation). On by
         * default — the law does not wait for a request — and switchable per
         * shop for an operator who keeps records longer on another ground.
         */
        'autoAnonymize' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'default' => true,
            'eval' => ['tl_class' => 'w50 m12'],
            'sql' => ['type' => 'boolean', 'default' => true],
        ],
        /*
         * Two texts the operator writes and Consho freezes into every
         * invoice (C-8, Michael 2026-09-24: "Betreiber-Texte, eingefroren").
         *
         * Consho sets no legal wording (2026-09-22). What an invoice has to say
         * beyond the figures — the registry entry, the bank, the note on the
         * reverse charge — depends on the operator's legal form and country,
         * and a text the bundle shipped would be wrong for the next shop.
         *
         * Translatable (TranslatableFields), because the invoice is in the
         * language of the order. The reverse charge note is printed only on an
         * invoice with a reverse charge line; DbalInvoiceIssueSource says when
         * it is missing although such an invoice can arise.
         */
        'invoiceFooter' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'eval' => ['tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'invoiceReverseChargeNote' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'eval' => ['tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        /*
         * Block R (R9, R10): invoices, cancellations and corrections to
         * business customers carry the ZUGFeRD XML (profile EN 16931) in the
         * PDF. Off by default; only with the shop's VAT ID, company and
         * contact e-mail (EInvoiceListener). Consumers never get one.
         */
        'eInvoice' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'clr'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        /*
         * Keep a copy of every PDF in files/ (C8, 8.3) — the operator's option
         * for the case that "urschriftgetreu" (§ 131 Abs 3 BAO) is read
         * strictly. Off by default: without it the invoice is regenerated
         * from its snapshot.
         */
        'invoiceArchive' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['submitOnChange' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        /*
         * A folder, protected: DbalInvoiceIssueSource warns when it or a
         * parent carries `.public`, because then every invoice is a download
         * for anyone who guesses its name.
         *
         * ⚠️ `'files' => false` is NOT redundant, although the picker would
         * show folders only without it. The picker is the browser's side;
         * the SERVER checks "folders only" in FileTree::checkValue() with
         * `$this->files === false` — strictly false, not merely falsy. Left
         * out, a crafted POST could make a file the archive folder. (Taken
         * out once on 2026-09-24 in the name of "switch options off by
         * leaving them out", and put back the same hour: that rule is about
         * options Contao reads as truthy, not about a value it asks for.)
         */
        'invoiceArchiveFolder' => [
            'inputType' => 'fileTree',
            'exclude' => true,
            'eval' => ['fieldType' => 'radio', 'files' => false, 'mandatory' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'binary', 'length' => 16, 'notnull' => false],
        ],
        'operatorName' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        /*
         * ⚠️ An E-MAIL ADDRESS, not a postal one — the recipient of the
         * operator's copy of every order confirmation (7.6). The name is older
         * than the invoice sender above and stays for compatibility; the label
         * says what it is.
         */
        'operatorAddress' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'email', 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
    ],
];
