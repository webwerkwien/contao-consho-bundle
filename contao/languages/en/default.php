<?php declare(strict_types=1);

/*
 * Sentences for the findings of ConfigurationCheck and DeliverabilityChecker.
 *
 * The checking classes return codes and the values to fill in — the wording
 * belongs here, so that it stays translatable and the checkers need to know
 * nothing about Contao.
 */
$GLOBALS['TL_LANG']['CONSHO']['findings'] = [
    'taxRateMissing' => 'The shop configuration "%shop%" has not recorded how the group "%taxClass%" is taxed in %country%. Without an entry orders from that country fail at checkout; if it is the shop\'s home country, it shows no prices for the group.',
    'homeCountryMissing' => 'The shop configuration "%shop%" has no home country. Without one there is no gross price: the shop shows no prices in listings or on product pages. Enter it under "Countries" in the shop configuration.',
    'duplicateVatId' => 'Two shop configurations carry the same VAT ID %vatId%: %shops%. If this is one shop in several languages it belongs on ONE configuration — otherwise two separate invoice number sequences are running.',
    'mailTransportMissing' => 'The shop configuration "%shop%" has no mail configuration. No order confirmations can be sent.',
    'productManufacturerMissing' => 'Physical products without a manufacturer: %count%. The EU product safety regulation requires the manufacturer details on every single offer.',
    'manufacturerEmailMissing' => 'The manufacturer "%manufacturer%" has no e-mail address. A website is not enough — an address allowing direct communication is required.',
    'duplicateGtin' => 'The GTIN %gtin% is recorded on %count% products. A GTIN normally names exactly one article.',
    'manufacturerEuResponsibleMissing' => 'The manufacturer "%manufacturer%" is based outside the EU (%country%) and has no responsible person inside the Union. Without one, their products may not be offered here.',
    'translationMissing' => 'Published products without a translation with an alias in the shop "%shop%" for the language "%language%": %count%. Without a translation a product does not appear there.',
    'translationSafetyMissing' => 'Translated, published products without safety notices in the shop "%shop%" for the language "%language%": %count%. Such a product is invisible there: the notices have to be in the language of the market and are therefore not taken from the default language.',
    'listPriceDeviation' => 'List prices for filtering and sorting that do not match the prices: %count%. Affected products are missing from the product list or appear with a wrong price. Fix with the console command consho:price:rebuild; consho:price:verify shows the details.',
    'visibilityDeviation' => 'Entries for product listings and filters that do not match the catalogue: %count%. Affected products are missing from listings, appear in them wrongly or with a wrong "from" price. Fix with the console command consho:visibility:rebuild; consho:visibility:verify shows the details.',
    'pinnedProductMissing' => 'Pins in product listings whose product no longer exists or has since become a variant: %count%. They do not appear on the website. Delete them in the listing or choose another product.',
    'productShopMissing' => 'Published products assigned to no shop: %count%. They appear in no listing and have no product page. Assign them under "Shops" at the product.',
    'productPriceMissing' => 'Visible products without a price in %currency% in the shop "%shop%": %count%. They appear in no product listing and not in the sitemap, and their product page shows no price. Whether a product with a price stands in no listing is shown by consho:catalog:verify.',
    'priceTie' => 'Articles with two price rows of equal rank at the same time (same currency, customer group and threshold, overlapping validity): %count%. Which price applies is then random. Limit or delete one of the rows.',
    'pathTemplateInvalid' => 'Product pages with an invalid address template: %count% (%pages%). The template was stored past the back end; the page uses the default template {alias} instead, and its products answer under other addresses than configured. Save a valid template in the page settings.',
    'shippingPriceMissing' => 'The shipping method "%method%" has no saved amount in %currency%. It is therefore not offered at checkout in the shop "%shop%". Add an amount in %currency% under "Shipping methods" or deselect the method in the shop configuration.',
    'rootWithoutCurrency' => 'The start page "%page%" belongs to the shop configuration "%shop%" but has no currency. Below it the shop shows no price at all and no product appears in a listing — a price without a currency is not a statement. Pick a currency under "Consho" on the start page; the shop\'s currencies are on offer there.',
    'rootCurrencyNotSold' => 'The start page "%page%" sells in %currency%, but the shop configuration "%shop%" does not accept that currency (any more). Visitors see prices there that cannot be collected: there are no shipping amounts in %currency%. Either allow %currency% in "%shop%" again or pick one of the accepted currencies on the start page.',
    'currencyMissing' => 'No start page of the shop configuration "%shop%" carries a currency. The shop therefore shows no price anywhere and no product appears in a listing. Pick a currency on a start page of this shop — or, if none points at it yet, create a start page and assign the shop to it.',
    'multipleShopsShareMembers' => 'This installation has more than one shop configuration: %shops%. They all work with the same member administration — customers, addresses and VAT ids are shared. That presupposes one and the same controller within the meaning of the GDPR behind every shop; two unrelated companies need two separate installations. Once that is settled there is nothing to do: several shops are intended, and this note blocks nothing.',
    'miniCartMissing' => 'The shop configuration "%shops%" sends visitors back to the page after adding, but there is no mini basket element anywhere. The visitor then has no way of seeing that something landed in the basket. Add a mini basket element to a layout section or an article, or set "After adding" to "to the basket".',
    'groupPriceAboveList' => 'Customer group prices that are higher than the price for everyone: %count% (%examples%). Members of those groups pay more there than a guest does — that is how it is set up, and the shop is following it: a customer group price takes precedence over a bulk discount whatever the amounts. If it is intended (single units for a group where the public price is a pallet rate), there is nothing to do. Otherwise the price for everyone was usually lowered later while the group price stayed where it was.',
    'checkoutPageMissing' => 'Start points with a shop but no checkout page: %count% (%pages%). The basket then leads nowhere, and the checkout of that shop is blocked. The page is set on the start point, in the "Consho" legend.',
    'checkoutElementMissing' => 'Checkout pages without a checkout element: %count% (%pages%). The page is set but empty — put a "Checkout" content element into an article on that page. Until then the checkout of that shop is blocked.',
    'shippingMethodMissing' => 'No shipping possible in: %shops%. Either none is selected on the shop, or every selected one is unpublished. Without a shipping method nobody can order.',
    'paymentMethodMissing' => 'No payment possible in: %shops%. Either none is selected on the shop, or every selected one is unpublished. Without a payment method nobody can order.',
    'ageGateMissing' => 'Published articles from 16 or 18 that nobody can order: %count% (%shop%). For those levels the shop needs either a customer group with a verified age or a shipping method that checks at hand-over — either one is enough. With neither configured the checkout refuses every such order, and the article sits on the shelf unbuyable.',
    'catalogPageAlwaysCached' =>'These pages are set to "always load from cache": %pages%. This installation prices per customer somewhere (net price groups or customer group prices), and with that setting a logged-in member is handed the page a guest left in the cache: gross tiles saying "incl. VAT" beside a mini basket counting in net. Check whether any of these pages — or a page below one of them, which inherits the setting — shows product prices, and untick the box on the page named here.',
    'netPriceGroupMissing' => 'The shop configuration "%shop%" shows net prices to a customer group that no longer exists or has been disabled (id %groups%). The entry is skipped — members who used to be in that group now read gross prices. Your customers will notice, you will not. Remove the entry from "Customer groups with net prices", or select the group that replaced it.',
    'cartPageMissing' => 'Start points with a shop but no basket page: %count% (%pages%). Adding an article then leads nowhere, and the checkout of this shop is blocked. The page is set on the start point, in the "Consho" legend.',
    'cartElementMissing' => 'Basket pages without a basket element: %count% (%pages%). The page is set on the start point but empty — adding an article lands the visitor on a blank page. Put a "basket" content element into an article of that page.',
    'orderPageMissing' => 'Start points with a shop but no order page: %count% (%pages%). After the purchase the buyer would have no page showing their order, and the link in the confirmation mail would go nowhere. Create a page for it and select it on the start point.',
    'orderElementMissing' => 'Order pages without an "order page" element: %count% (%pages%). The page is set on the start point but empty — after the purchase and through the link in the confirmation mail the buyer would see nothing. Put an "order page" content element into an article of that page.',
    'legalTextMissing' => 'Start points without a legal text: %count% (%pages%) — missing: %kinds%. Terms are required where "Require a terms tick box" is on (whoever has to agree needs a text), the withdrawal instruction where the shop is established in the EU or the EEA and has a withdrawal period — without it the period does not begin (§ 12 FAGG, § 356 Abs. 3 BGB). Select a content element of type "text" on the start point.',
    'legalTextAdvised' => 'Note on legal texts: %count% start points (%pages%) — affected: %kinds%. Either terms are selected that no buyer can read, or a shop outside the EU and the EEA with a withdrawal period has no withdrawal instruction. The checkout stays open; whether the text is needed is your decision.',
    'withdrawalFunctionMissing' => 'Start points without a reachable "withdraw from contract" function: %count% (%pages%). In Germany since 19.06.2026 (§ 356a BGB), in Austria from 01.10.2026 (§ 13a FAGG), a shop has to offer the withdrawal function, easily accessible, for the whole withdrawal period (Art. 11a Directive 2011/83/EU) — to buyers without the order mail as well. Create a page with the "withdraw from contract" content element, publish it and select it on the start point under ""Withdraw from contract" page". Best link it from every page, in the footer for instance.',
    'withdrawalFunctionAdvised' => 'Start points without a "withdraw from contract" function: %count% (%pages%). The shop has a withdrawal period but is established outside the EU and the EEA; the checkout stays open. Selling to consumers in the EU may still oblige you to offer it. If so, create a page with the "withdraw from contract" element and select it on the start point.',
    'withdrawalsOpen' => 'Open withdrawals in the shop "%shop%": %count% (%numbers%). The first refund falls due on %due% at the latest (14 days from receipt). Process them under "Withdrawals".',
    'retentionPeriodMissing' => 'The shop configuration "%shop%" has no retention period. Without one nothing is anonymised, and an erasure request can only be noted. Enter the period in the section "Retention and data protection".',
    'anonymizationDue' => 'In the shop "%shop%" the retention period of %count% orders ends within the next 30 days (%numbers%). Their customer data will then be anonymised automatically; numbers and amounts remain.',
    'customerGroupMissing' => 'The shop configuration "%shop%" has no base group for its customers, or the chosen group no longer exists. Accounts without an order are therefore missing from "Customers". Choose the group in the section "Customer groups".',
    'automaticStatusCancels' => 'In the shop configuration "%shop%", "%field%" is set to the status "%status%", which cancels. This change never happens: after a delivery an order may no longer be cancelled, and after a payment a cancelled order would be overpaid. Choose another status or leave the field empty.',
    'statusContradictsBalance' => 'Orders in the shop "%shop%" in a status their payments and deliveries no longer bear: %count% (%numbers%) — "paid", say, while something is open or refunded. Consho does not change a status by itself. Check the orders and set the fitting status.',
    'shippingCostsPageMissing' => 'Start points without a shipping costs page: %count% (%pages%). The element "Shipping costs" shows the shipping methods with prices from the data; which statements the shop owes about them is for you to judge.',
    'shippingCostsElementMissing' => 'Shipping costs pages without the element "Shipping costs": %count% (%pages%). The page is set at the start point but shows no shipping costs.',
    'deliveryCountryWithoutShipping' => 'The shop configuration "%shop%" lists %countries% as delivery countries, but none of its shipping methods delivers there. The checkout therefore does not offer them. Add the countries to a shipping method or remove them from the delivery countries.',
    'productKindMismatch' => 'Product "%product%" is %kind% according to its product type, but has %fields%. It is not used and hidden in the mask; please remove it at the product or check the product type.',
    'durabilityLabelMissing' => 'Product "%product%" has a manufacturer\'s durability guarantee of %years% years, but no GARAN label. Without the label the shop does not show the guarantee; it has to be given before the purchase in the harmonised form (Art. 6(1)(la) Directive 2011/83/EU). Upload the completed label at the product.',
    'durabilityYearsMissing' => 'Product "%product%" has a GARAN label but no valid duration of the durability guarantee (more than two years). Without one the shop does not show the label. Enter the duration in years or remove the label.',
    'durabilityLabelNotPublic' => 'The GARAN label of product "%product%" is in a folder that is not public. The image would not show in the shop, so Consho does not show the guarantee there at all. Set the folder to "public" in the file manager.',
    'durabilityLabelTooLarge' => 'The GARAN label of product "%product%" is %size% KB. It travels in every order confirmation with this product; heavy mails end up in spam more often. Upload a smaller PNG or JPG (up to 100 KB).',
    'durabilityOnVariant' => 'Variant "%product%" carries a durability guarantee. It only applies at the main product; at the variant it has no effect. Enter it at the main product and empty it at the variant.',
    'durabilityNestedUnused' => 'The nested GARAN label of product "%product%" does not show on the tiles. It needs a full label with a valid duration for the click to open, and a public folder. Add the full label, set the folder to "public" or remove the nested label.',
    'mailFooterEmpty' => 'The shop configuration "%shop%" has neither a company nor a footer — the mails to customers do not say who sends them. What business mails must state depends on legal form and country. Fill in the company and "Footer (invoice and mails)" under "Invoice sender".',
    'mailLogoTooLarge' => 'The logo of the shop configuration "%shop%" is %size% KB. It travels in every mail to customers; heavy mails end up in spam more often. Upload a smaller PNG or JPG (up to 100 KB).',
    'voucherTaxed' => 'Gift voucher "%product%" has a tax class that is not marked "not taxable". It is taxed when sold, and what it buys is taxed again.',
    'notTaxableClassOnGoods' => 'Product "%product%" is not a gift voucher but has a "not taxable" tax class. It is sold without VAT.',
    'voucherValueMissing' => 'Gift voucher "%product%" has no value. A voucher bought is worth what was paid for it.',
    'ossThresholdNear' => 'Distance sales to consumers in other EU countries (%business%) reached 80 % of the 10,000 € threshold in %year%. Sales outside Consho count as well. Above the threshold the VAT of the customer\'s country applies — switch on "Tax at destination" in the shop configuration. Details under "Tax report".',
    'eInvoiceIncomplete' => 'The shop configuration "%shop%" issues e-invoices, but this is missing: %fields%. Until it is filled in, invoices go out without the e-invoice data.',
    'ossThresholdReached' =>'Distance sales to consumers in other EU countries (%business%) reached the 10,000 € threshold in %year%. Sales outside Consho count as well. Above the threshold the VAT of the customer\'s country applies — switch on "Tax at destination" in the shop configuration. Details under "Tax report".',
    'saleModePriceMissing' => 'Product "%product%" is offered as %modes%, but has no price for it — it cannot be bought that way. Add a row with "Sold as" under "Prices" at the product, or untick the sale mode there.',
    'subscriptionSameTermOpen' => 'The subscription rule "%rule%" renews for the full minimum term each time and is open beyond business customers — no customer group, or one that no shop marks as a net price group. Towards consumers a tacit renewal for a fixed term is permitted only within limits in DE and AT. For business customers choose the net price group under "Customer groups" in the rule; if the rule is open on purpose, acknowledge this note.',
    'subscriptionNotListed' => 'Product "%product%" is sold only under subscription rules that are not open to everyone (limited to customer groups). Product listings show only plans open to everyone — so the product appears in none, only on its own page. Right in a closed shop; otherwise add a rule without customer groups.',
    'subscriptionBillingMethodMissing' => 'Shop "%shop%" sells subscriptions but has no payment method "on account". Follow-up orders are billed with it — without one none is created, and the customer keeps using what nobody bills. Select a method of type "on account" in the shop configuration under payment methods; its restrictions (groups, country, maximum amount) do not apply to follow-up orders.',
    'startPointForwardsToProductPage' => 'Start point "%root%" forwards to its first page, and that is the product page "%page%". Without a product it has no address — the start point\'s domain answers with an error (500). In the site structure, put another page first below the start point, such as the shop\'s home page.',
    'startPointWithoutDomain' => 'Start points of shop "%shop%" without a domain: %roots%. Mails sent from the cron and the console — subscription invoices, "your digital content" — then link to localhost, as no request tells the host there. Enter the domain on the start point under "Domain name", or set framework.router.default_uri in the installation.',
    'subscriptionCountryChanged' => 'Subscription customers in the shop "%shop%" name another country in their account than on their last invoice: %numbers%. Follow-up invoices keep going to the previous address — a change of country changes tax and possibly reverse charge; please clarify with the customer.',
    'decisionNotSent' => 'Decisions on cancellations in the shop "%shop%" whose mail did not go out: %count% (%numbers%). The customer does not know how the cancellation was decided. The system log names the reason; once it is fixed, choose "Resend decision" under "Subscriptions" → Cancellations.',
    'cancellationsWaiting' =>'Extraordinary cancellations in the shop "%shop%" are waiting for your decision: %count% (%numbers%), the first since %since%. They take effect only once you accept them; accept or reject them under "Subscriptions" → Cancellations.',
    'registrationPathMissing' => 'Shop "%shop%" sells subscriptions, and a subscription needs a customer account — but no registration module gives new accounts the customer group, opens them and allows the login. Contao creates new members disabled and without login. On the registration module set "Allow login" and "Activate accounts at once", or "Account activation" with the shop\'s customer group among the default groups.',
    'customerPageLocksOut' => 'The page "%page%" (shop "%shop%") shows customers their orders or subscriptions, but it is protected without admitting these groups: %groups%. Their members get "access denied", with no entry in the log. Add the groups under "Protect page" in the site structure — on the parent page if the protection comes from there.',
    'loginPageMissing' => 'Shop "%shop%": start points with protected customer pages but no published 401 page: %roots%. A visitor who is logged out sees Contao\'s generic error page instead of the login. Create a page of type "401 Not authenticated" with a login module directly below the start point and set "Redirect to last page visited" there — otherwise the customer lands on the module\'s redirect page after the login instead of their customer page.',
    'cancellationPageMissing' =>'Shop "%shop%" sells subscriptions, but these start points have no reachable page with "Cancel contracts here": %roots%. On the start point under "\'Cancel contracts here\' page" choose a published page without login that carries the element "Cancel contracts here". Whether § 312k BGB applies to the shop is for you to judge.',
    'mandateMethodUnavailable' => 'Subscriptions in the shop "%shop%" are charged through a payment method that is no longer available: %count% (%numbers%). Their follow-up orders go "on account". Once the method is published and assigned to the shop again, they are charged again.',
    'subscriptionsSuspended' => 'Subscriptions on hold in shop "%shop%": %count% (%numbers%). An invoice is open past its payment term and grace period, and the access is on hold. Consho neither cancels nor sends reminders; once the payment is recorded in the order, the subscription comes back by itself.',
    'stockLow' => 'Stock low or gone: %count% articles — %products% (what is left in brackets). At 0 the article shows as "currently unavailable" in the shop. Reorder and book the stock at the article; for keys add codes under "Add keys".',
    'digitalFileMissing' => 'Articles delivered as "File" without a file that can be fetched: %count% (%products%). No file chosen, the file is missing, or it is in a public folder. Orders of these articles are released only once the file is there.',
    'deliveryTypeNotReady' => 'A delivery cannot deliver: %reason% Published articles affected: %count%. Their orders are released only once this is put right.',
    'deliveryArticleNotReady' => 'Articles that cannot deliver: %count% (%products%). %reason% Orders of these articles are released only once this is put right.',
    'paymentFeeTaxMissing' => 'The payment method "%method%" charges a fee, but how it is taxed has not been chosen. Until then it is not offered at checkout. In the payment method under "Fee" choose "proportional" or "fixed" with a tax class — whether a payment fee is ancillary or a supply of its own is not decided by Consho.',
    'invoiceOverdue' => 'Orders in the shop "%shop%" past their payment term and not paid: %count% (%numbers%). Consho sends no reminders by itself. Record the payment in the order or ask the customer.',
    'prepaymentUnpaid' => 'Prepayment orders in the shop "%shop%" unpaid for more than %days% days: %count% (%numbers%). They hold their stock until they are paid or cancelled — Consho cancels nothing by itself. Record the payment, ask the customer or cancel the order.',
    'senderDomainMismatch' => 'The shop configuration "%shop%" sends its mail from %sender%, while its start points run under %domains%. A mail whose links all lead to another domain than its sender looks like phishing to spam filters. Where possible, choose a sender under the domain of the shop.',
    'refundRangeMissing' => 'The shop configuration "%shop%" lacks number ranges for refunds: %types%. Without them a refund after a withdrawal cannot be booked.',
    'withdrawalPeriodShort' => 'The shop configuration "%shop%" has a withdrawal period of %days% days and delivers into the EU or the EEA (%countries%). Consumers there are entitled to 14 days by law; 0 days switches the withdrawal function off entirely. Check the setting under "Withdrawal".',
    'legalTextUnreachable' => 'Legal texts the customer cannot read: %count% (%pages%) — affected: %kinds%. The selected element is gone, is no longer a text element, is set to invisible, sits on an unpublished or protected page, or outside its time window. A text behind a login counts as not given.',
    'warrantyNoticeMissing' => 'Start points without a reachable page for the guarantee notice: %count% (%pages%). Since 27.09.2026 the harmonised EU notice on the legal guarantee has to be clearly visible in every shop (§ 4 Abs. 1 Z 12 FAGG, layout binding in Implementing Regulation (EU) 2025/1960). Create a page carrying the unaltered artwork published by the Commission in the language of this start point — with a clickable link to europa.eu — and select it here. Consho does not ship the artwork: it is official, must not be altered, and the Commission publishes it per language.',
    'warrantyNoticeFileMissing' => 'Start points without the guarantee notice as a file: %count% (%pages%). The order confirmation has to contain the harmonised EU notice (Art. 8(7) Directive 2011/83/EU), and Consho attaches it. At the start point, choose the unaltered colour version published by the Commission in the language of this start point under "Guarantee notice as a file" (PDF, PNG or JPEG).',
    'confirmationNotSent' => 'Orders in the shop "%shop%" whose confirmation was not sent: %count% (%numbers%). The confirmation with terms, withdrawal instructions and guarantee notice is mandatory (Art. 8(7) Directive 2011/83/EU). The system log names the reason; once it is fixed, choose "Resend confirmation" at the order. If it says "was sent, but the time could not be recorded", the customer already has it — do not resend.',
    'operatorAddressIsSender' => 'The shop configuration "%shop%" sends the operator copy to %address% — the same address the mails come from. Microsoft 365 files such a mail under "Sent" only, so the copy seems never to arrive. Better enter a mailbox of its own for the copies.',
    'testPaymentActive' => 'The test payment provider is switched on (environment variable CONSHO_TEST_PAYMENT=1). It confirms payments without taking any money. On a real shop, remove the variable from .env.local.',
    'privacyPageMissing' => 'Start points without a reachable privacy page: %count% (%pages%). None is selected, or the selected page is unpublished. Art. 13 GDPR wants the information where the data is collected — in the checkout. The page is set on the start point, in the "Consho" legend.',
    'invoiceSenderIncomplete' => 'The invoice sender of shop configuration "%shop%" is incomplete. %count% entries are missing: %fields%. Without the full name and address of the issuer the invoice is deficient (§ 11 UStG in Austria, § 14 UStG in Germany), and it is written at the buy click.',
    'numberPrefixShared' => 'Several document number ranges use the prefix "%prefix%": %ranges%. They issue the same numbers twice as soon as their counters meet. If they belong to one company, give each range its own prefix; if they are separate companies, the shared prefix is harmless.',
    'numberRangeMissing' => 'Shop configuration "%shop%" is missing %count% number ranges: %types%. Every order needs an order number, and the selected payment methods issue invoices — at the order (prepayment), on payment or on shipment (on account). Without a number range exactly that step stops: the purchase, or recording the payment or the shipment. The cancellation range is needed as soon as invoices exist: changing the payment method cancels the first invoice, and both sequences have to be gapless.',
    'invoiceArchiveFailed' => 'Invoices whose archiving failed: %count% (%numbers%). Order and invoice stand; on retrieval the PDF is regenerated and marked as such. The error log gives the reason. Once fixed, "consho:document:verify --retry" archives them.',
    'invoiceArchiveFolderUnsafe' => 'Shop configuration "%shop%" archives invoices, but the archive folder %problem%. Until that changes no invoice is archived — Consho never writes into a public folder, because every invoice would then be open to anyone.',
    'reverseChargeNoteMissing' => 'Shop configuration "%shop%" can issue reverse charge invoices (a VAT ID and delivery countries outside the home country) but has no note for them. Such an invoice would go out without one. The operator sets the wording, at the shop under "Reverse charge note".',
    'deliveryTimeMissing' => 'Shop configuration "%shop%" issues the invoice at the purchase with a selected payment method (prepayment, for instance) but has no delivery time. Such an invoice is written before shipping and should state the expected delivery period; without a delivery time it stays empty. Set "Delivery time from/to (working days)" at the shop.',
    'noticeParamStripped' => 'The environment variable QUERY_PARAMS_ALLOW_LIST is set and does not let these parameters through: %param%. The shared cache strips them before the request arrives — the checkout then never leaves its first step, every link to an order page ends in a 404, and a refused add reaches the visitor as silence. Add "consho_.*" to the list.',
];

// The parts of the finding "product does not fit its kind" (DL5).
$GLOBALS['TL_LANG']['CONSHO']['kindMismatch'] = [
    'kinds' => ['physical' => 'physical goods', 'digital' => 'digital', 'service' => 'a service', 'voucher' => 'a gift voucher'],
    'delivery' => 'a delivery type',
    'file' => 'a file',
    'keys' => 'keys in the pool',
    'subscription' => 'sale by subscription',
    'durability' => 'a durability guarantee (GARAN label)',
    'and' => ' and ',
];

/*
 * Messages from save callbacks. They appear at the field itself.
 */
$GLOBALS['TL_LANG']['CONSHO']['errors'] = [
    'facetStateEmpty' => '"%s" is not a valid filter combination. Expected group:option, several separated by semicolons — for example colour:red;volume:250ml.',
    'facetStateSorted' => '"%s" contains a sort order. A sorted view shows the same products in a different sequence and therefore never gets an address of its own.',
    'facetStateAvailable' => '"%s" contains "available only". What is available changes with the stock, so such a view never gets an address of its own.',
    'facetStateDuplicate' => 'This filter combination is already released on this page as "%s". Two addresses for the same selection would be two pages with identical content.',
    'facetStateUnknown' => '"%s" names a group or option that does not exist or that visitors cannot filter by (group not marked filterable). The landing page would otherwise show the whole category under its own title.',
    'facetStateOutsideCategory' => '"%s" lies outside the product selection of this listing page. A landing page narrows down within its category — this combination would not contain a single product there.',
    'facetStateMatchesCategory' => '"%s" matches this category\'s own filter preset exactly. This combination shows the same products as the category itself and therefore needs no address of its own.',
    'facetPageWithoutListing' => 'The page with ID %s carries no visible product listing that follows the address. The landing page could not be reached below it. Please choose a listing page.',
    'facetAliasForm' => '"%s" is not a valid address. Allowed are lowercase letters, digits and hyphens — the same form as in filter addresses.',
    'facetAliasDuplicate' => 'The address "%s" is already taken on this page by the landing page with ID %s.',
    'facetAliasShadowed' => 'The page "%s" (ID %s) already answers at %s. It hides this landing page: it would be saved, but never reachable. Please choose another address.',
    'gtinInvalid' => '"%s" is not a valid GTIN. Expected 8, 12, 13 or 14 digits with a matching check digit — a mistyped number names a different article.',
    'variantSelf' => 'A product cannot be a variant of itself.',
    'variantHasVariants' => 'This product has variants of its own (count: %s) and cannot become a variant. The existing variants would be left without a main article.',
    'pathTemplateUnknownPlaceholder' => '"%s" is not a known placeholder. Available are {alias}, {sku}, {manufacturer} and {type} — only values that are unique per product. A category is deliberately not among them: a product sits in several, and the address would stop being unique.',
    'pathTemplateNoIdentifier' => 'The address pattern needs {alias} or {sku}. Without one of them every product would share the same address.',
    'pathTemplateRepeated' => 'The placeholder {%s} appears more than once. The address would carry the same value twice.',
    'pathTemplateEmpty' => 'The address pattern cannot be empty. The shortest sensible value is {alias}.',
    'translationAliasRequired' => 'Something is filled in for the language "%s", but neither an alias nor a name. Without an alias the entry cannot be reached in that language — enter an alias, or a name to generate it from, or clear the section completely.',
    'shopCurrenciesEmpty' => 'The shop needs at least one currency. Without one no start page could pick any — and there is no silent default: assuming euro where nobody said so would be worse than asking.',
    'shopCurrencyUnknown' => '"%s" is not a valid ISO 4217 code. Contao offers an old or hand-entered value back as an unknown option — this is where it stops.',
    'rootCurrencyNotAllowed' => 'The shop configuration "%2$s" does not accept "%1$s". Contao offers a currency back once it has been stored, even after the shop dropped it — this is where it stops. Either pick one of the currencies on offer or allow "%1$s" in "%2$s" again.',
    'rootCurrencyWithoutShop' => 'This page has no shop configuration, so no currency applies to it — "%1$s" cannot be saved. Pick a shop on the start page first; which currencies are then on offer follows from it.',
    'priceCurrencyWithoutShop' => 'No shop sells in "%s". A price row needs the currency of a shop configuration with a home country — choose one, or create the shop first.',
    'priceCountryMissing' => 'Shops from %2$s sell in %1$s. Choose one of these countries — its tax rate turns the gross amount into net.',
    'priceTaxClassMissing' => 'The product has no tax class. Without one a gross amount cannot be turned into net — set the tax class on the product or enter the amount as net.',
    'priceTaxRateMissing' => 'The product\'s tax class has no rate in %s on %s. Add the rate under "Taxes" or enter the amount as net.',
    'priceNegative' => 'A price cannot be negative. Discounts do not belong in the price row.',
    'priceInvalid' => 'Please enter the amount as a number, e.g. 12.90.',
    'priceOverrideNeedsMode' => 'Override the amount together with "Enter as" — otherwise the same number would be gross in one row and net in the next.',
    'priceTooLarge' => 'The amount is too large: at most 99,999,999.9999 net can be stored.',
    'priceAmountMissing' => 'A new price row cannot be saved without an amount. If the "Amount" field is missing from the mask, the user group lacks field rights: an administrator has to allow Currency, Enter as, Amount and Country together.',
    'shippingPriceTaxClassMissing' => 'The shipping method has no tax class. Without one a gross amount cannot be turned into net — set the tax class on the shipping method or enter the amount as net.',
    'shippingPriceDuplicate' => 'This shipping method already has an amount in %s (for weight brackets: for this bracket). Exactly one applies per currency and bracket — edit or delete one of them.',
    'ibanInvalid' => '"%s" is not a valid IBAN — length or check digits do not match. A mistyped IBAN on the invoice sends the money to someone else\'s account.',
];

/*
 * Heading of the section per translation language. The same in all five
 * translated tables, hence here rather than five times in the table files.
 */
$GLOBALS['TL_LANG']['CONSHO']['translationLegend'] = 'Translation: %s';

/*
 * Label of the button on a note. Only notes carry one — warnings cannot
 * be clicked away.
 */
$GLOBALS['TL_LANG']['CONSHO']['acknowledge'] = 'Noted';

$GLOBALS['TL_LANG']['CONSHO']['deliverability'] = [
    'senderAddressInvalid' => 'The sender address is not a valid e-mail address.',
    'spfMissing' => 'There is no SPF record for %domain%. Mail is likely to be filtered or rejected.',
    'dmarcMissing' => 'There is no DMARC record for %domain%.',
    'alignmentMismatch' => 'The sender domain (%domain%) does not match the authenticated domain (%authenticated%). This is the most common cause of mail landing in spam.',
    'dkimNotVerifiable' => 'DKIM cannot be checked: the record lives at <selector>._domainkey.%domain% and the selector cannot be derived from the address.',
];

/*
 * Label of the page type. Contao merges registered types into the select
 * (PageTypeOptionsListener); without this entry the raw key would show.
 */
$GLOBALS['TL_LANG']['PTY']['consho_product'] = ['Consho product page', 'The page all product detail addresses live under. The address of each product comes from the address pattern on this page.'];

$GLOBALS['TL_LANG']['CTE']['consho'] = 'Consho';
$GLOBALS['TL_LANG']['CTE']['consho_product_list'] = ['Product list', 'Shows products matching the chosen property selection.'];
$GLOBALS['TL_LANG']['CTE']['consho_product_filter'] = ['Product filter', 'Filters as links to the product listing of the page, with counts. Usually placed in a layout section, where it serves every category page.'];
$GLOBALS['TL_LANG']['CONSHO']['listEmpty'] = 'There are currently no products in this selection.';
$GLOBALS['TL_LANG']['CONSHO']['filterAvailableOnly'] = 'Available only';
$GLOBALS['TL_LANG']['CTE']['consho_product_pin'] = ['Pin', 'Puts a product at the start of the product listing. Can only be created inside a product listing.'];
$GLOBALS['TL_LANG']['CONSHO']['pinLabel'] = 'Pinned: %s';
$GLOBALS['TL_LANG']['CONSHO']['pinMissing'] = 'There is no main product with the ID %s. This pin shows nothing.';

$GLOBALS['TL_LANG']['CONSHO']['sort'] = [
    'title' => 'Name',
    'price_asc' => 'Price ascending',
    'price_desc' => 'Price descending',
    'newest' => 'Newest first',
    'sorting' => 'Manual order',
];
$GLOBALS['TL_LANG']['CONSHO']['priceFrom'] = 'from';
// Block H (H-2): badge and note next to the price struck through. The wording is
// the operator's (§ 9a PrAG, § 11 PAngV require the price, not the words).
$GLOBALS['TL_LANG']['CONSHO']['promotion']['badge'] = 'Sale';
$GLOBALS['TL_LANG']['CONSHO']['promotion']['reference'] = 'lowest price of the last 30 days';

$GLOBALS['TL_LANG']['CTE']['consho_product_detail'] = ['Product detail', 'Shows the product of the product page: images, price, variants, safety warnings and manufacturer details. On any other page the element stays empty.'];

// Placeholders: amount, reference quantity, unit symbol.
$GLOBALS['TL_LANG']['CONSHO']['basePrice'] = '%1$s / %2$s %3$s';
$GLOBALS['TL_LANG']['CONSHO']['unitSymbols'] = [
    'kg' => 'kg',
    'g' => 'g',
    'l' => 'l',
    'ml' => 'ml',
    'm' => 'm',
    'cm' => 'cm',
    'm2' => 'm²',
    'stk' => 'pc.',
];
$GLOBALS['TL_LANG']['CONSHO']['priceTaxNotice'] = 'incl. VAT, plus shipping';
$GLOBALS['TL_LANG']['CONSHO']['priceTaxNoticeNet'] = 'plus VAT, plus shipping';
$GLOBALS['TL_LANG']['CONSHO']['priceTaxOnly'] = 'incl. VAT';
$GLOBALS['TL_LANG']['CONSHO']['priceShippingOnly'] = 'plus shipping';
$GLOBALS['TL_LANG']['CONSHO']['priceTaxOnlyNet'] = 'plus VAT';
$GLOBALS['TL_LANG']['CONSHO']['ownPriceGroup'] = 'You are signed in as %s — your own price is shown on the product page.';
$GLOBALS['TL_LANG']['CONSHO']['scaleQuantity'] = 'Quantity';
$GLOBALS['TL_LANG']['CONSHO']['scalePrice'] = 'Price per item';
$GLOBALS['TL_LANG']['CONSHO']['scaleFrom'] = 'from %s';
$GLOBALS['TL_LANG']['CONSHO']['minimumAge'] = 'Sold from the age of %s only';
$GLOBALS['TL_LANG']['CONSHO']['garan']['alt'] = 'GARAN – manufacturer\'s durability guarantee: %s years. Your legal guarantee of conformity applies as well.';
$GLOBALS['TL_LANG']['CONSHO']['garan']['more'] = 'More about the durability guarantee (Your Europe)';
$GLOBALS['TL_LANG']['CONSHO']['garan']['open'] = 'Open the GARAN label at full size';
$GLOBALS['TL_LANG']['CONSHO']['detail'] = [
    'sku' => 'Item number',
    'gtin' => 'GTIN',
    'mpn' => 'Manufacturer part number',
    'productSafety' => 'Product safety',
    'manufacturer' => 'Manufacturer',
    'euResponsible' => 'Responsible person in the EU',
    'safetyInformation' => 'Safety information',
    // ZW-4: the product type's custom fields (Z3).
    'customFields' => 'Details',
    'yes' => 'Yes',
    'no' => 'No',
];
$GLOBALS['TL_LANG']['CTE']['consho_cart'] = ['Basket', 'Shows the visitor\'s basket with quantities and totals. It makes the page private and uncached.'];

$GLOBALS['TL_LANG']['CONSHO']['cart'] = [
    'quantity' => 'Quantity',
    // Placeholder: the title of the line. The visible column heading stays
    // "Quantity" — this text labels the individual field, which would
    // otherwise read the same in every row.
    'quantityOf' => 'Quantity: %s',
    // Abo-7b (P67): a line from "Renew licence".
    'renews' => 'renews subscription %s — licence and keys stay',
    'quantityHint' => 'Minimum quantity %1$s, step %2$s.',
    'add' => 'Add to basket',
    'empty' => 'Your basket is empty.',
    'article' => 'Article',
    'lineTotal' => 'Total',
    'total' => 'Grand total',
    'actions' => 'Actions',
    'update' => 'Update',
    'remove' => 'Remove',
    'noPrice' => 'No price',
    'unknownArticle' => 'Article no longer available',
    'unpriced' => 'One article in your basket currently has no price. Please remove it to continue.',
    'notice' => [
        'added' => 'The article has been added to your basket.',
        'unavailable' => 'This article is currently not available.',
        'needs_choice' => 'Please choose a version first.',
        'invalid_quantity' => 'This quantity is not possible.',
        'not_offered' => 'This plan is not available to you. Please choose another one.',
        'out_of_stock' => 'This article is currently out of stock.',
        'too_many_codes' => 'Too many invalid codes. Please try again in a quarter of an hour.',
        'voucher_unknown' => 'We do not know this voucher code. Please check what you typed.',
        'too_many_vouchers' => 'No more vouchers can be redeemed in one purchase.',
    ],
];

$GLOBALS['TL_LANG']['CTE']['consho_mini_cart'] = ['Mini basket', 'Count and total of the basket, also in a layout section. Loaded as an ESI fragment, so it does not take the page out of the cache.'];

/*
 * Both texts sit invisibly beside the number and the amount: a "2" and an
 * amount with no word are not interpretable with a screen reader.
 */
$GLOBALS['TL_LANG']['CONSHO']['miniCart'] = [
    'label' => 'Basket',
    'lines' => 'items',
];


/*
 * The checkout (C-5). Step names are deliberately not the block names: with
 * one step it reads "Order", with four they read separately — a visitor reads
 * a number, not an architecture.
 */
$GLOBALS['TL_LANG']['CONSHO']['checkout'] = [
    'empty' => 'Your basket is empty. Put something in it to order.',
    /*
     * The late double click (F2): the order exists, the basket does not any
     * more. Deliberately no order number and no link — this page cannot prove
     * who is asking, and the confirmation went out by mail.
     */
    'alreadyOrdered' => 'Your order has already been placed. You will find the details in your confirmation email — nothing was ordered twice.',
    'steps' => 'Steps of this order',
    /*
     * One name per group of blocks, not per number: which groups exist is
     * decided by CheckoutBlock::steps(), and a numbered name would be wrong
     * for every other step count.
     */
    'step' => [
        'address_shipping_payment_review' => 'Order',
        'address_shipping_payment' => 'Your details',
        'shipping_payment' => 'Shipping and payment',
        'address' => 'Address',
        'shipping' => 'Shipping',
        'payment' => 'Payment',
        'review' => 'Review and buy',
    ],
    'choose' => 'Please choose',
    'free' => 'free of charge',
    'save' => 'Continue',
    'unavailable' => 'not available right now',
    'shipping' => 'Shipping method',
    'payment' => 'Payment method',
    'review' => 'Review and buy',
    'login_required' => 'This shop requires an account. Please sign in to continue.',
    'reverse_charge' => 'Reverse charge — VAT to be accounted for by the recipient',
    'age' => 'This basket contains articles for ages %s and over.',
    'address' => [
        'billing' => 'Billing address',
        'shipping' => 'Delivery address',
        'separate' => 'Use a different delivery address',
    ],
    'total' => [
        'net' => 'Total, net',
        'gross' => 'Total (incl. VAT)',
        'plain' => 'Total',
    ],
    'vat' => [
        'recheck' => 'Check VAT ID again',
    ],
    'field' => [
        'company' => 'Company',
        'salutation' => 'Salutation',
        'firstname' => 'First name',
        'lastname' => 'Last name',
        'street' => 'Street and number',
        'postal' => 'Postcode',
        'city' => 'Town',
        'state' => 'State',
        'country' => 'Country',
        'phone' => 'Telephone',
        'email' => 'Email',
        'vatId' => 'VAT ID',
    ],
    'terms_accept' => 'I have read the terms and conditions and accept them',
    'legal' => [
        'intro' => 'Please note:',
        'terms' => 'Terms and conditions',
        'withdrawal' => 'Withdrawal instruction',
        'warranty' => 'Legal guarantee',
        'privacy' => 'Privacy',
        'newWindow' => ' (opens in a new window)',
    ],
    /*
     * The three approved buy button labels. Which one applies is set on the
     * shop; that there are only these three is in BuyButtonLabel.
     */
    'buy' => [
        'paymentRequired' => 'Order with an obligation to pay',
        'chargeable' => 'Order subject to charge',
        ],
    'purchase' => [
        'checksumMismatch' => 'Something about your order has changed in the meantime. Please check the details and confirm again.',
        'termsNotAccepted' => 'Please accept the terms and conditions to order.',
        'noOrderPage' => 'This shop is not set up to confirm orders yet. Nothing has been ordered — please get in touch with us.',
        'couldNotPlace' => 'Your order could not be placed just now, and nothing has been charged. Please try again in a moment or get in touch with us.',
        'stockShort' => 'An article is no longer available in the quantity you chose. Nothing was ordered — please adjust the quantity in your basket.',
        'discountExhausted' => 'The discount was used up in the meantime. Nothing was ordered — please check your order without it.',
        'voucherChanged' => 'A gift voucher has changed in the meantime. Nothing was ordered — please check what your vouchers cover now.',
    ],
    'problem' => [
        'basketEmpty' => 'Your basket is empty.',
        'unpricedLine' => 'One article in your basket currently has no price. Please remove it.',
        'addressIncomplete' => 'Please complete your address.',
        'deliveryCountryNotServed' => 'We do not deliver to this country at the moment.',
        'vatIdInvalid' => 'This VAT ID is not valid.',
        'vatServiceUnavailable' => 'The VAT ID could not be checked just now. You can order with tax or try the check again.',
        'noShippingMethod' => 'No shipping method is available for this order at the moment.',
        'shippingMethodGone' => 'The shipping method you chose is no longer available. Please choose again.',
        'noPaymentMethod' => 'No payment method is available for this order at the moment.',
        'paymentMethodGone' => 'The payment method you chose is no longer available. Please choose again.',
        'ageNotVerifiable' => 'This basket contains age-restricted articles we may not send this way.',
        'dateOfBirthMissing' => 'This shipping method needs your date of birth.',
        'tooYoung' => 'You are not yet old enough for the articles in this basket.',
        'stockShort' => 'An article in your basket is not available in the quantity you chose. Please adjust it in your basket.',
    ],
];

$GLOBALS['TL_LANG']['CTE']['consho_checkout'] = ['Checkout', 'The ordering process in one to four steps. This makes the page private and keeps it out of the cache.'];
$GLOBALS['TL_LANG']['CTE']['consho_order'] = ['Order page', 'Shows one order through the link in the confirmation: state, documents, changing the payment method. Belongs on the start point\'s order page. Without a valid link it offers to send a new one to the address of the order.'];
$GLOBALS['TL_LANG']['CTE']['consho_my_subscriptions'] = ['My subscriptions', 'The signed-in customer\'s subscriptions in this shop: status, next billing, end; a link to the order and "Cancel". Belongs on a protected page of the account area. Does not replace "Cancel contracts here", which must be reachable without a login.'];
$GLOBALS['TL_LANG']['CTE']['consho_cancellation'] =['Cancel contracts here', 'The cancellation button of § 312k BGB: subscription number, e-mail and name, then "cancel now"; afterwards the declaration with date and time to save. Belongs on a page of the start point that is reachable from every page, through the footer for instance — without a login.'];
$GLOBALS['TL_LANG']['CTE']['consho_withdrawal'] =['Withdraw from contract', 'The withdrawal function for buyers without the order mail: order number, e-mail and name, then "confirm withdrawal". Belongs on the start point\'s "withdraw from contract" page and should be reachable from every page, through the footer for instance.'];

$GLOBALS['TL_LANG']['CONSHO']['checkout']['blocked'] = 'Ordering is not possible at the moment. Please try again later.';

$GLOBALS['TL_LANG']['CONSHO']['cart']['checkout'] = 'Proceed to checkout';

$GLOBALS['TL_LANG']['CONSHO']['tile']['purchase'] = 'Add %s to your basket';
$GLOBALS['TL_LANG']['CONSHO']['tile']['alsoSubscription'] = 'also as a subscription';

$GLOBALS['TL_LANG']['CONSHO']['checkout']['requiredNote'] = 'Fields marked with * are required.';

$GLOBALS['TL_LANG']['CONSHO']['checkout']['address']['shippingHint'] = '— only needed if you ticked "different delivery address" above';

// The invoice (C-8). Labels only — every value comes from the snapshot.
$GLOBALS['TL_LANG']['CONSHO']['invoice']['types']['invoice'] = 'Invoice';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['types']['cancellation'] = 'Cancellation invoice';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['types']['creditNote'] = 'Invoice correction';
// The number ranges as the mask names them — here, because findings appear on
// the start page, where tl_consho_number_range is not loaded (Fable review D-3, S1).
$GLOBALS['TL_LANG']['CONSHO']['numberRangeTypes'] = [
    'order' => 'Order number',
    'invoice' => 'Invoice number',
    'deliveryNote' => 'Delivery note number',
    'creditNote' => 'Invoice correction number',
    'cancellation' => 'Cancellation number',
];
$GLOBALS['TL_LANG']['CONSHO']['invoice']['types']['deliveryNote'] = 'Delivery note';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['number'] = 'Invoice number';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['date'] = 'Invoice date';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['correction']['number'] = 'Correction number';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['correction']['date'] = 'Correction date';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['orderNumber'] = 'Order number';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['cancels'] = 'Cancels invoice';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['corrects'] = 'Corrects invoice';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['withdrawal'] = 'Withdrawal';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['cancelsDate'] = 'of %s';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['orderDate'] = 'Order date';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['subscription'] = 'Subscription';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['servicePeriod'] = 'Service period';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['deliveryPeriod'] = 'Expected delivery';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['deliveryDate'] = 'Delivery date';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['deliveryPartial'] = '%s (partial delivery)';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['deliveryRest'] = 'Remainder expected';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['dueDate'] = 'Payable by';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['billingAddress'] = 'Billing address';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['shippingAddress'] = 'Delivery address';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['vatId'] = 'VAT ID';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['customerVatId'] = 'Recipient VAT ID';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['lines'] = 'Items';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['position'] = 'No.';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['item'] = 'Description';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['sku'] = 'SKU';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['quantity'] = 'Qty';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['unitPrice'] = 'Unit price';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['taxRate'] = 'VAT rate';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['total'] = 'Total';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['taxes'] = 'VAT by rate';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['net'] = 'Net';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['tax'] = 'VAT';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['gross'] = 'Gross';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['netTotal'] = 'Net total';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['taxTotal'] = 'VAT';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['grandTotal'] = 'Total amount';
// Wertgutscheine (WG12): payments before the invoice and what remains.
$GLOBALS['TL_LANG']['CONSHO']['invoice']['paid'] = 'Already paid';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['refunded'] = 'Refunded';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['outstanding'] = 'Amount outstanding';
// Wertgutscheine (WG3): the line of a voucher sold with a "nicht steuerbar" class.
$GLOBALS['TL_LANG']['CONSHO']['invoice']['notTaxable'] = 'not taxable';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['shippingMethod'] = 'Shipping method';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['paymentMethod'] = 'Payment method';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['payment'] = 'Payment';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['bank']['accountHolder'] = 'Account holder';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['bank']['iban'] = 'IBAN';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['bank']['bic'] = 'BIC';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['bank']['bankName'] = 'Bank';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['bank']['reference'] = 'Payment reference';
// The grey delete button of a shipping or payment method (C-9, wording: Michael 2026-09-25).
$GLOBALS['TL_LANG']['CONSHO']['methodInUse'] = 'This item is linked to at least one order and can no longer be deleted.';
$GLOBALS['TL_LANG']['CONSHO']['invoice']['giroCode'] = 'Scan with your banking app to pay';

// The confirmation mails (C-9). ⚠️ Placeholders are %s in order: Contao fills
// contao_default strings with vsprintf, not by name.
$GLOBALS['TL_LANG']['CONSHO']['mail']['salutation'] = 'Dear %s,';
$GLOBALS['TL_LANG']['CONSHO']['mail']['viewOrder'] = 'View your order';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdraw'] = 'Withdraw from contract here';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['subject'] = 'Your order %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['intro'] = 'thank you for your order %s of %s. We confirm receipt as follows.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['invoiceAttached'] = 'The invoice is attached.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['warrantyAttached'] = 'The notice about your legal guarantee of conformity is attached.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['garanHeadline'] = 'Manufacturer\'s durability guarantee';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['garanLine'] = 'For "%s" the manufacturer gives a durability guarantee of %s years. Your legal guarantee of conformity applies as well.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['operator']['subject'] = 'New order %s for %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['operator']['intro'] = 'New order %s of %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['subject'] = 'New payment method for your order %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['intro'] = 'your order %s is now paid by: %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['cancelled'] = 'Invoice %s is cancelled accordingly; the cancellation invoice %s is attached.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['newInvoice'] = 'The new invoice %s is attached.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['operator']['subject'] = 'Payment method changed: order %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentChanged']['operator']['intro'] = 'The payment method of order %s was changed to: %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['subject'] = 'Payment received: order %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['intro'] = 'we have received your payment for order %s. Thank you!';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['invoiceAttached'] = 'Please find invoice %s attached.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['operator']['subject'] = 'Payment received: order %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['operator']['intro'] = 'The payment for order %s has arrived (%s).';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['subject'] = 'Your order %s is on its way';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['intro'] = 'we have shipped the following items of your order %s:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['rest'] = 'The remaining items will follow in another parcel.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['tracking'] = 'Tracking number (%s): %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['trackingNoCarrier'] = 'Tracking number: %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['shipped']['invoiceAttached'] = 'Please find invoice %s attached.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['subject'] = 'Your invoice for subscription %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['intro'] = 'a new period of your subscription %s begins: from %s to %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['invoiceAttached'] = 'Please find invoice %s attached.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['charged'] = 'The amount was charged on %s via %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['operator']['introCharged'] = 'Follow-up order %s for subscription %s charged via %s on %s, period %s to %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['operator']['subject'] = 'Follow-up order %s for subscription %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionInvoice']['operator']['intro'] = 'Follow-up order %s for subscription %s created, "on account", period %s to %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['subject'] = 'Your subscription %s is on hold';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['intro'] = 'the payment for %s of your subscription %s was due on %s and is still open: %s. Until the payment arrives, your access is on hold.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['resume'] = 'As soon as the payment has arrived, your access is available again — you need not do anything else. The payment details are on your order page.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['operator']['subject'] = 'Subscription %s on hold: %s open';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['operator']['intro'] = 'Subscription %s is on hold: invoice %s (order %s) was due on %s and %s is still open.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionSuspended']['operator']['note'] = 'Consho neither cancels nor sends reminders. Once the payment is recorded in the order, the subscription comes back by itself.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['subject'] = 'Your subscription %s: the minimum term ends on %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['intro'] = 'the minimum term of your subscription %s (%s) ends on %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['ends'] = 'The subscription ends then. If you would like to keep using %s, you can take it out again.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['sameTerm'] = 'It then renews for %s on the terms so far. If you do not want that, cancel by %s at the latest.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['indefinite'] = 'It then runs on open-ended on the terms so far and can be cancelled at any time with %s notice. If you want it to end on %s, cancel by %s at the latest.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['buyAgain'] = 'Take it out again';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionReminder']['renew'] = 'Renew licence';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionPaused']['subject'] = 'Pause of your subscription %s confirmed';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionPaused']['intro'] = 'your subscription %s is paused from %s to %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionPaused']['effect'] = 'Nothing is billed meanwhile, and your access rests. On %s the subscription carries on; its term moves by the pause.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionPaused']['operator']['subject'] = 'Subscription %s paused';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionPaused']['operator']['intro'] = 'Subscription %s is paused from %s to %s.';
// E7: a charge with the mandate is under way.
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionChargePending']['subject'] = 'Payment for your subscription %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionChargePending']['intro'] = 'a new period of your subscription %s begins: from %s to %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionChargePending']['charge'] = 'We have requested the amount of %s through %s. It may take a few days until the payment is confirmed.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionChargePending']['invoiceFollows'] = 'You will receive the invoice as soon as the amount has arrived.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionResumed']['subject'] = 'Your subscription %s is active again';
$GLOBALS['TL_LANG']['CONSHO']['mail']['subscriptionResumed']['intro'] = 'no invoice for your subscription %s is open any more. The subscription is active again, and your access is available once more.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['purchaseSuspended']['subject'] = 'Your digital content from order %s is on hold';
$GLOBALS['TL_LANG']['CONSHO']['mail']['purchaseSuspended']['intro'] = 'the payment for %s was due on %s and is still open: %s. Until the payment arrives, the digital content of this order is on hold — downloads, licences and access cannot be used meanwhile.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['purchaseSuspended']['resume'] = 'As soon as the payment has arrived, it is available again — you need not do anything else. The payment details are on your order page.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['purchaseResumed']['subject'] = 'Your digital content from order %s is available again';
$GLOBALS['TL_LANG']['CONSHO']['mail']['purchaseResumed']['intro'] = 'no payment for your order %s is overdue any more. Your digital content is available again.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['subject'] = 'Confirmation of receipt: cancellation of your subscription %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['intro'] = 'we confirm receipt of your cancellation of subscription %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['statement'] = 'Your cancellation as received:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['receivedAt'] = 'Received';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['number'] = 'Cancellation number';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['contract'] = 'Contract';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['kind'] = 'Kind of cancellation';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['kinds'] = ['ordinary' => 'ordinary cancellation', 'extraordinary' => 'extraordinary cancellation'];
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['reason'] = 'Reason';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['name'] = 'Name';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['email'] = 'E-mail';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['requestedAt'] = 'Requested date';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['earliest'] = 'at the earliest possible date';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['endsAt'] = 'By this cancellation the contract ends on %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['earliestApplied'] = 'The requested date was before the earliest possible one. The cancellation therefore takes effect at the earliest possible date.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['laterThanEarliest'] = 'The requested date lies after the earliest possible end (%s). Billing periods that begin before the end of contract are still billed in full.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['pending'] ='We decide on an extraordinary cancellation and let you know the result — if we accept it, with the date the contract ends.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['operator']['subject'] = 'Cancellation received: subscription %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['operator']['intro'] = 'A cancellation of subscription %s has been received.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellation']['operator']['decide'] = 'An extraordinary cancellation takes effect only once you accept it in the back end (Subscriptions → Cancellations). You can also reject it there, with a reason.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellationDecided']['acceptedSubject'] = 'Your cancellation of subscription %s has been accepted';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellationDecided']['rejectedSubject'] = 'Your cancellation of subscription %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellationDecided']['accepted'] = 'we accept your extraordinary cancellation %s of subscription %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellationDecided']['rejected'] = 'we cannot accept your extraordinary cancellation %s of subscription %s. The reason:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['cancellationDecided']['runsOn'] = 'The subscription continues on the terms so far. An ordinary cancellation is not affected by this.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['subject'] = 'Confirmation of receipt: withdrawal from order %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['intro'] = 'we confirm receipt of your withdrawal from order %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['receivedAt'] = 'Received';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['number'] = 'Withdrawal number';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['order'] = 'Order';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['name'] = 'Name';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['email'] = 'E-mail';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['scope'] = 'Withdrawn';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['all'] = 'the whole order';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['message'] = 'Your message';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['statement'] = 'Your declaration as received:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['operator']['message'] = 'Customer\'s message';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawalRejected']['subject'] = 'Your withdrawal %s from order %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawalRejected']['intro'] = 'we cannot accept your withdrawal %s from order %s. The reason:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['subject'] = 'Your withdrawal %s from order %s: refund';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['intro'] = 'we have processed your withdrawal %s from order %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['payout'] = 'We are refunding %s to you using the same means of payment you paid with, unless we have agreed otherwise.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['vouchers'] = 'Of this, we are crediting %s back to the gift voucher you paid with.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['credit'] = 'The amount you have to pay is reduced by %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['documentAttached'] = 'Please find the documents attached: %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['remaining'] = 'Please transfer only the remaining amount of %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['valueCompensation'] = 'For the service rendered until your withdrawal we keep back a value compensation of %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['refund']['subscriptionEnded'] = 'Your subscription %s has ended with the withdrawal.';
// "My orders" (D-4, K3).
$GLOBALS['TL_LANG']['CTE']['consho_my_orders'] = ['My orders', 'The orders of the signed-in member in this shop, each with a link to the order page. Orders placed as a guest under the account\'s address appear once the member has confirmed the address by mail. Belongs on a protected page of the customer account.'];
$GLOBALS['TL_LANG']['CONSHO']['myOrders'] = [
    'empty' => 'You have not placed an order in this shop yet.',
    'colOrder' => 'Order',
    'colDate' => 'Ordered on',
    'colTotal' => 'Total',
    'asGuest' => '(as a guest)',
    'guestHint' => 'Orders you placed without an account under %s appear here once you have confirmed that the address is yours.',
    'guestButton' => 'Show orders placed as a guest',
    'confirmIntro' => 'Please confirm that the address %s is yours. Your orders placed as a guest will then appear here too.',
    'confirmButton' => 'Confirm address',
    'confirmState' => [
        'login' => 'Please sign in with your customer account, then open the link from the mail again.',
        'invalid' => 'This link is invalid or has expired. Ask for a new one under "My orders".',
        'otherAccount' => 'This link belongs to another customer account or another address. Nothing was confirmed.',
    ],
    'notice' => [
        'sent' => 'We have sent a mail to %s. Please open the link in it while you are signed in here.',
        'notSent' => 'The mail could not be sent. Please try again later.',
        'tooMany' => 'You have just asked for the mail several times. Please try again in an hour.',
        'confirmed' => 'Thank you — the address is confirmed. Your orders placed as a guest are now in the list.',
        'notFound' => 'This order does not exist in your customer account.',
        'noOrderPage' => 'The order cannot be opened right now. Please contact the shop.',
    ],
];
// The menu item "Customers" (D-4, K2).
$GLOBALS['TL_LANG']['CONSHO']['statusFields'] = [
    'statusAfterPayment' => 'Status after full payment',
    'statusAfterDelivery' => 'Status after full delivery',
    'statusWhenSettled' => 'Status when everything is settled',
];

// Block R (R10): the fields an e-invoice needs, for the finding.
$GLOBALS['TL_LANG']['CONSHO']['eInvoiceFields'] = [
    'invoiceCompany' => 'Company name',
    'invoiceCountry' => 'Country of the invoice sender',
    'vatId' => 'Own VAT ID',
    'invoiceContactEmail' => 'Contact e-mail for invoices',
];

// Block R (R6, R7): "Tax report".
$GLOBALS['TL_LANG']['CONSHO']['dashboard'] = [
    'title' => 'Consho Dashboard',
    'openPoints' => 'Open points',
    'unpaid' => 'Payments',
    'overdue' => '%d of them overdue',
    'toDeliver' => 'Deliveries',
    'complaints' => 'Complaints',
    'undecided' => 'Withdrawals',
    'refundOpen' => 'Refunds',
    'refundDue' => 'by %s',
    'stockLow' => 'Articles below reorder level',
    'reorder' => 'Reorder',
    'stockMore' => 'and %d more',
    'figures' => 'Orders and gross order value, cancellations excluded',
    'today' => 'Today',
    'week' => '7 days',
    'month' => 'Month',
    'noOrders' => 'No order this month yet.',
    'tax' => 'Tax report',
    'threshold' => 'Delivery threshold',
    'previousYear' => 'previous year',
    'sharedWith' => 'together with %s',
    'taxOpen' => 'Tax report and quarterly export',
    'incomplete' => 'Setup incomplete — this shop does not sell.',
    'incompleteHidden' => 'Setup incomplete — this shop does not sell. The reasons are shown to whoever may edit the setup.',
    'setupContinue' => 'Continue setup',
    'setupStart' => 'No shop configuration yet — start the setup',
    'warnings' => 'Warnings',
    'notes' => 'Notes',
    'close' => 'Close',
    'notesElsewhere' => '%d notes in the shop dashboard.',
    'open' => 'Open',
    'filtered' => 'Filtered from the dashboard: %s (%d).',
    'showAll' => 'Show all',
    'filters' => [
        'unpaid' => 'unpaid',
        'overdue' => 'overdue',
        'toDeliver' => 'to deliver',
        'complaint' => 'complaint open',
        'undecided' => 'to decide',
        'refundOpen' => 'refund open',
        'low' => 'below reorder level',
    ],
];

// Block I: "Setup".
$GLOBALS['TL_LANG']['CONSHO']['setup'] = [
    'title' => 'Setup',
    'back' => 'Back to the dashboard',
    'intro' => 'What a shop needs before its first sale. The steps come from the same checks as the notes: a step is done when none of them fires any more. Content such as legal texts, addresses, prices and tax rates is yours to enter.',
    'ready' => 'Ready — this shop sells.',
    'notReady' => 'Not ready yet — this shop does not sell.',
    'noShop' => 'There is no shop configuration yet. Create one, then select it at a start point in the site structure under "Consho".',
    'createShop' => 'Create a shop configuration',
    'status' => ['done' => 'done', 'open' => 'open', 'attention' => 'check'],
    'steps' => [
        'shop' => ['Shop and start point', 'A published start point in the site structure names this shop configuration and a currency under "Consho".'],
        'basics' => ['Basic data', 'Home country, invoice sender and the other details of the shop configuration.'],
        'mail' => ['Mail delivery', 'An outgoing mailbox for order confirmations and invoices, selected in the shop configuration.'],
        'shipping' => ['Shipping method', 'At least one published shipping method with a tax class and prices, selected in the shop configuration.'],
        'payment' => ['Payment method', 'At least one published payment method, selected in the shop configuration.'],
        'numberRanges' => ['Number ranges', 'Order numbers always; invoice and cancellation numbers as soon as a payment method issues invoices; invoice corrections where there is a withdrawal period.'],
        'shopPages' => ['Shop pages', 'Basket, checkout, order, shipping costs, withdrawal form and cancellation — one page each with its element, linked at the start point.'],
        'legalTexts' => ['Legal texts', 'Terms, withdrawal instructions, privacy policy and warranty notice are yours to write; select them at the start point under "Consho".'],
    ],
    'links' => [
        'shopEdit' => 'Edit the shop configuration',
        'pages' => 'Site structure',
        'rootEdit' => 'Edit start point "%s"',
        'mail' => 'Mail delivery',
        'shipping' => 'Shipping methods',
        'payment' => 'Payment methods',
        'numberRanges' => 'Number ranges',
    ],
    'others' => 'Further warnings of this shop',
    'adminOnly' => 'Creating at the push of a button is available to administrators.',
    'createRanges' => 'Create the missing number ranges',
    'rangesCreated' => 'Number ranges created: %s. The prefixes can be changed in the mask.',
    'rangesNone' => 'No number range was missing.',
    'createPages' => 'Create the missing shop pages',
    'pagesCreated' => 'Created below "%s": %s. The pages are published and not in the navigation.',
    'pagesNone' => 'No shop page was missing.',
    'unpublishedOnly' => 'Only start points that are not published yet are concerned — this blocks nothing.',
    'pageTitles' => [
        'cart' => 'Basket',
        'checkout' => 'Checkout',
        'order' => 'Your order',
        'shippingCosts' => 'Shipping costs',
        'withdrawal' => 'Withdraw from contract',
        'cancellation' => 'Cancel contracts here',
    ],
    'cache' => 'Page cache',
    'cacheHelp' => 'Optional, never blocks. Whether catalogue pages are cached is your decision.',
    'cacheOff' => 'The page cache is off at start point "%s" (Contao default): every page is computed on every request. With a proxy such as Cloudflare or Varnish in front, that is right.',
    'cacheOn' => 'The page cache is on at start point "%s": %d seconds.',
    'cacheEnable' => 'Turn caching on (1 hour)',
    'cacheEnabled' => 'Page cache turned on at start point "%s": 1 hour.',
    'probe' => 'Measure now',
    'probeNote' => 'Measured from the server: two requests without cookies, like a guest. A visitor elsewhere may see a different state.',
    'probeResults' => [
        'cached' => 'served from a cache (%s)',
        'private' => 'private — not cached anywhere',
        'public' => 'cacheable, no cache hit visible (a proxy may remove its traces)',
        'failed' => 'Measurement not possible: %s',
    ],
    'probeBudget' => 'The time for this measurement is used up (15 seconds). Please measure again.',
];

$GLOBALS['TL_LANG']['CONSHO']['taxReport'] = [
    'back' => 'Back to the dashboard',
    'intro' => 'Distance sales and electronic services to consumers in other EU countries, net, per business (shops with the same VAT ID). Up to the threshold of %s in the current and the previous year the VAT of the shop\'s country applies. Sales outside Consho count as well; only euro is counted against the threshold.',
    'atDestinationOn' => 'Tax at destination: switched on',
    'atDestinationOff' => 'Tax at destination: switched off',
    'colYear' => 'Year',
    'colCounted' => 'Distance sales net',
    'colShare' => 'Share of the threshold',
    'notConverted' => 'not converted',
    'colQuarter' => 'Quarter',
    'colExport' => 'Export (CSV)',
    'summary' => 'Return',
    'records' => 'Records',
    'noShops' => 'No shop configuration yet.',
    'exportNote' => 'The return holds only sales taxed in the customer\'s country. Part A: the quarter\'s sales per country and rate; part B: corrections of earlier quarters per country and original quarter. A sale counts at the earlier of full payment and delivery. Amounts in the order\'s currency — for the return convert to euro at the ECB rate of the last day of the quarter. No authority\'s upload format.',
    'colPart' => 'Part',
    'colCountry' => 'Member state',
    'colRate' => 'Rate',
    'colPeriod' => 'Original period',
    'colCurrency' => 'Currency',
    'colNet' => 'Taxable amount',
    'colTax' => 'VAT',
    'colOrder' => 'Order',
    'colDocument' => 'Document',
    'colKind' => 'Type',
    'colDate' => 'Date',
    'colSupply' => 'Sale as of',
    'colPaid' => 'Paid on',
    'colDelivered' => 'Delivered on',
    'colDestination' => 'Destination country',
    'colItem' => 'Item',
    'supply' => 'Sale',
    'correction' => 'Correction',
];

$GLOBALS['TL_LANG']['CONSHO']['customers'] = [
    'back' => 'Back',
    'shop' => 'Shop',
    'allShops' => 'all shops',
    'search' => 'Search',
    'searchHint' => 'Name or e-mail',
    'apply' => 'Apply',
    'empty' => 'No customers found.',
    'colName' => 'Name',
    'colEmail' => 'E-mail',
    'colKind' => 'Account',
    'colOrders' => 'Orders',
    'colLast' => 'Last order',
    'member' => 'Member',
    'guest' => 'Guest',
    'alsoGuest' => 'also ordered as a guest',
    'limit' => 'Show',
    'openMember' => 'Open member account',
    'noOrders' => 'No order in this shop yet.',
    'colOrder' => 'Order',
    'colDate' => 'Ordered on',
    'colTotal' => 'Total',
    'colAs' => 'Ordered as',
    'filter' => 'Filter',
    'filterShow' => 'Show filters',
    'filterHide' => 'Hide filters',
    'filterLabel' => 'Search and filter options',
    'reset' => 'Reset',
    'opShow' => 'Show customer and orders',
    'noMember' => 'Guest — no member account',
    // D-5: access and erasure.
    'colErasure' => 'Erasure requested',
    'privacyLegend' => 'Access and erasure',
    'privacyHelp' => 'The access copy is a ZIP file with all data of this person (daten.json, machine-readable) and the documents as PDF. Consho does not send it: check the identity and add the information Art. 15(1) GDPR requires.',
    'exportButton' => 'Download access copy',
    'eraseHelp' => 'Orders past their retention period are anonymised at once — numbers and amounts remain. Orders within it stay unchanged and are anonymised when it ends. Baskets, pending confirmations, the Consho fields of the account and complaint notes go at once. Running subscriptions stay until the contract ends and are then anonymised with their orders; cancelling them is up to you. Delete the member account itself under "Members". Cannot be undone.',
    'eraseConfirm' => 'The person asked for erasure, their identity has been checked.',
    'eraseButton' => 'Erase data',
    'eraseUnconfirmed' => 'Please first confirm that erasure was requested and the identity checked. Nothing was erased.',
    'erasedNow' => 'Anonymised: %s.',
    'erasedLater' => 'Within the retention period, erasure noted: %s.',
    'pendingUntil' => '%s (until %s)',
    'pendingNoPeriod' => '%s (no period set)',
    'erasedRunning' => 'Running subscriptions, anonymised once the contract has ended: %s.',
    'erasedAround' => 'Baskets, pending confirmations, the Consho fields of the account and complaint notes are erased.',
];
// Confirming an e-mail address (D-4, K3a) — common, then per purpose.
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['hello'] = 'Hello,';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['validity'] = 'The link is valid for 24 hours.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['notYou'] = 'If you did not ask for this, do not click the link — nothing will change.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['guestOrders']['subject'] = 'Please confirm your e-mail address';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['guestOrders']['intro'] = 'You would like to see in your customer account the orders you placed without an account under the address %s. To do so, confirm that this address is yours: first sign in to the shop with your customer account, then open the link.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmEmail']['guestOrders']['button'] = 'Confirm address';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['orderContext'] = 'The order:';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['operator']['subject'] = 'Withdrawal received: order %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['operator']['intro'] = 'A withdrawal from order %s has been received (%s).';
$GLOBALS['TL_LANG']['CONSHO']['mail']['withdrawal']['operator']['source'] = ['orderPage' => 'through the order page', 'form' => 'through the withdrawal form'];

// The order page (C-10).
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['title'] = 'Your order %s';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['linkRequest'] = [
    'title' => 'Request a link to your order',
    'intro' => 'This link is no longer valid or incomplete. Enter the order number and the e-mail address of the order — we will send a new link to the address of the order.',
    'number' => 'Order number',
    'email' => 'E-mail address',
    'submit' => 'Request a new link',
    'requested' => 'If the details match an order, a new link is on its way to its e-mail address. Please also check your spam folder.',
    'tooMany' => 'You have just requested a link several times. Please try again later.',
    'missing' => 'Please enter the order number and the e-mail address.',
];
$GLOBALS['TL_LANG']['CONSHO']['mail']['orderLink']['subject'] = 'Your link to order %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['orderLink']['intro'] = 'You requested a new link to your order %s. It shows the state of the order and your documents.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['orderLink']['button'] = 'View order';
$GLOBALS['TL_LANG']['CONSHO']['mail']['orderLink']['validity'] = 'The link is valid for 90 days. After that you can request a new one on the order page at any time.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['orderLink']['notYou'] = 'If you did not request this, you can ignore this e-mail. The link was sent to you only.';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['gate'] = [
    'text' => 'Your order %s is ready. Clicking the button also confirms your e-mail address.',
    'button' => 'Show order',
];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['paymentState'] = 'Payment';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['deliveryState'] = 'Delivery';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['paymentStates'] = ['open' => 'open', 'partial' => 'partly paid', 'paid' => 'paid', 'overpaid' => 'overpaid', 'refunded' => 'refunded', 'nothingDue' => 'nothing due'];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['deliveryStates'] = ['open' => 'not yet shipped', 'partial' => 'partly shipped', 'complete' => 'shipped'];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['documents'] = 'Documents';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['cancelled'] = 'cancelled';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['format'] = ['pdf' => 'PDF', 'html' => 'HTML'];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['retrieve'] = ['pdf' => '%s as PDF', 'html' => '%s as HTML'];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['changeBlocked'] = [
    'notOpen' => 'The payment method can no longer be changed because a payment has already been received.',
    'statusLocks' => 'The payment method of this order can no longer be changed.',
    'alreadyChanged' => 'The payment method of this order has already been changed once. For a further change, please contact us.',
    'afterWithdrawal' => 'After a withdrawal the payment method can no longer be changed. For a change, please contact us.',
    'overdue' => 'The invoice is already due; the payment method can no longer be changed. The payment details are above.',
];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['change'] = [
    'title' => 'Change payment method',
    'explanation' => 'An invoice already issued is cancelled and you receive a new one. We also send you the documents by e-mail.',
    'current' => 'current payment method',
    'unavailable' => 'not available for this order',
    'confirm' => 'Yes, I want to change the payment method.',
    'submit' => 'Change payment method',
];
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['notice'] = [
    'changed' => 'The payment method has been changed. The new documents are listed below.',
    'notOpen' => 'The payment method can no longer be changed because a payment has already been received.',
    'statusLocks' => 'The payment method of this order can no longer be changed.',
    'alreadyChanged' => 'The payment method of this order has already been changed once. For a further change, please contact us.',
    'afterWithdrawal' => 'After a withdrawal the payment method can no longer be changed. For a change, please contact us.',
    'overdue' => 'The invoice is already due; the payment method can no longer be changed. The payment details are above.',
    'methodUnavailable' => 'This payment method is not available for your order.',
    'confirmMissing' => 'Please confirm the change.',
    'paymentFailed' => 'The payment could not be started. You can try again or change the payment method.',
    'withdrawn' => 'Your withdrawal has been received. We have sent you the confirmation of receipt by e-mail.',
    'withdrawnUnconfirmed' => 'Your withdrawal has been received and stored. The confirmation of receipt could not be sent by e-mail; we will make up for it. You can see the receipt below.',
    'withdrawClosed' => 'This order can no longer be withdrawn from through this page.',
    'withdrawInvalid' => 'The items given do not match the order. Please check the quantities.',
    'withdrawNothingSelected' => 'Please enter a quantity for at least one item, or choose the whole order.',
    'withdrawNameMissing' => 'Please enter your name.',
];

// The withdrawal function (D-2). The two button labels follow Art. 11a of
// Directive 2011/83/EU — do not reword them.
$GLOBALS['TL_LANG']['CONSHO']['withdrawal'] = [
    'button' => 'Withdraw from contract here',
    'confirm' => 'Confirm withdrawal',
    'title' => 'Withdraw from contract',
    'intro' => 'You can withdraw from your contract here. Check your details and confirm the withdrawal. You will receive a confirmation of receipt by e-mail straight away.',
    'name' => 'Your name',
    'email' => 'E-mail for the confirmation of receipt',
    'order' => 'Order',
    'scope' => 'What would you like to withdraw from?',
    'all' => 'The whole order',
    'items' => 'Single items',
    'quantity' => 'Quantity %s (up to %s)',
    'whole' => '%s (subscription, %s — whole only)',
    'message' => 'Message (optional)',
    'back' => 'Back to the order',
    'expired' => 'The withdrawal period for this order has expired. If you have any questions, you can reach us at %s.',
    'expiredNoContact' => 'The withdrawal period for this order has expired.',
    'received' => 'Withdrawals received',
    'receivedEntry' => '%s, received %s',
    'confirmed' => 'confirmation of receipt sent by e-mail',
    'unconfirmed' => 'confirmation of receipt by e-mail pending',

    // The element for buyers without the order mail (W1, E6).
    'formIntro' => 'Enter your order number, the e-mail address of the order and your name. You will receive a confirmation of receipt by e-mail straight away.',
    'orderNumber' => 'Order number',
    'orderEmail' => 'E-mail address of the order',
    'partOptional' => 'Only part of the order? Then name it here (optional)',
    'notFound' => 'We found no order with this order number and e-mail address. Please check your details.',
    'tooMany' => 'Too many attempts. Please try again in a quarter of an hour.',
    'missing' => 'Please fill in order number, e-mail address and name.',
    'done' => 'Your withdrawal %s has been received. We have sent the confirmation of receipt to the e-mail address of the order.',
    'doneUnconfirmed' => 'Your withdrawal %s has been received and stored. The confirmation of receipt could not be sent by e-mail; we will make up for it.',
    'closed' => 'This order can no longer be withdrawn from through this form.',
    'closedContact' => 'This order can no longer be withdrawn from through this form. If you have any questions, you can reach us at %s.',

    // In the finding "withdrawal period under 14 days" where no delivery countries are chosen.
    'allCountries' => 'all countries',
];
// "My subscriptions" (Abo-7, P60).
$GLOBALS['TL_LANG']['CONSHO']['mySubscriptions'] = [
    'empty' => 'You have no subscription in this shop.',
    'number' => 'Subscription',
    'product' => 'Product',
    'state' => 'Status',
    'nextBilling' => 'Next billing',
    'actions' => 'Actions',
    'cancel' => 'Cancel',
    'pending' => 'Extraordinary cancellation received, not yet decided',
    // Abo-7b (P67): a new subscription at today's terms; licence and keys stay.
    'renew' => 'Renew licence',
    'renewedBy' => 'renewed by %s',
    'states' => ['active' => 'running', 'suspended' => 'on hold (open invoice)', 'paused' => 'paused until %s', 'ending' => 'cancelled as of %s', 'ended' => 'ended', 'upcoming' => 'starts on %s'],
    // Pausing (P101, P107).
    'pause' => 'Pause',
    'pauseAnnounced' => 'Pause from %s to %s announced',
    'withdrawPause' => 'Withdraw pause',
    'pauseTitle' => 'Pause subscription',
    'pauseIntro' => 'The pause begins on %s, at the end of the current period. Nothing is billed meanwhile, and access rests. The term moves by the pause.',
    'pauseLength' => 'Length',
    'pauseOptionOne' => '%d month, until %s',
    'pauseOptionMany' => '%d months, until %s',
    'pauseConfirm' => 'Confirm pause',
    'pauseWithdrawHint' => 'Until it begins you can withdraw the pause under "My subscriptions".',
    'back' => 'Back',
    'notice' => [
        'notFound' => 'We did not find this subscription in your account.',
        'noOrderPage' => 'This shop has no order page set up.',
        'renewalUnavailable' => 'This licence cannot be renewed at the moment.',
        'paused' => 'The pause is announced. We have sent you the confirmation by e-mail.',
        'pauseWithdrawn' => 'The pause is withdrawn. Your subscription carries on as before.',
        'pauseUnavailable' => 'This subscription cannot be paused like this at the moment.',
        'expired' => 'This confirmation page can no longer be opened. Your cancellation is stored; you have received the confirmation of receipt by e-mail.',
    ],
];
// Cancellation (Abo-7, § 312k BGB). The German button labels are set by law.
$GLOBALS['TL_LANG']['CONSHO']['cancellation'] = [
    'button' => 'Cancel contracts here',
    'confirm' => 'Cancel now',
    'formIntro' => 'Here you can cancel your subscription. You will receive a confirmation of receipt by e-mail at once.',
    'kind' => 'Kind of cancellation',
    'kinds' => ['ordinary' => 'ordinary cancellation', 'extraordinary' => 'extraordinary cancellation (for good cause)'],
    'reason' => 'Reason (extraordinary cancellation only)',
    'name' => 'Your name',
    'subscriptionNumber' => 'Subscription number',
    'email' => 'E-mail address of the subscription',
    'contract' => 'Contract',
    'date' => 'Date the contract is to end (optional)',
    'dateHint' => 'Leave empty: at the earliest possible date.',
    'earliestIs' => 'Earliest possible for an ordinary cancellation: %s.',
    'laterBilled' => 'If your date lies after it, billing periods that begin before the end of contract are still billed in full.',
    'confirmationBy' => 'We send the confirmation of receipt to the subscription\'s e-mail address.',
    'done' => 'Your cancellation has been received.',
    'mailSent' => 'We have sent the confirmation of receipt to %s.',
    'mailNotSent' => 'The confirmation of receipt could not be sent by e-mail; we will catch up on it. Your cancellation is stored.',
    'save' => 'Please save or print this page as proof of your cancellation. It can be opened for 90 days; the confirmation of receipt by e-mail stays.',
    'expired' => 'This confirmation page can no longer be opened. Your cancellation is stored; you have received the confirmation of receipt by e-mail.',
    'error' => [
        'missing' => 'Please fill in subscription number, e-mail address and name.',
        'reasonMissing' => 'Please give the reason for an extraordinary cancellation.',
        'dateInvalid' => 'Please enter the date as a date, or leave the field empty.',
        'notFound' => 'We found no subscription for this subscription number and e-mail address. Please check your details.',
        'tooMany' => 'Too many attempts. Please try again in a quarter of an hour.',
        'alreadyEnding' => 'This subscription has already been cancelled and ends on %s.',
        'ended' => 'This subscription has already ended.',
    ],
];
// Online payment (C-11). The page says only what it can prove (Michael, 2026-09-26).
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['online'] = [
    'confirmed' => 'Payment received (confirmed by the payment provider).',
    'reported' => 'The payment provider reports the payment as completed. As soon as the confirmation arrives, it will show here.',
    'none' => 'No payment has been received for this order yet.',
    'failed' => 'The payment failed.',
    'cancelled' => 'The payment was cancelled.',
    'retry' => 'Try again',
];
$GLOBALS['TL_LANG']['CONSHO']['invoice']['regenerated'] = 'Regenerated from the stored invoice data. The file archived at issue was unavailable or did not match its checksum.';
$GLOBALS['TL_LANG']['CONSHO']['invoiceArchiveFolder']['missing'] = 'is not selected or no longer exists';
$GLOBALS['TL_LANG']['CONSHO']['invoiceArchiveFolder']['public'] = 'is public (through "%s")';

// Versandkosten (E-6, V5).
$GLOBALS['TL_LANG']['CTE']['consho_shipping_costs'] = ['Shipping costs', 'The shipping methods of the shop with countries, prices and limits — from the data of the shipping methods, not typed by hand. Belongs on the shipping costs page chosen at the start point.'];
$GLOBALS['TL_LANG']['CONSHO']['shippingCosts'] = [
    'none' => 'No shipping methods are set up at the moment.',
    'allCountries' => 'To every delivery country of the shop.',
    'countries' => 'To: %s.',
    'calculated' => 'The price is calculated at checkout.',
    'noAmount' => 'Price at checkout',
    'weight' => 'Weight',
    'price' => 'Price',
    'upTo' => 'up to %s kg',
    'proportional' => 'VAT on shipping follows the tax rates of the goods ordered; the gross amount at checkout may therefore differ.',
    'limits' => 'Per piece at most: %s.',
    'limitLength' => '%s cm longest side',
    'limitGirth' => '%s cm girth',
    'limitWeight' => '%s kg',
    'cod' => 'Cash on delivery available.',
    'codWithFee' => 'Cash on delivery available, plus a cash on delivery fee shown at checkout.',
];

// Telefon für den Paketdienst (E-6, V6).
$GLOBALS['TL_LANG']['CONSHO']['checkout']['problem']['phoneMissing'] = 'The carrier of this shipping method needs the recipient\'s phone number.';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['shippingPhone'] = 'Phone for the carrier';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['shippingPhoneHint'] = 'The carrier uses it to announce the delivery.';

// The delivery for a basket with digital lines only (F-2, F14).
$GLOBALS['TL_LANG']['CONSHO']['checkout']['electronicDelivery'] = 'Electronic delivery';

// Consent to the early start of digital content (F-3, F15), close to § 356
// Abs. 6 BGB and § 18 Abs. 1 Z 11 FAGG. The operator can change it like any
// label through their own language file and is responsible for the wording.
$GLOBALS['TL_LANG']['CONSHO']['checkout']['digitalConsent'] = 'I expressly agree that you begin supplying the digital content before the withdrawal period ends. I acknowledge that I lose my right of withdrawal once the supply begins.';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['digitalConsentHint'] = 'Optional. Without it, you receive the digital content after the withdrawal period.';
// Abo-Nachlese (P75): net group lines on the review page.
$GLOBALS['TL_LANG']['CONSHO']['checkout']['reviewNet'] = 'plus VAT';
// Abo-7b: a renewal in the basket.
$GLOBALS['TL_LANG']['CONSHO']['checkout']['renewalConsentHint'] = 'For a licence renewal this applies to your existing licence too: without consent it continues in the new subscription only after the withdrawal period. If your current subscription ends before, the licence cannot be used until then.';
// For products whose withdrawal works like a service (Abo-2, P14, A12), close
// to § 10 FAGG and § 356 Abs. 5 Nr. 2 BGB, read in the primary text on
// 2026-09-29. The operator can change it and is responsible for the wording.
$GLOBALS['TL_LANG']['CONSHO']['checkout']['digitalConsentService'] = 'I expressly request that you begin providing the service before the withdrawal period ends. I am aware that I lose my right of withdrawal once you have fully performed the contract.';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['digitalConsentHintService'] = 'Optional. Without this request we begin only after the withdrawal period.';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['digitalConsentHintMixed'] = 'Optional. Without it, you receive the digital content and we begin the services only after the withdrawal period.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digitalConsentService'] = 'Before the contract was performed, you expressly requested that we begin providing the service before the withdrawal period ends, and confirmed that you lose your right of withdrawal once we have fully performed the contract.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digitalConsent'] = 'Before the contract was performed, you expressly agreed that we begin supplying the digital content before the withdrawal period ends, and acknowledged that you lose your right of withdrawal once the supply begins.';

// Subscription terms on product page, basket, checkout and order line (Abo-2,
// P15). SubscriptionTerms joins them with " · ". `day`/`days` follow "with"
// ("with 14 days' notice"), `dayNominative`/`daysNominative` stand alone
// ("notice period 30 days").
$GLOBALS['TL_LANG']['CONSHO']['subscription'] = [
    'once' => '%s one-off',
    'perMonth' => '%s / month',
    'perYear' => '%s / year',
    'perMonths' => '%s / %s months',
    'term' => 'Term %s',
    'month' => '%s month',
    'months' => '%s months',
    'year' => '%s year',
    'years' => '%s years',
    'day' => '%s day\'s',
    'days' => '%s days\'',
    'dayNominative' => '%s day',
    'daysNominative' => '%s days',
    'ends' => 'ends after that',
    'sameTerm' => 'renews for %s at a time, notice period %s',
    'indefinite' => 'then open-ended, cancellable at any time with %s notice',
    'oneTimeSale' => 'One-time purchase',
    'choose' => 'Plan',
    'accountRequired' => 'A subscription needs a customer account. You sign in or register at the checkout.',
    // Abo-7b (P70).
    'renewalRefused' => 'The licence of subscription %s can no longer be renewed. Please remove the line from your basket.',
];

// Entitlements on the order page (F-5).
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['entitlements'] = 'Digital content';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['entitlementsServices'] = 'Services';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['entitlementsMixed'] = 'Digital content and services';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['serviceUnlocked'] = 'unlocked';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['serviceRunning'] = 'running';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['servicePerformed'] = 'performed on %s';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['validUntil'] = 'valid until %s';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['inactive'] = 'no longer valid';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['resting'] = 'on hold until the open invoice is paid';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['download'] = 'Download';
$GLOBALS['TL_LANG']['CONSHO']['orderPage']['downloadOf'] = 'Download %s';

// Mails about digital content (F-7, F20).
$GLOBALS['TL_LANG']['CONSHO']['mail']['paymentReceived']['ready'] = [
    'digital' => 'Your digital content is now ready on your order page.',
    'service' => 'Your services are now unlocked; you can see them on your order page.',
    'mixed' => 'Your digital content is now ready on your order page, and your services are unlocked.',
];
$GLOBALS['TL_LANG']['CONSHO']['mail']['digitalReleased']['invoice'] = 'Invoice %s is attached to this e-mail.';
// Wertgutscheine (WG4): the mail with the codes, the PDF and the order page.
$GLOBALS['TL_LANG']['CONSHO']['mail']['vouchersIssued'] = [
    'subject' => 'Your gift voucher: order %s',
    'intro' => 'thank you for your order %s. Here is your gift voucher:',
    'attached' => 'Each voucher is attached to this e-mail as a PDF — to print or pass on.',
];
// Wertgutscheine (WG-5, E3): Einlösung. %1$s der Code (…MDSN), %2$s ein Betrag.
$GLOBALS['TL_LANG']['CONSHO']['cart']['voucher'] = [
    'code' => 'Redeem gift voucher',
    'apply' => 'Redeem',
    'remove' => 'Remove',
    'removeLabel' => 'Remove voucher %1$s',
    'later' => 'Vouchers are applied at the checkout.',
    'notForVouchers' => 'Gift vouchers cannot be paid for with gift vouchers.',
    'applied' => 'Gift voucher %1$s',
    'payable' => 'Left to pay',
    'covered' => 'Your vouchers cover the whole amount — no payment method is needed.',
    'change' => 'Change vouchers in the basket',
    'payment' => 'Gift voucher',
    'status' => [
        'usable' => 'Voucher %1$s: balance %2$s',
        'spent' => 'Voucher %1$s is used up.',
        'blocked' => 'Voucher %1$s is blocked.',
        'expired' => 'Voucher %1$s has expired.',
        'otherCurrency' => 'Voucher %1$s is not valid in this currency.',
        'unknown' => 'Voucher %1$s is not valid in this shop.',
    ],
    'after' => 'Afterwards %2$s remain on voucher %1$s.',
];
$GLOBALS['TL_LANG']['CONSHO']['voucher'] = [
    'title' => 'Gift voucher',
    'code' => 'Voucher code',
    'validUntil' => 'Valid until %s',
    'unlimited' => 'Valid without time limit',
    'howTo' => 'Redeem it in the basket under "Redeem gift voucher".',
    'balance' => 'Remaining balance',
    'blocked' => 'blocked',
    'heading' => 'Your gift vouchers',
];
$GLOBALS['TL_LANG']['CONSHO']['mail']['digitalReleased']['digital'] = ['subject' => 'Your digital content: order %s', 'intro' => 'the following from your order %s is now ready on your order page:'];
$GLOBALS['TL_LANG']['CONSHO']['mail']['digitalReleased']['service'] = ['subject' => 'Unlocked: order %s', 'intro' => 'the following from your order %s is now unlocked:'];
$GLOBALS['TL_LANG']['CONSHO']['mail']['digitalReleased']['mixed'] = ['subject' => 'Ready and unlocked: order %s', 'intro' => 'the following from your order %s is now ready or unlocked:'];
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digital']['now'] = 'We provide the digital content of your order on your order page.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digital']['afterPayment'] = 'We provide the digital content of your order on your order page once your payment has arrived.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digital']['afterPeriod'] = 'We provide the digital content of your order on your order page once the withdrawal period ends on %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['digital']['afterBoth'] = 'We provide the digital content of your order on your order page once your payment has arrived and the withdrawal period has ended on %s.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['service'] = [
    'now' => 'We unlock the services of your order; you can see them on your order page.',
    'afterPayment' => 'We unlock the services of your order once your payment has arrived.',
    'afterPeriod' => 'We unlock the services of your order once the withdrawal period ends on %s.',
    'afterBoth' => 'We unlock the services of your order once your payment has arrived and the withdrawal period has ended on %s.',
];
$GLOBALS['TL_LANG']['CONSHO']['mail']['confirmation']['mixed'] = [
    'now' => 'We provide the digital content and unlock the services of your order on your order page.',
    'afterPayment' => 'We provide the digital content and unlock the services of your order once your payment has arrived.',
    'afterPeriod' => 'We provide the digital content and unlock the services of your order once the withdrawal period ends on %s.',
    'afterBoth' => 'We provide the digital content and unlock the services of your order once your payment has arrived and the withdrawal period has ended on %s.',
];

// Block G: availability (G7). The figure only in the basket, where it decides the quantity.
$GLOBALS['TL_LANG']['CONSHO']['stock'] = [
    'available' => 'in stock',
    'few' => 'only a few left',
    'unavailable' => 'currently unavailable',
    'backorder' => 'on order, longer delivery time',
    'onlyLeft' => 'only %s left',
    'availableFrom' => 'available again from %s',
];

// Block G (G12, G15): "notify me when back" — members only.
$GLOBALS['TL_LANG']['CONSHO']['mail']['stockAvailable']['subject'] = 'Back in stock: %s';
$GLOBALS['TL_LANG']['CONSHO']['mail']['stockAvailable']['intro'] = '"%s" is available again. You asked to be told.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['stockAvailable']['button'] = 'To the article';
$GLOBALS['TL_LANG']['CONSHO']['mail']['stockAvailable']['once'] = 'This message comes only once. Your request is done.';
$GLOBALS['TL_LANG']['CONSHO']['mail']['stockAvailable']['confirm'] = 'The link also lets you confirm your e-mail address: signed in with your customer account, on the article\'s page. This works for 24 hours; the article itself stays reachable.';
$GLOBALS['TL_LANG']['CONSHO']['stockNotice'] = [
    'subscribe' => 'Notify me when back',
    'unsubscribe' => 'Stop notifying me',
    'subscribed' => 'You will get an e-mail as soon as the article is available again.',
    'login' => 'Sign in with your customer account to be notified when the article is available again.',
    'result' => [
        'subscribed' => 'You will be notified as soon as the article is available again.',
        'unsubscribed' => 'You will no longer be notified about this article.',
        'available' => 'The article is available again.',
        'login' => 'Please sign in with your customer account first.',
        'confirmed' => 'Your e-mail address is confirmed.',
        'invalid' => 'The confirmation link is no longer valid.',
        'otherAccount' => 'The link belongs to another customer account or another address.',
    ],
    'confirm' => [
        'pending' => 'Confirm your e-mail address here.',
        'button' => 'Confirm e-mail address',
        'login' => 'To confirm your e-mail address, sign in with your customer account and open the link again.',
        'confirmed' => 'Your e-mail address is confirmed.',
        'invalid' => 'The confirmation link is no longer valid. The article is here all the same.',
        'otherAccount' => 'The link belongs to another customer account or another address.',
    ],
];

// Block H (H-4): discount code in the basket. Placeholders: %1$s the code, %2$s
// the amount still missing to the minimum order value.
$GLOBALS['TL_LANG']['CONSHO']['cart']['discount'] = [
    'code' => 'Discount code',
    'apply' => 'Redeem',
    'remove' => 'Remove code',
    'subtotal' => 'Goods',
    'freeShipping' => 'free shipping',
    'status' => [
        'none' => '',
        'applied' => 'The code %1$s is redeemed.',
        'unknown' => 'There is no code %1$s. Please mind upper and lower case.',
        'notActive' => 'The code %1$s is not valid at the moment.',
        'notForYou' => 'The code %1$s does not apply to this purchase.',
        'minimumOrder' => 'The code %1$s needs a minimum order value — %2$s are still missing.',
        'noMatchingGoods' => 'The code %1$s applies to no article in your basket.',
        'exhausted' => 'The code %1$s has already been redeemed and is no longer valid.',
        'superseded' => 'The code %1$s is valid, but another discount saves you more — that one applies.',
        'nothingToSave' => 'The code %1$s waives the shipping costs — this order has none. You keep it for another one.',
    ],
];

// Connect-2, CW-1: versions of order records are history, not a way back.
$GLOBALS['TL_LANG']['CONSHO']['versionFrozen'] = 'Earlier versions of orders, payments, deliveries and their parts can be compared but not restored: that would reset payments received, statuses and locks past their checks. Please enter the value again instead.';
