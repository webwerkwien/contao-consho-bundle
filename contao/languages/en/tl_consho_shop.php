<?php declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_consho_shop']['title'] = ['Name', 'Name of this shop configuration in the back end.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['currencies'] = ['Accepted currencies', 'Which currencies this shop can invoice in. That hangs off the bank account, the payment method and whether a flat shipping rate carries an amount in that currency. Which currency a visitor sees is not set here but on the start page, which picks from this list.'];

// Out of the palette since C-1c, the entry stays: Contao shows the label in
// the listing and under "Details" as well, until the column goes in a later
// release.
$GLOBALS['TL_LANG']['tl_consho_shop']['currency'] = ['Former currency', 'The currency this shop had before C-1c. It was carried over to its start pages and is read nowhere any more; which currencies the shop accepts is under "Currencies".'];
$GLOBALS['TL_LANG']['tl_consho_shop']['country'] = ['Home country', 'The country the shop is established in. Its tax rate turns the net price into the gross price visitors see while no delivery country is known yet. Without a home country the shop shows no prices.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['fallbackLanguage'] = ['Default language', 'The language whose texts live on the record itself. A missing translation falls back to it.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['deliveryCountries'] = ['Delivery countries', 'Where this shop delivers. What is ticked here is offered at checkout. If a country is missing the rate of a tax class in use, it does not disappear — it is reported so the rate can be added.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['mailTransport'] = ['Mail configuration', 'Mailbox and sender of this shop. Several shop configurations may share one.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['operatorName'] = ['Operator name', 'Recipient of the operator copies — not the sender.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['operatorAddress'] = ['Operator e-mail', 'Where operator copies go — an e-mail address, not a postal one. The address for the invoice is under "invoice sender". The sender of the mails lives in the mail configuration.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoicing'] = ['Invoices issued by', 'Consho issues invoices, cancellations and corrections itself. An installed accounting module can take this over or pass Consho\'s documents on. With "Off", invoices are issued outside Consho. Consho keeps the invoice data in every case; order confirmation, delivery note and withdrawal receipt always come from Consho.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoicingOptions'] = ['consho' => 'Consho', 'off' => 'Off', 'missing' => '%s (not installed)'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceCompany'] =['Company name', 'Who issues the invoice, as registered.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceLegalForm'] = ['Legal form', 'Only fill this in when it is not part of the company name already. Optional.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceStreet'] = ['Street and number', ''];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoicePostal'] = ['Postcode', ''];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceCity'] = ['Town', ''];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceCountry'] = ['Country', ''];
$GLOBALS['TL_LANG']['tl_consho_shop']['termsRequired'] = ['Require a terms tick box', 'Buyers have to agree to the terms explicitly before they can buy. Without it the terms are only linked — which is legally sufficient.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['buyButtonLabel'] = ['Buy button label', 'Approved wordings only. Without a clear reference to the obligation to pay, no contract comes about (§ 8 FAGG, § 312j BGB).'];
$GLOBALS['TL_LANG']['tl_consho_shop']['buyButtonLabelRef'] = [
    'paymentRequired' => 'Order with an obligation to pay',
    'chargeable' => 'Order subject to charge',
];

$GLOBALS['TL_LANG']['tl_consho_shop']['vatId'] = ['Own VAT ID', 'This shop\'s own VAT ID, including the country code. Without it a VIES check still returns a result but no consultation number — and that number is the evidence for a tax audit.'];

$GLOBALS['TL_LANG']['tl_consho_shop']['shippingMethods'] = ['Shipping methods', 'Tick what this shop offers and drag to order. The order applies at checkout; the top one available is preselected. A deleted shipping method shows as an unknown option and simply stays unticked.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['paymentMethods'] = ['Payment methods', 'Tick what this shop offers and drag to order. The order applies at checkout; the top one available is preselected. A deleted payment method shows as an unknown option and simply stays unticked.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['unpublishedMethod'] = 'unpublished';

$GLOBALS['TL_LANG']['tl_consho_shop']['afterAddToCart'] = ['After adding', 'Where the visitor goes after putting something in the basket. "Back to the page" needs a mini basket, otherwise they cannot see that anything happened.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['afterAddToCartOptions'] = [
    'cart' => 'to the basket',
    'page' => 'back to the page',
];

$GLOBALS['TL_LANG']['tl_consho_shop']['netPriceGroups'] = ['Customer groups with net prices', 'Members of these groups see prices without VAT, followed by "plus VAT". Guests always see gross prices. This selection only decides what is displayed — which price applies is the customer group price on the product.'];

$GLOBALS['TL_LANG']['tl_consho_shop']['taxRateMissing'] ='It is not recorded how the group %2$s is taxed in %1$s. Without an entry an order from that country fails at the tax calculation.';

$GLOBALS['TL_LANG']['tl_consho_shop']['title_legend'] = 'Name';
$GLOBALS['TL_LANG']['tl_consho_shop']['currency_legend'] = 'Currencies';
$GLOBALS['TL_LANG']['tl_consho_shop']['language_legend'] = 'Language';
$GLOBALS['TL_LANG']['tl_consho_shop']['country_legend'] = 'Countries';
$GLOBALS['TL_LANG']['tl_consho_shop']['tax_legend'] = 'VAT';
$GLOBALS['TL_LANG']['tl_consho_shop']['taxAtDestination'] = ['Tax at destination', 'Charge goods and digital services to consumers in another EU country at the VAT of the customer\'s country (One-Stop-Shop). Switch it on once the 10,000 € threshold is exceeded or you have opted out of it. Switched off, the shop country\'s VAT applies. Applies to every shop with the same VAT ID.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['taxAtDestinationShared'] = 'Tax at destination applies to the whole business — it is now also %s for these shops with the same VAT ID: %s.';
$GLOBALS['TL_LANG']['tl_consho_shop']['taxAtDestinationOn'] = 'switched on';
$GLOBALS['TL_LANG']['tl_consho_shop']['taxAtDestinationOff'] = 'switched off';
$GLOBALS['TL_LANG']['tl_consho_shop']['ageVerifiedGroups'] = ['Customer groups with a verified age', 'Members of these groups may order articles from 16 or 18. The operator verifies the age outside the shop and answers for it — Consho verifies nothing itself. The other way is a shipping method that checks at hand-over; either one is enough. With neither configured, such articles cannot be ordered.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['guestCheckout'] = ['Ordering without an account', 'Allows ordering as a guest. Switched off, only signing in remains — the checkout page then needs a login element on it.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['vatIdNetGroups'] = ['Verified VAT ID → net price groups', 'A member whose VAT ID VIES confirms joins this shop\'s customer groups with net prices automatically — on registration, when saving their personal data or in the back end. Groups are only added, never taken away. Note: the groups also unlock their customer group prices and discounts; VIES only confirms that the number is valid.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['customergroup_legend'] = 'Customer groups';
$GLOBALS['TL_LANG']['tl_consho_shop']['customerGroup'] = ['Base group of the customers', 'Accounts without an order appear under "Customers" through this group; members with an order and guests come from the orders. Create the group under "Members → Groups" and set the registration to it.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['methods_legend'] = 'Shipping and payment';
$GLOBALS['TL_LANG']['tl_consho_shop']['status_legend'] = 'Automatic order status';
$GLOBALS['TL_LANG']['tl_consho_shop']['statusAfterPayment'] = ['Status after full payment', 'Set as soon as the payment is settled. Empty: no automatic change.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['statusAfterDelivery'] = ['Status after full delivery', 'Set as soon as all goods are reported shipped.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['statusWhenSettled'] = ['Status when everything is settled', 'Set as soon as payment and delivery are settled. Takes precedence over the other two. No change is ever made out of a status that cancels, completes or locks changes.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['checkout_legend'] = 'Checkout';
$GLOBALS['TL_LANG']['tl_consho_shop']['withdrawal_legend'] = 'Withdrawal';
$GLOBALS['TL_LANG']['tl_consho_shop']['privacy_legend'] = 'Retention and data protection';
$GLOBALS['TL_LANG']['tl_consho_shop']['retentionYears'] = ['Retention period (years)', 'How long orders and documents with customer data are kept, counted from the end of the calendar year of the latest document. After that the customer data is anonymised; numbers and amounts remain. You set the period — for reference: Austria 7 years (§ 132 Abs. 1 BAO, § 212 Abs. 1 UGB), Germany 8 years for accounting vouchers (§ 147 Abs. 1 Nr. 4 and Abs. 3 AO, § 257 Abs. 1 Nr. 4 and Abs. 4 HGB).'];
$GLOBALS['TL_LANG']['tl_consho_shop']['autoAnonymize'] = ['Anonymise automatically after the period', 'Anonymises the customer data of every order whose retention period has ended, without a request (storage limitation, Art. 5(1)(e) GDPR). Off: only orders for which erasure was requested.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['withdrawalDays'] = ['Withdrawal period (days)', 'Consho uses it to estimate until when a withdrawal is in time (end of window). Statutory in the EU and the EEA: 14 days. 0 switches the function off — for a shop that only delivers outside the EU, say.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['withdrawalGraceDays'] = ['Delivery allowance (days)', 'The period starts when the goods are received; Consho only knows the shipping date. The end of the window is therefore the end of the day "last shipment + period + allowance". A withdrawal after it is still taken and marked.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceLogo'] = ['Logo (invoice and mails)', 'PNG or JPEG, at the top of the invoice and of every mail to customers (embedded, at most 200 px wide; for mails preferably under 100 KB). Every invoice remembers the file and its checksum: replace or delete the logo later and regenerated old invoices print none — no logo rather than a different one.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['deliveryDaysMin'] = ['Delivery time from (working days)', 'Earliest delivery, counted in working days from the invoice date (Mon–Fri, public holidays are not deducted). A prepayment invoice is issued before shipping and therefore states the expected delivery period.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['deliveryDaysMax'] = ['Delivery time to (working days)', 'Latest delivery in working days. Empty or equal to "from" gives a single day.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['remainderDeliveryDays'] = ['Remainder after a partial delivery (working days)', 'Default: after a partial delivery the invoice names the first parcel and the day the rest is expected — this many working days later. Can be overridden per order. 0: no day for the rest.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceFooter'] = ['Footer (invoice and mails)', 'Printed below every invoice (frozen when it is issued) and below every mail to customers, after company and address: company register, court, bank details, notes. Consho presets no text — what an invoice must say depends on legal form and country.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceReverseChargeNote'] = ['Reverse charge note', 'Printed only on invoices with a reverse charge, frozen when issued. The operator is responsible for the wording.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceContactEmail'] = ['Contact e-mail for invoices', 'Named in the e-invoice as the seller\'s contact. Required for e-invoices.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['eInvoice'] = ['E-invoice (ZUGFeRD)', 'Invoices, cancellations and corrections to business customers also carry the machine-readable data under EN 16931 (ZUGFeRD, profile EN 16931) in the PDF. Between businesses in Germany this becomes mandatory step by step from 1 January 2027 (§ 14, § 27 Abs. 38 UStG); consumers never get an e-invoice. Needs company name, country, own VAT ID and contact e-mail.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['eInvoiceIncomplete'] = 'E-invoices need these details, please fill them in first: %s.';
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceArchive'] =['Also archive invoices', 'Stores every PDF in a protected folder when it is issued. Retrieval then delivers the stored file instead of regenerating it.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceArchiveFolder'] = ['Archive folder', 'A folder in files/ that must not be public.'];
$GLOBALS['TL_LANG']['tl_consho_shop']['invoiceLogoCmyk'] = 'The logo is saved in the CMYK colour model (typical of print files). Invoices are generated as PDF/A, which cannot embed a CMYK image — the invoice could not be created. Please save the logo as RGB (in any image editor under "Mode" or "Colour space") and upload it again.';
$GLOBALS['TL_LANG']['tl_consho_shop']['archiveFolderParentPublic'] = 'The folder “%1$s” is public, and so is every folder inside it. Invoices contain personal data — choose a folder outside it, or protect “%1$s” in the file manager yourself.';
$GLOBALS['TL_LANG']['tl_consho_shop']['archiveFolderProtected'] = 'The archive folder “%s” was public and is now protected.';
$GLOBALS['TL_LANG']['tl_consho_shop']['invoice_legend'] = 'Invoice sender';
$GLOBALS['TL_LANG']['tl_consho_shop']['mail_legend'] = 'E-mail';

$GLOBALS['TL_LANG']['tl_consho_shop']['new'] = ['New shop configuration', 'Create a shop configuration'];
$GLOBALS['TL_LANG']['tl_consho_shop']['status'] = ['Order statuses', 'Edit the order statuses of shop configuration ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shop']['numbers'] = ['Number ranges', 'Edit the number ranges of shop configuration ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shop']['setup'] = ['Setup', 'Show the setup steps of shop configuration ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shop']['edit'] = ['Edit', 'Edit shop configuration ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shop']['copy'] = ['Duplicate', 'Duplicate shop configuration ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shop']['delete'] = ['Delete', 'Delete shop configuration ID %s'];
$GLOBALS['TL_LANG']['tl_consho_shop']['show'] = ['Details', 'Show details of shop configuration ID %s'];
