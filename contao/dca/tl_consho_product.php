<?php declare(strict_types=1);

/*
 * Products and variants share this table. A variant is a row whose `pid`
 * points at its main product — see docs/block-a-fundament.md, section 3.1.
 * Variants are never nested; VariantResolver enforces that.
 *
 * Weights and dimensions use decimal(10,4) in kilogrammes and centimetres.
 * Four decimals are not decoration: shipping cost runs on weight brackets, and
 * a hundred rows rounded at three decimals drift by up to 50 g. Rounding
 * happens at the carrier interface, never in the schema — several carriers,
 * several precisions, and the schema must not pick one of them.
 *
 * minPurchase and purchaseSteps come from the Farbenwolf analysis: goods sold
 * by length are not a variant axis with fixed options but a quantity with a
 * step size of 0.1.
 */
$GLOBALS['TL_DCA']['tl_consho_product'] = [
    'config' => [
        'dataContainer' => Contao\DC_Table::class,
        // tl_consho_translation: so that deleting a product takes its
        // translations into tl_undo instead of leaving them orphaned. It only
        // finds the right rows because that table declares dynamicPtable.
        // Not tl_consho_key (F-6): Contao copies ctable children with the
        // product, and a copied pool sells every code twice. Its codes are
        // removed on delete by KeyPoolListener instead.
        //
        // The three join tables (shops, properties, variant options): so that
        // deleting a product takes them into tl_undo too, and undo brings them
        // back — before, they stayed behind as orphans (CW-3 acceptance,
        // 2026-10-11). Each declares doNotCopyRecords: ProductCopyListener
        // copies them, and must keep deciding that a copied variant leaves its
        // options behind.
        //
        // 🔴 NEVER this table itself, to have Contao delete the variants:
        // reviseTable() deletes every row of a ctable whose pid matches no
        // parent — for this table that is every main product (pid 0).
        // VariantDeleteListener takes the variants along instead.
        'ctable' => ['tl_consho_price', 'tl_consho_translation', 'tl_consho_product_shop', 'tl_consho_product_property', 'tl_consho_product_option'],
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',
                'alias' => 'index',
                'sku' => 'index',
                'published' => 'index',
                // For TaxClassDeleteVoter, which asks once per row while the
                // tax class list draws its delete buttons. The `LIMIT 1` ends
                // the scan at the first hit, so a class IN use answers quickly
                // either way — it is the UNUSED one, the one an operator is
                // about to delete, that would scan the whole catalogue.
                'taxClass' => 'index',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode' => 1,
            // Tie-break: two products may share a title, so the order would otherwise be
            // whatever MySQL finds cheapest. id is autoincrement and never ties.
            'fields' => ['title', 'id'],
            'flag' => 1,
            'panelLayout' => 'filter;search,limit',

            /*
             * Contao labels a record in the breadcrumb through
             * FallbackRecordLabelListener: it uses `defaultSearchField` if the
             * DCA names one, and otherwise falls back to the `edit` operation
             * label. Without this line the trail reads "Produkt ID 1
             * bearbeiten" instead of "Schwedenbitter" — and for tables whose
             * `edit` operation jumps to a child table, the fallback is plainly
             * wrong ("Werte der Eigenschaft ID 1 bearbeiten" while editing the
             * property itself).
             */
            'defaultSearchField' => 'title',
        ],
        'label' => [
            'fields' => ['title', 'sku'],
            'format' => '%s <span class="tl_gray">[%s]</span>',
        ],
        'operations' => [
            /*
             * A separate operation rather than repurposing `edit` as the jump
             * to the child table, which is what tl_consho_property_group and
             * tl_consho_tax_class do. Here the pencil keeps meaning "edit this
             * record" — the arrangement that would not have produced the
             * breadcrumb confusion those two tables did.
             */
            // `primary` keeps the jump in the row. Without it every operation
            // without the flag moves behind ⋯ as soon as ONE operation has it,
            // and Contao's named `edit` brings it along. In the core the jump
            // to the children is primary too (`children` in getDefaults()).
            'prices' => ['href' => 'table=tl_consho_price', 'icon' => 'header.svg', 'primary' => true],
            // F-6: the key pool. Only drawn for an article delivered from one
            // (KeyPoolListener) — a physical shop never sees it.
            'keys' => ['href' => 'table=tl_consho_key', 'icon' => 'lock.svg', 'primary' => true],
            'edit',
            '!copy',
            '!delete',
            'toggle',
            'show' => ['href' => 'act=show', 'icon' => 'show.svg', 'primary' => true],
            '!versions',
        ],
    ],

    'palettes' => [
        /*
         * ── Seven legends, and that is a limit rather than a taste ─────────
         *
         * Contao 5.7 renders legends as a tab strip and starts showing scroll
         * arrows beyond roughly seven. The palette had grown to NINE, one plan
         * at a time from A-2 to A-11, and nobody had ever looked at it whole.
         *
         * The grouping answers what an editor is doing, not which plan added
         * the field:
         *
         *   Produkt         what is it and where does it sit
         *   Kennzeichnung   which article, from whom  — sku, gtin, mpn AND the
         *                   manufacturer, which used to sit with the warnings
         *   Verkauf         shops, tax, units, minimum quantities
         *   Beschreibung    the text and the pictures, formerly two legends
         *   Sicherheit      what the buyer has to be told
         *   Versand         weight and measurements
         *   Veröffentlichung stays on its own: it is the last thing touched
         *                   before going live, not something to hunt for
         *                   between measurements
         *
         * The sale modes of the subscription block (Abo-1, A4) sit in the
         * delivery legend, not in a legend of their own: they are asked only
         * of a digital product or a service, and an eighth tab is past the
         * limit above. Which of the legend's fields show depends on the kind
         * of the product type (SaleModeListener, DL5).
         */
        '__selector__' => ['saleOnce', 'saleSubscription'],
        'default' => '{product_legend},title,alias,productType,pid,properties,variantOptions;{identification_legend},sku,gtin,mpn,manufacturer;{sales_legend},shops,taxClass,voucherValue,voucherValidMonths,unit,purchaseUnit,referenceUnit,minPurchase,purchaseSteps,stockTracked,stockBackorder,stockWarning,stockTarget,packUnit,supplier,stockQuantity,stockOpened,stockAvailableFrom,supplierSku;{description_legend},teaser,metaDescription,description,images;{safety_legend},minimumAge,safetyWarnings,safetyInformation,durabilityYears,durabilityLabel,durabilityNested;{shipping_legend},weight,shippingWeight,length,width,height;{digital_legend:hide},digitalDelivery,digitalFile,keyImport,saleOnce,saleSubscription;{publish_legend},published,start,stop',
    ],

    // Abo-1 (A4): the one-time sale shows its term, the subscription its rules.
    'subpalettes' => [
        'saleOnce' => 'entitlementMonths',
        'saleSubscription' => 'subscriptionRules',
    ],

    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        /*
         * Set `pid` and the product becomes a variant of that one. The options
         * and the guards live in ProductParentListener.
         */
        'pid' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy', 'table' => 'tl_consho_product'],
        ],
        'sorting' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
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
        'alias' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['rgxp' => 'alias', 'unique' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'sku' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],

        /*
         * ── The two numbers the trade identifies an article by ─────────────
         *
         * ⚠️ Per ARTICLE, and never inherited from the main product — unlike
         * the manufacturer. "Schwedenbitter 250 ml" and "750 ml" are two
         * articles with two EANs; an inherited GTIN would be a false statement,
         * not a convenience.
         *
         * No unique index on gtin on purpose. Two products sharing one is
         * almost always a mistake, but only almost — a shop may carry the same
         * article twice during a changeover. It is reported on the start page
         * instead. And an index here would be the trap from A-10 again: Contao
         * inserts empty rows when a record is created.
         *
         * GtinListener checks the check digit; see Gtin.
         */
        'gtin' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['maxlength' => 20, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 14, 'default' => ''],
        ],
        'mpn' => [
            'inputType' => 'text',
            'exclude' => true,
            'search' => true,
            'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'productType' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'foreignKey' => 'tl_consho_product_type.title',
            // The type's kind decides which delivery fields show (DL5).
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],

        /*
         * No `sql` key on purpose: the assignment lives in
         * tl_consho_product_shop, not in a column here. ProductShopsListener
         * fills the widget, writes the rows and returns null; `doNotSaveEmpty`
         * is what keeps DC_Table from putting the field name into its UPDATE.
         */
        'shops' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['multiple' => true, 'doNotSaveEmpty' => true, 'tl_class' => 'clr'],
        ],

        /*
         * What the article IS — filter information about the main product.
         * Written to tl_consho_product_property by ProductPropertiesListener;
         * no `sql` key, because there is no such column.
         *
         * Both fields live in the FIRST legend rather than a new one. A-12 cut
         * the palette to seven legends on purpose: Contao renders them as a tab
         * strip and shows scroll arrows from about eight. They belong there
         * anyway — with title, alias, type and pid they answer "what is this
         * record", and variantOptions sits next to the pid that makes it a
         * variant in the first place.
         */
        'properties' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['multiple' => true, 'doNotSaveEmpty' => true, 'tl_class' => 'clr'],
        ],

        /*
         * What makes THIS ROW a particular variant. A separate table from the
         * one above, and therefore a separate field: block A keeps them apart
         * because a forgotten `WHERE role = 'variant'` returns wrong data in
         * silence — a product whose variants are not variants.
         */
        'variantOptions' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['multiple' => true, 'doNotSaveEmpty' => true, 'tl_class' => 'clr'],
        ],

        /*
         * The product carries the class, never the rate. Rate follows from
         * class + delivery country + order date and is frozen on the order
         * item, which is what lets destination-based taxation and OSS arrive
         * later without a schema change.
         *
         * Mandatory on purpose: a product without a tax class cannot be priced
         * correctly for anyone. On an existing catalogue this means every
         * product has to be visited once — that is the intended cost, not an
         * oversight.
         */
        'taxClass' => [
            'inputType' => 'select',
            'exclude' => true,
            'foreignKey' => 'tl_consho_tax_class.title',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'teaser' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'search' => true,
            'eval' => ['tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        /*
         * The description search engines show (Michael, 2026-09-14). Empty
         * means the teaser, shortened by ProductHead; both empty leave the
         * page's own. Translatable, and it falls back like the teaser.
         *
         * Only a main product's counts. Like the teaser, the field stays in
         * the mask of a variant, and like the teaser, a variant's is never
         * read for the page head — a variant address is canonical to its
         * product.
         *
         * Eval as tl_page.description (core-bundle contao/dca/tl_page.php):
         * decodeEntities, a 60px textarea, and no maxlength — the core sets
         * none either, and ProductHead does not cut what an editor wrote.
         */
        'metaDescription' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'search' => true,
            'eval' => ['style' => 'height:60px', 'decodeEntities' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        /*
         * ── Product safety (GPSR) ─────────────────────────────────────────
         *
         * The manufacturer is a reference, because many articles share one and
         * a change of address has to reach all of them at once. Empty on a
         * variant means "same as the main product" — like the shop assignment
         * in A-3; ProductSafetyResolver does the inheriting.
         */
        'manufacturer' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'foreignKey' => 'tl_consho_manufacturer.title',
            'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],

        /*
         * ⚠️ Warnings belong per ARTICLE, not per product type. "Contains
         * alcohol" is true of the Schwedenbitter, not of every article of its
         * type.
         *
         * And they are not displayed with the manufacturer block: a warning has
         * to stand next to the goods, never behind a tab. See
         * ProductSafetyResolver.
         */
        /*
         * A number, not a switch. A yes/no could not tell 16 from 18 — which
         * is the difference between beer and spirits.
         *
         * A-12 records it and shows it. Age verification at checkout and
         * identity-checked delivery are Block C: they need a purchase, and
         * there is none yet.
         */
        'minimumAge' => [
            'inputType' => 'select',
            'exclude' => true,
            'filter' => true,
            'options' => [0, 6, 12, 14, 16, 18],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_product']['minimumAges'],
            'eval' => ['tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        'safetyWarnings' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'search' => true,
            'eval' => ['rte' => 'tinyMCE', 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'safetyInformation' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'search' => true,
            'eval' => ['rte' => 'tinyMCE', 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],

        /*
         * The manufacturer's commercial durability guarantee (block GARAN,
         * § 4 Abs. 1 Z 12a FAGG): its duration and the harmonised label
         * (Durchführungsverordnung (EU) 2025/1960, Anhang II), filled in by
         * the manufacturer or the operator and uploaded as an image (GA1).
         * Main product and physical goods only (GA2, GA5) —
         * DurabilityGuaranteeListener hides and refuses the rest. Only a
         * guarantee of more than two years carries a label, so 1 and 2 are
         * refused; 0 means none.
         *
         * PNG or JPG, not SVG: the label goes into the order confirmation,
         * and mail programmes strip SVG (GA4).
         */
        'durabilityYears' => [
            'inputType' => 'text',
            'exclude' => true,
            // Whole or half years (GA9): checked by DurabilityGuaranteeListener.
            // No `filter`: DC_Table's filter menu skips the load callback and
            // showed MySQL's "5.0" (review GARAN part 2, 2) — and the findings
            // already list every product with a guarantee that needs care.
            'eval' => ['maxlength' => 4, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'decimal', 'precision' => 3, 'scale' => 1, 'default' => '0.0'],
        ],
        'durabilityLabel' => [
            'inputType' => 'fileTree',
            'exclude' => true,
            'eval' => ['filesOnly' => true, 'fieldType' => 'radio', 'extensions' => 'png,jpg,jpeg', 'tl_class' => 'w50'],
            'sql' => ['type' => 'binary', 'length' => 16, 'notnull' => false],
        ],
        /*
         * The nested label (Commission guidelines, April 2026, 3.3.1; GA8,
         * Michael, 2026-10-10: "GA8a"): the compact element with only "XX"
         * filled in, for space-limited places — the tiles of a listing. It
         * links to the full label, which must appear "on the first mouse
         * click, mouse roll-over or tactile screen expansion". Digital only,
         * never in a mail: SVG is fine here. Optional, and shown only with a
         * full label to open.
         */
        'durabilityNested' => [
            'inputType' => 'fileTree',
            'exclude' => true,
            'eval' => ['filesOnly' => true, 'fieldType' => 'radio', 'extensions' => 'png,jpg,jpeg,svg', 'tl_class' => 'w50'],
            'sql' => ['type' => 'binary', 'length' => 16, 'notnull' => false],
        ],

        'description' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'search' => true,
            'eval' => ['rte' => 'tinyMCE', 'tl_class' => 'clr'],
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        // From Contao's file manager. Consho creates no image folders of its
        // own — that was Isotope's original sin and the thing two commenters
        // asked them to change without an answer.
        'images' => [
            'inputType' => 'fileTree',
            'exclude' => true,
            /*
             * `isSortable` is what gives the drag handles. Without it the
             * order is whatever the file manager returns — and on a product
             * the FIRST image is the one that ends up in the listing, in the
             * cart and in the JSON-LD. That is a decision about the article,
             * not about the file system.
             */
            'eval' => [
                'multiple' => true,
                'fieldType' => 'checkbox',
                'filesOnly' => true,
                'isGallery' => true,
                'isSortable' => true,
                'extensions' => '%contao.image.valid_extensions%',
                'tl_class' => 'clr',
            ],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        'weight' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'shippingWeight' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'length' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w33'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'width' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w33'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'height' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w33'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],

        /*
         * Digital delivery (F-4). Read only for an article whose product type
         * is not a physical good (F13); a variant's own value where it has
         * one, otherwise its main product's (DigitalProductSettings).
         *
         * digitalDelivery: HOW it is redeemed — the key of a delivery handler
         * (F8), options from DigitalDeliveryListener. Empty: the entitlement
         * alone.
         * entitlementMonths: FOR HOW LONG, 0 for permanent (F1). What the end
         * stops — updates or use — is the product's business, not Consho's.
         */
        'digitalDelivery' => [
            'inputType' => 'select',
            'exclude' => true,
            'eval' => ['includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        /*
         * The file behind the delivery type "Datei" (F-5, F18). Only from a
         * protected folder — DigitalDeliveryListener refuses a public one.
         */
        'digitalFile' => [
            'inputType' => 'fileTree',
            'exclude' => true,
            'eval' => ['filesOnly' => true, 'fieldType' => 'radio', 'tl_class' => 'clr w50'],
            'sql' => ['type' => 'binary', 'length' => 16, 'notnull' => false],
        ],
        /*
         * The key pool behind the delivery type "Schlüssel" (F-6, F19). No
         * `sql` key: pasted codes go into tl_consho_key (KeyPoolListener).
         * From how many codes left it warns is stockWarning since Block G
         * (G11) — one threshold for kept stock and key pools.
         */
        'keyImport' => [
            'inputType' => 'textarea',
            'exclude' => true,
            'eval' => ['doNotSaveEmpty' => true, 'decodeEntities' => true, 'tl_class' => 'clr'],
        ],
        'entitlementMonths' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        /*
         * Block Wertgutscheine (WG3): what a voucher of this article is worth,
         * per article — 25, 50, 100 € as variants. The price says what it
         * costs; the two differ only where the operator wants them to (a
         * 50 € voucher sold for 45 €). Empty: the voucher is worth what was
         * paid for it, and the finding says the value is not set.
         * Shown only for the kind "Wertgutschein" (SaleModeListener).
         */
        'voucherValue' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['conshoMoney' => true, 'rgxp' => 'digit', 'maxlength' => 12, 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => '0.0000'],
        ],
        // Months a voucher of this product stays valid; 0 = unlimited (WG10).
        // At the main product only, like entitlementMonths.
        'voucherValidMonths' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'maxlength' => 3, 'tl_class' => 'w50'],
            'sql' => ['type' => 'smallint', 'unsigned' => true, 'default' => 0],
        ],
        /*
         * What the article is sold as (Abo-1, A4). Two ticks, not a list:
         * both at once is the ordinary case of a theme bought for good or
         * rented by the year. saleOnce defaults to ON, so contao:migrate turns
         * every existing product into a one-time sale — which is what it was
         * (plan P1). The ticks count for a digital article only; a physical
         * one is always sold once (SaleModes). Read from the main product and
         * hidden at variants (SaleModeListener, P2).
         */
        'saleOnce' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['submitOnChange' => true, 'tl_class' => 'w50 clr'],
            'sql' => ['type' => 'boolean', 'default' => true],
        ],
        'saleSubscription' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['submitOnChange' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        /*
         * The rules on offer, in this order on the product page (Abo-2). A
         * checkboxWizard, like the shop's methods (C2): it stores the ticked
         * ids in the order dragged. A chosen select stored them in the order
         * of its options — alphabetical —, whatever order they were picked in
         * (seen on c5 in the Abo-1 acceptance).
         */
        'subscriptionRules' => [
            'inputType' => 'checkboxWizard',
            'exclude' => true,
            'foreignKey' => 'tl_consho_subscription_rule.title',
            'eval' => ['multiple' => true, 'mandatory' => true, 'tl_class' => 'clr'],
            'sql' => ['type' => 'blob', 'notnull' => false],
        ],
        'unit' => [
            'inputType' => 'select',
            'exclude' => true,
            'options' => ['kg', 'g', 'l', 'ml', 'm', 'cm', 'm2', 'stk'],
            'reference' => &$GLOBALS['TL_LANG']['tl_consho_product']['units'],
            'eval' => ['includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 8, 'default' => ''],
        ],
        'purchaseUnit' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'referenceUnit' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'minPurchase' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '1.0000'],
        ],
        'purchaseSteps' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '1.0000'],
        ],
        /*
         * Stock (Block G). The three settings at the MAIN product of a
         * physical good, read for all its articles: whether stock is kept
         * (G1, off: unlimited as before), whether it sells on below zero
         * (G6), and from how much it reads "nur noch wenige" and warns (G7,
         * G11). StockFieldsListener shows them where they apply.
         */
        'stockTracked' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['submitOnChange' => true, 'tl_class' => 'clr w50 m12'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        'stockBackorder' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'eval' => ['tl_class' => 'w50 m12'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        'stockWarning' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        /*
         * Purchasing (G-7), at the main product like the settings above —
         * only in the back end, the customer sees none of it (L2: "Zielbestand
         * geht den Kunden nichts an"):
         *  - stockTarget: the Sollbestand a purchase fills up to;
         *  - packUnit: pieces per pack the supplier sells (VPE, E2); 1 = single
         *    pieces. Not the customer's purchase unit or steps above;
         *  - supplier: free text with the names already in use as suggestions,
         *    matched on save (E5 a — Michael: "ich will aber vermeiden, dass
         *    ein und der selbe Lieferant mehrere Schreibweisen bekommt").
         * supplierSku at each ARTICLE: the supplier's number for exactly it, so
         * a process outside Consho can order without guessing (L4).
         */
        'stockTarget' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'tl_class' => 'w50'],
            'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4, 'default' => '0.0000'],
        ],
        'packUnit' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'natural', 'minval' => 1, 'tl_class' => 'w50'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 1],
        ],
        'supplier' => [
            'inputType' => 'conshoSuggest',
            'exclude' => true,
            'search' => true,
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'supplierSku' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['maxlength' => 64, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        /*
         * The stock of THIS article — no column: it lives in tl_consho_stock
         * and changes only by a booking (G2). The field shows the figure; the
         * hidden stockOpened carries what it showed when the mask opened, and
         * saving books the difference (G3), so a sale in between is not
         * overwritten. Callbacks in StockFieldsListener.
         */
        'stockQuantity' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'digit', 'doNotSaveEmpty' => true, 'tl_class' => 'clr w50'],
        ],
        'stockOpened' => [
            'exclude' => true,
        ],
        /*
         * ⚠️ doNotSaveEmpty on both column-less fields (review G, B1): their
         * save_callback returns null, and without the flag DC_Table writes
         * the empty value into tl_consho_product — a column that does not
         * exist, and every save of the mask failed.
         */
        'stockAvailableFrom' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'date', 'datepicker' => true, 'doNotSaveEmpty' => true, 'tl_class' => 'w50 wizard'],
        ],
        // Deliberately `text`, not a native `json` column: whether Contao's
        // schema generator migrates one cleanly is unverified, and a column
        // type that fails during contao:migrate blocks the whole rollout.
        // CustomFieldCodec encapsulates the encoding, so switching later is a
        // migration of one column, not a change to the code.
        //
        // No input of its own: CustomFieldsListener adds one field per custom
        // field of the product type (`cf_<alias>`). `exclude` makes the column
        // grantable in the user group — the generated fields cannot be, the
        // group form never sees them — and they take its permission (ZW-2).
        'customFieldValues' => [
            'exclude' => true,
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'published' => [
            'inputType' => 'checkbox',
            'exclude' => true,
            'toggle' => true,
            'filter' => true,
            'eval' => ['doNotCopy' => true, 'tl_class' => 'w50 m12'],
            'sql' => ['type' => 'boolean', 'default' => false],
        ],
        /*
         * `clr` on the FIRST date field, not on the checkbox: it breaks the
         * row so that "Anzeigen ab" and "Anzeigen bis" stand side by side.
         * Without it the checkbox takes the left half, one date takes the
         * right, and the second date drops to a row of its own — the pair that
         * belongs together is the pair that gets split.
         */
        'start' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'datim', 'datepicker' => true, 'tl_class' => 'w50 wizard clr'],
            'sql' => ['type' => 'string', 'length' => 10, 'default' => ''],
        ],
        'stop' => [
            'inputType' => 'text',
            'exclude' => true,
            'eval' => ['rgxp' => 'datim', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => ['type' => 'string', 'length' => 10, 'default' => ''],
        ],
    ],
];
