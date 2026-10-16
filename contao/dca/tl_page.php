<?php declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

/*
 * Root pages select a shop configuration. n root pages : 1 shop — a
 * multilingual site is several root pages in Contao, but one shop, and its
 * order numbers have to be shared. See docs/block-a-fundament.md, section 4.
 *
 * ⚠️ Both palettes are needed: Contao uses "rootfallback" for the root page
 * marked as the language fallback. Serving only "root" loses the field on the
 * main language of every site.
 *
 * ⚠️ consho_currency comes AFTER consho_shop, and that order is functional.
 * DC_Table::edit() walks the palette fields in sequence and only puts a value
 * into arrSubmit once its own field has been processed. Ahead of the shop, the
 * currency callbacks would read the shop the record had BEFORE this save.
 */
PaletteManipulator::create()
    ->addLegend('consho_legend', 'publish_legend', PaletteManipulator::POSITION_BEFORE)
    ->addField('consho_shop', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_currency', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_cartPage', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_checkoutPage', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_orderPage', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_shippingCostsPage', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_withdrawalFormPage', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_cancellationPage', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_subscriptionsPage', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_termsElement', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_withdrawalElement', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_privacyPage', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_warrantyPage', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->addField('consho_warrantyNotice', 'consho_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('root', 'tl_page')
    ->applyToPalette('rootfallback', 'tl_page')
;

$GLOBALS['TL_DCA']['tl_page']['fields']['consho_shop'] = [
    'inputType' => 'select',
    'exclude' => true,
    'foreignKey' => 'tl_consho_shop.title',
    'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
    'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
];

/*
 * The currency that applies below this start page (C-1c, spec 4.0).
 *
 * The shop says which currencies it can accept, the start page picks the one
 * for its audience — like the language, only for money. See RootCurrencyListener
 * for the options, the refusal and why the field disappears when the shop
 * accepts a single currency.
 *
 * ⚠️ No `doNotCopy`, deliberately. DC_Table::copy() carries over every field
 * that is not doNotCopy, unique, fallback or password; in tl_page only alias,
 * language, fallback, disableLanguageRedirect, robotsTxt, urlPrefix and
 * published opt out. So a duplicated start page brings its currency along —
 * which is exactly how a second currency site is set up (spec, rollout).
 *
 * `mandatory` although the field is often hidden: the flag only bites where
 * there is a widget, i.e. where the shop really offers a choice. A start page
 * whose shop accepts several currencies must not stay on none of them — that
 * would be the silent empty state spec section 7 rules out.
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['consho_currency'] = [
    'inputType' => 'select',
    'exclude' => true,
    'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 3, 'default' => ''],
];

/*
 * The basket page of this site (C13). It lives on the root page, like
 * consho_shop: there is exactly one per site, and every "added to basket"
 * redirect ends here.
 *
 * C-6 added the terms, withdrawal and privacy texts plus the order page to the
 * same legend.
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['consho_cartPage'] = [
    'inputType' => 'pageTree',
    'exclude' => true,
    'foreignKey' => 'tl_page.title',
    'eval' => ['fieldType' => 'radio', 'tl_class' => 'clr'],
    'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * The checkout page of this site (C13). On the root page for the same reason
 * as the basket: one per site, and the basket links here.
 *
 * A PAGE, not an element: the number of steps is set on the element, but the
 * step travels in the address, and a step needs a page to be an address of.
 * Which element sits on it is the operator's business — Consho only warns when
 * none does (11.3).
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['consho_checkoutPage'] = [
    'inputType' => 'pageTree',
    'exclude' => true,
    'foreignKey' => 'tl_page.title',
    'eval' => ['fieldType' => 'radio', 'tl_class' => 'clr'],
    'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * Where a buyer lands after the purchase, and where the link in the
 * confirmation mail points (C21).
 *
 * The FIELD is here although the page itself is C-10: the finding "root page
 * without an order page" sits in 11.3 and blocks the checkout, and C-6 is the
 * plan that builds the findings from 11.3. A checkout that completes with
 * nowhere to send the buyer is not finished, whatever the order page later
 * shows on itself.
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['consho_orderPage'] = [
    'inputType' => 'pageTree',
    'exclude' => true,
    'foreignKey' => 'tl_page.title',
    'eval' => ['fieldType' => 'radio', 'tl_class' => 'clr'],
    'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * The page with the element "Versandkosten" (E-6, V5). Checked like the
 * basket and order pages, but it does not block the checkout: which
 * statements about shipping a shop owes is the operator's to judge.
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['consho_shippingCostsPage'] = [
    'inputType' => 'pageTree',
    'exclude' => true,
    'foreignKey' => 'tl_page.title',
    'eval' => ['fieldType' => 'radio', 'tl_class' => 'clr'],
    'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * The page carrying the element "Vertrag widerrufen" (D-2, W1) — the way in
 * for a buyer without the mail.
 *
 * ⚠️ Not `consho_withdrawalElement` below: that one is the withdrawal
 * INSTRUCTION, a legal text frozen at the buy click. This is the FUNCTION.
 * Two things the law keeps apart, and so do the names.
 *
 * A page like the order page, so the finding "element missing" can look for
 * the element on it, and a mail or a footer can link to it.
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['consho_withdrawalFormPage'] = [
    'inputType' => 'pageTree',
    'exclude' => true,
    'foreignKey' => 'tl_page.title',
    'eval' => ['fieldType' => 'radio', 'tl_class' => 'clr'],
    'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * The page with "Verträge hier kündigen" (Abo-7, § 312k BGB). A page like
 * "Vertrag widerrufen", so the finding can look for the element on it
 * (CancellationPageIssueSource, P63).
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['consho_cancellationPage'] = [
    'inputType' => 'pageTree',
    'exclude' => true,
    'foreignKey' => 'tl_page.title',
    'eval' => ['fieldType' => 'radio', 'tl_class' => 'clr'],
    'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * The page with "Meine Abos" (Abo-7b, P71) — optional. The reminder before a
 * subscription that ends links there for "Lizenz verlängern"; without it the
 * reminder links the product page, as before.
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['consho_subscriptionsPage'] = [
    'inputType' => 'pageTree',
    'exclude' => true,
    'foreignKey' => 'tl_page.title',
    'eval' => ['fieldType' => 'radio', 'tl_class' => 'clr'],
    'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * The terms and the withdrawal instruction (C17), each as ONE content element
 * of type "text" somewhere below this root page.
 *
 * An ELEMENT, not a page, and that is the difference to everything else in
 * this legend: at the buy click exactly one element is frozen into
 * tl_consho_order_legal with its checksum. A page holding three text elements
 * would leave open which of them the buyer agreed to.
 *
 * ⚠️ One field, not a page field plus an element field. A dependent element
 * list has to be refilled once the page is picked, which needs
 * `submitOnChange` — and a mask that reloads while it is being filled in loses
 * unsaved input in its neighbours (the reason C-1b left it out of the price
 * mask). The page is known either way, only derived the other way round:
 * tl_content -> tl_article -> tl_page.
 *
 * Whether the element is actually reachable on a published page is not the
 * picker's business — DbalLegalReadinessIssueSource reports it (11.3).
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['consho_termsElement'] = [
    'inputType' => 'select',
    'exclude' => true,
    'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50 clr'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

$GLOBALS['TL_DCA']['tl_page']['fields']['consho_withdrawalElement'] = [
    'inputType' => 'select',
    'exclude' => true,
    'eval' => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * The privacy page (9). A PAGE, not an element, and nothing is frozen from it:
 * the order rests on the contract, not on consent, so the privacy statement is
 * linked and never agreed to. Consho only warns when the link would go
 * nowhere.
 */
/*
 * The harmonised notice on the legal guarantee (§ 4 Abs 1 Z 12 FAGG, in force
 * for contracts from 27.09.2026; Art. 2 RL (EU) 2024/825, layout binding in
 * Durchführungsverordnung (EU) 2025/1960).
 *
 * ⚠️ Consho does NOT ship the notice, and that is a decision, not an omission
 * (Michael, 2026-09-22: "ich bin dagegen, dass wir Rechtstexte setzen, aber
 * alles technisch Notwendige müssen wir bereitstellen"). The notice is an A4
 * poster the Commission publishes per EU language, and it may not be altered,
 * cropped or re-coloured — a bundle that bundled one language's file would
 * either be wrong for the next shop or silently outdated when the Commission
 * republishes. The operator puts the page up; Consho links it and says when it
 * is missing.
 *
 * A PAGE like the privacy one, and per START POINT rather than per shop: the
 * notice exists in every EU language and has to appear in the shop's, which is
 * a property of the audience, not of the invoicing entity.
 *
 * The guidelines (2.3 "Digital display") name the checkout as one of the
 * acceptable places, next to the header and the catalogue — so a link from
 * here satisfies them. What they also want, and what C-9 owes: the notice IN
 * THE ORDER CONFIRMATION, as an attachment rather than a linked image.
 * Established at a partner shop on 2026-09-17: a referenced image is blocked
 * by mail clients and does not travel with the message, while § 7 Abs 3 FAGG
 * wants the § 4 information on a durable medium.
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['consho_warrantyPage'] = [
    'inputType' => 'pageTree',
    'exclude' => true,
    'foreignKey' => 'tl_page.title',
    'eval' => ['fieldType' => 'radio', 'tl_class' => 'clr'],
    'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * The harmonised guarantee notice as a FILE, attached to every order
 * confirmation of this start point (C-9).
 *
 * Checked at the primary text on 2026-09-25: the confirmation on a durable
 * medium carries all of Art. 6 Abs. 1 (Art. 8 Abs. 7 lit. a Directive
 * 2011/83/EU), and Art. 6 Abs. 1 lit. l, as amended by Directive (EU)
 * 2024/825, asks for the guarantee right "unter Verwendung der ...
 * harmonisierten Mitteilung". In Austria § 4 Abs 1 Z 12 and § 7 Abs 3 FAGG
 * (version of 27.09.2026). None of its elements may be altered, and online it
 * has to be in colour, RGB (Implementing Regulation (EU) 2025/1960, Annex I).
 *
 * A file rather than the page above: a mail cannot carry a page, and a
 * linked image is not in the mail (a partner shop, 2026-09-17). Per start
 * point for the same reason as the page — the notice exists per language.
 * Consho does not ship it (C-6, Michael 2026-09-22): it is an official
 * document the operator takes from the Commission unchanged.
 */
$GLOBALS['TL_DCA']['tl_page']['fields']['consho_warrantyNotice'] = [
    'inputType' => 'fileTree',
    'exclude' => true,
    'eval' => ['filesOnly' => true, 'fieldType' => 'radio', 'extensions' => 'pdf,png,jpg,jpeg', 'tl_class' => 'clr'],
    'sql' => ['type' => 'binary', 'length' => 16, 'notnull' => false],
];

$GLOBALS['TL_DCA']['tl_page']['fields']['consho_privacyPage'] = [
    'inputType' => 'pageTree',
    'exclude' => true,
    'foreignKey' => 'tl_page.title',
    'eval' => ['fieldType' => 'radio', 'tl_class' => 'clr'],
    'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

/*
 * The product detail page.
 *
 * The type itself is registered by ProductPageController via #[AsPage]; Contao
 * merges the registry into the type list (PageTypeOptionsListener), so nothing
 * has to be added to $GLOBALS['TL_PTY'].
 *
 * ⚠️ No `requireItem` in this palette, and that is not an omission. The flag
 * only turns the DEFAULT route's optional `{!parameters}` into a mandatory one.
 * Our route names its own segments, so the bare page already matches no route.
 * Offering the flag would suggest it could be switched off.
 *
 * `routePath` IS in the palette: it is Contao's read-only preview of the
 * computed path, and it is the quickest way for the operator to see what the
 * template produced.
 */
$GLOBALS['TL_DCA']['tl_page']['palettes']['consho_product'] =
    '{title_legend},title,type'
    .';{routing_legend},alias,conshoPathTemplate,routePath,routePriority,routeConflicts'
    .';{meta_legend},pageTitle,robots'
    .';{protected_legend:hide},protected'
    .';{layout_legend:hide},includeLayout'
    .';{cache_legend:hide},includeCache'
    .';{chmod_legend:hide},includeChmod'
    .';{expert_legend:hide},cssClass,hide,guests'
    .';{publish_legend},published,start,stop'
;

$GLOBALS['TL_DCA']['tl_page']['fields']['conshoPathTemplate'] = [
    'inputType' => 'text',
    'exclude' => true,

    /*
     * Relative to the page, because that is what Contao supports: a path
     * without a leading slash is prefixed with the page alias in
     * PageRoute::__construct. See Seo\ProductPathTemplate.
     *
     * Single braces are safe — Contao's insert tags use double ones, so
     * "{alias}" passes through untouched. No `decodeEntities`: it is forbidden
     * in this bundle and would be pointless here, braces are not encoded.
     */
    'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50 clr'],
    'sql' => ['type' => 'string', 'length' => 255, 'default' => '{alias}'],
];

/*
 * Facet landing pages hang off a listing page (tl_consho_facet_page.pid), but
 * tl_consho_facet_page is deliberately NOT a child table of tl_page.
 *
 * ⚠️ A ctable entry would make DC_Table::reviseTable() delete every landing
 * page whose pid is not an existing page, each time the site structure is
 * listed and without looking at tstamp. Clicking "new" in the landing page
 * module creates a row with pid 0 at once — a colleague opening the site
 * structure in the meantime would delete the half-finished record.
 *
 * Deleting a page still takes its landing pages along, with undo:
 * FacetPageDeleteListener hangs off ondelete_callback and appends them to the
 * tl_undo entry. Copying a page does not copy them, because copyChildren()
 * only walks ctable.
 */
