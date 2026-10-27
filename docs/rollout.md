# Rollout

Consho creates its own tables and extends `tl_page`. Contao derives the schema from the DCA files; `contao:migrate` creates the columns.

## The order is cache → migrate → cache

```bash
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
vendor/bin/contao-console cache:clear
```

**Not** migrate → cache. In the `prod` environment Contao reads the DCA from the cache. As long as the old cache is in place, `contao:migrate` does not know the new fields — the dry run then calmly reports "All migrations completed", although not a single column exists.

### 🔴 `--no-interaction` **adds, but never deletes**

A renamed field produces two changes: create the new column, remove the old one. `contao:migrate --no-interaction` executes **only the first** and still reports "All migrations completed" — the `DROP` lines are in the output, but below them it says "Executed 0 SQL queries".

After a field rename, the old and the new column stood side by side, with the old values still in the old one.

```bash
vendor/bin/contao-console contao:migrate --no-interaction --with-deletes
```

> ⚠️ **Copy the values over before `--with-deletes`.** An `UPDATE tabelle SET neu = alt` takes one line — afterwards the old column is gone, and its content with it. The right order is: migrate (the new column appears) → copy the values → `--with-deletes`.
>
> Cross-check that really nothing was left over:
>
> ```sql
> SELECT COLUMN_NAME FROM information_schema.COLUMNS
> WHERE TABLE_NAME = 'tl_…' AND COLUMN_NAME IN ('alt', 'neu');
> ```

## The web server's opcode cache

If the web server does not revalidate its OPcache (`opcache.validate_timestamps = 0` or a long
`revalidate_freq`), the web keeps running the **old** version of every changed class after an update,
while the console — `cache:warmup`, `contao:migrate` — already has the new state. Nothing fails,
it just looks as if the change had no effect. Remedy: after the update, clear the opcode cache
(Contao Manager → Wartung (Maintenance)) or restart PHP-FPM.

## Count afterwards

Since Block I, one command tells whether every shop still sells — exit code 1 as soon as one does not,
with the reasons below:

```bash
vendor/bin/contao-console consho:check
```

The dry run does not lie, it just does not see anything. Check:

```sql
SELECT COUNT(*) FROM information_schema.COLUMNS
WHERE TABLE_NAME = 'tl_page' AND COLUMN_NAME = 'consho_shop';
```

Expected: 1

## The window between step 1 and 2

After the first `cache:clear`, Contao knows the fields, but the database does not yet.

| | |
|---|---|
| Back end | 🔴 editing a start page throws an SQL error — the form queries the columns by name |
| Front end | 🔴 no longer unaffected since C-1a — product lists query `tl_consho_product_list_price.country` and the per-minute boundary cron (`ListPriceBoundaryCron`) queries `tl_consho_shop.country`; both columns are still missing, both throw an SQL error until `contao:migrate` has run |

The window is short, but real. On customer installations, schedule it for off-peak hours or briefly enable maintenance mode.

## Home country and list prices per country (C-1a)

After deploying C-1a, **no shop shows prices until its home country is set** — without a home country there is no gross price. The back-end start page warns about the missing home country ("Heimatland fehlt").

1. Cache → migrate → cache as above. `ListPriceMarketMigration` rebuilds list prices and visibility once and sets the fingerprint of the markets in doing so — the first save of a page afterwards does **not** rebuild again.
2. Check the unique key — it changes from `(pid, currency)` to `(pid, currency, country)`:

   ```sql
   SHOW INDEX FROM tl_consho_product_list_price WHERE Non_unique = 0;
   ```

   Expected: `PRIMARY` and an index over `pid`, `currency`, `country`. `contao:migrate` drops the old index in the regular way, like any other schema change. Anyone who declines the schema change with "no" in the interactive run declines the **whole list** — then the `country` column is missing too, and the migration simply waits. Next to an existing `country` column, the old index only remains if the schema change went through **partially** (for instance a failed `DROP INDEX` or a manual intervention). In that case `ListPriceMarketMigration` aborts with a message instead of silently emptying the projection — otherwise the old key would hit `INSERT … ON DUPLICATE KEY` and mix up the countries. Run `contao:migrate` again so that it drops the index; after that this migration runs through on its own.
3. In every shop configuration, enter the home country under "Länder" (Countries) and save — saving calculates the prices of this market.
4. Cross-check:

   ```sql
   SELECT COUNT(*) FROM tl_consho_product_list_price WHERE country = '';
   ```

   Expected: 0. In addition, `consho:price:verify` and `consho:visibility:verify` without deviations.

> ⚠️ **Tax rates now affect the lists.** When a rate begins or ends, `ListPriceBoundaryCron` switches the gross price of all articles of that tax class. For the standard class that is the whole catalogue in one run — so let Contao's cron run from the command line, not through the web cron with its time limit.

## Price input and removal of `gross` (C-1b)

1. Cache → migrate → cache as above. `contao:migrate --no-interaction` creates `inputCountry` and `inputMode`. Existing price rows get "netto" (net) and open in the form with their net amount — the value that has been used for calculation and display since C-1a. There is no migration of the price rows and no finding for old rows whose gross amount did not match the net amount.

   **Check beforehand, read-only:** `SELECT id, pid, currency, net FROM tl_consho_price WHERE tstamp = 0;` — such rows (created outside the back end, for instance) do not count for any price calculation from C-1b on. Decide on each one individually: open and save it in the back end, or delete it. Rows that were never saved, left behind by a closed browser session, show up in the list as "EUR 0.0000 netto" but have no effect anywhere — Contao's normal behaviour.
2. The `gross` column stays until deletions are explicitly allowed — `--no-interaction` skips it. First read what would be deleted:

   ```bash
   php bin/console contao:migrate --dry-run --with-deletes --no-interaction --env=prod
   ```

   If it lists **only** the deletion of `tl_consho_price.gross`, run the same command without `--dry-run`. If it lists further `DROP` lines, do **not** run it — they do not belong to this step and need a decision of their own.
3. **Field permissions of user groups:** The fields `net` and `gross` no longer exist in the form. Groups with restricted field permissions need the fields **Währung, Eingabe als, Betrag and Land together** (Currency, Enter as, Amount, Country) for `tl_consho_price` — otherwise Contao hides the input, and a new price row cannot be saved (message "Ohne Betrag …").
4. A price row in a currency that no shop carries is rejected by the form on save ("In der Währung … verkauft kein Shop") — choose a currency of a shop first. A price row that was created but never saved (`tstamp` 0) does not count for any price calculation.

## Shipping and payment methods (C-2)

1. Cache → migrate → cache as above. `contao:migrate --no-interaction` creates `tl_consho_shipping_method`, `tl_consho_shipping_price`, `tl_consho_payment_method`, the columns `shippingMethods`/`paymentMethods` on `tl_consho_shop`, four columns plus two indexes on `tl_consho_order`, and `instructions` on `tl_consho_translation`. Nothing is deleted.
2. **`symfony/validator`** is now in the bundle's `composer.json`. It comes with `contao/core-bundle` anyway; a `composer update` is not needed for it.
3. **Setup, in this order:** a tax class exists → create a shipping method (the tax class is mandatory, even with "anteilig" (proportional)) → amount per shop currency → create a payment method → publish both → tick and order them in the shop configuration. The start page reports a selected shipping method without an amount in the shop currency.
4. **Field permissions of user groups:** The same applies to `tl_consho_shipping_price` as to `tl_consho_price` — allow **Währung, Eingabe als, Betrag and Land together**. The modules "Versandarten" (Shipping methods) and "Zahlungsarten" (Payment methods) must be explicitly allowed for groups without admin rights; likewise, with restricted field permissions, the fields of the payment method including the bank details (Kontoinhaber, IBAN, BIC, Bank) and, on the shop configuration, "Versandarten" and "Zahlungsarten".
5. A method that occurs in an order has a greyed-out delete button; when deleting via "Mehrere bearbeiten" (Edit multiple) it is silently skipped. Switch it off via "Veröffentlicht" (Published).
6. When copying a shipping method or a product, amount or price rows that were never saved remain drafts (`tstamp` 0) and appear in the list like saved rows — they count nowhere. To clean up: read-only `SELECT id, pid, currency FROM tl_consho_shipping_price WHERE tstamp = 0;` (likewise `tl_consho_price`), then open and save them in the back end, or delete them.

## Currency on the start page (C-1c)

> ⚠️ **An existing shop behaves exactly as before afterwards.** The migration writes its currency to all of its start pages and makes it its only allowed one. A shop that carries one currency sees no new field.

1. Cache → migrate → cache as above. `contao:migrate --no-interaction` creates `currencies` on `tl_consho_shop`, `consho_currency` on `tl_page` and `currency` on `tl_consho_product_visibility` (together with a new unique key and three changed indexes). It also changes the column default of `currency` on `tl_consho_price`, `tl_consho_shipping_price` **and `tl_consho_shop`** from `EUR` to empty — three `ALTER TABLE`. **Nothing is deleted** — `tl_consho_shop.currency` stays, see step 5.

2. **Between upload and `contao:migrate` the site is not usable — this is not a maintenance window of hours.** The columns are missing at that moment, and the code queries them:

   | Path | in the window |
   |---|---|
   | Product list, filter, sitemap | **HTTP 500** (`ProductQuery` compares `m.currency`) |
   | Back-end start page | **HTTP 500** (the finding sources read `currencies` and `consho_currency`) |
   | Boundary cron | fails |
   | Detail page | 200, without price |
   | Basket, mini basket | empty, but clean |

   That the basket and the resolver handle the window cleanly is not the whole picture — **they are the two paths that were specifically built for it.** Cache → migrate → cache in one go, not spread over a lunch break.

   > **C-3b has the same window**, and there it is explained more briefly: the unique key of
   > `tl_consho_cart` moves from `(tokenHash)` to `(tokenHash, shop)`. Until `contao:migrate` has run,
   > the old, narrower key is still in place — a visitor with a basket in shop A who clicks
   > "In den Warenkorb" (Add to basket) in shop B gets an **HTTP 500** instead of a 303 in this window.
   > **Only affects installations with at least two shops.** The change needs no migration:
   > `contao:migrate` swaps indexes itself, `CommandCompiler` only protects tables and
   > columns. And it does not fail on existing data — what was unique under `(tokenHash)` is
   > still unique under `(tokenHash, shop)`.

3. **Count, three queries (read-only):**

   ```sql
   SELECT COUNT(*) FROM tl_page WHERE type = 'root' AND consho_shop > 0;
   SELECT COUNT(*) FROM tl_page WHERE type = 'root' AND consho_shop > 0 AND consho_currency <> '';
   SELECT id, title, currency, currencies FROM tl_consho_shop;
   ```

   The first two numbers must be **equal**, and every shop must carry in `currencies` the currency that is in `currency` — **except in three cases that the migration deliberately leaves out:**

   | Left out | because |
   |---|---|
   | A start page whose shop has been **deleted** | there is no one whose currency it could inherit |
   | A shop with an **empty** legacy column | there is nothing to transfer |
   | A shop whose `currencies` had **already been maintained** | a choice is not replaced by the legacy column |

   Check the first two in advance — then you know before the deploy whether the numbers can be equal at all:

   ```sql
   SELECT id, title, consho_shop FROM tl_page
    WHERE type = 'root' AND consho_shop > 0
      AND consho_shop NOT IN (SELECT id FROM tl_consho_shop);
   SELECT id, title FROM tl_consho_shop WHERE currency = '';
   ```

4. **Field permissions of user groups:** explicitly allow `currencies` on the shop configuration and `consho_currency` on the start page.

5. **`tl_consho_shop.currency` is removed in a later release.** The DCA no longer defines the field since the release after 0.1.14. Once step 3 has shown the values arrived, `contao:migrate --dry-run --with-deletes` proposes dropping the column, and `contao:migrate --with-deletes` drops it. 🔴 **Coming straight from a version before C-1c, run `contao:migrate` first without `--with-deletes`:** the migration that copies the currency needs `consho_currency` on `tl_page`, which only the schema update creates — and with `--with-deletes` that same pass would drop the old column before the migration has read it. The same procedure as for `gross` in C-1b.

### Introducing another currency — in this order

**Without step 2 the price form rejects the new currency, and that looks like a bug.** The form only offers currencies that are also sold in; a market only comes into being when a start page carries the currency.

1. In the shop configuration, additionally tick the currency under **Währungen** (Currencies).
2. Create or copy the start page, choose the new currency there — **and leave it unpublished.** That is enough: the market query deliberately does **not** filter by "published", so that an unpublished start page does not take the prices of its currency out of the projection.
3. Maintain prices. Now the form accepts the currency.
4. Wait until the completeness finding on the start page goes quiet — it counts the products that do not yet have a price in this currency.
5. Publish the start page.

> 🔴 **Deselecting a currency on the shop that a start page still carries goes through — and silently.** There is no lock for it: the check on the start page only runs when someone saves there, and if only one currency is left, the field even disappears from the form. The start page then keeps selling in a currency that its shop no longer carries.
>
> That is why the back-end start page reports it: **a start page sells in a currency that its shop does not accept** (warning). **First switch the start page over, then deselect the currency** — the message is the fallback, not the intended way.

## Basket (C-3)

1. Cache → migrate → cache as above. `contao:migrate --no-interaction` creates `tl_consho_cart` and `tl_consho_cart_item`, plus `afterAddToCart` on `tl_consho_shop` and `consho_cartPage` on `tl_page`. Nothing is deleted.
2. **Setup:** On the **start page**, choose the basket page (legend "Consho", next to the shop configuration) — that page needs a **Warenkorb** (Basket) element. In the shop configuration under "Checkout" you set where the visitor goes after adding; by default to the basket.
3. **Field permissions of user groups:** explicitly allow `afterAddToCart` on the shop configuration and `consho_cartPage` on the start page. The two new content elements (`consho_cart`, `consho_mini_cart`) are in the "Consho" category and must be allowed for groups without admin rights.
4. The basket tables have **no back-end module** — on purpose. Baskets are transient and carry personal data. Read-only SQL is enough to look at them: `SELECT id, member, shop, state, createdAt, updatedAt FROM tl_consho_cart;`

### 🔴 Without a configured Contao cron, nobody cleans up

The clean-up cron runs **daily** and deletes open baskets after 30 days, converted ones after 7. Both are fixed values in the code, deliberately without a setting.

But it only runs if Contao's cron is triggered on this installation at all — via the web cron, `contao:cron` from the command line, or a service. **If that does not happen, the installation collects baskets without end** — and from C-5 on, they contain addresses, the result of the VAT ID check and a date of birth. Nothing points to it; it only shows when someone looks into the table.

Cross-check: `SELECT COUNT(*), MIN(FROM_UNIXTIME(updatedAt)) FROM tl_consho_cart;` — if the oldest entry is more than 30 days old, the cron is not running.

### 🔴 The `Origin` check depends on `trusted_proxies`

"In den Warenkorb" (Add to basket) runs through its own route that deliberately does not check Contao's request token (the catalogue page is cached in the shared cache, a token in it would belong to another visitor). In its place there is a comparison of the `Origin` header against `Request::getSchemeAndHttpHost()` — and that depends on Symfony's `trusted_proxies`:

| Setting | Consequence |
|---|---|
| too broad | An attacker sets `X-Forwarded-Host`, the comparison becomes self-referential and thus **worthless** |
| not set, but a proxy in front | The check rejects **everything** — nobody can put anything into the basket |

**Both fail silently.** After going live behind a proxy or CDN, put something into the basket once by hand.

### 🔴 A proxy or CDN without ESI breaks the mini basket

The mini basket is an ESI fragment: the page stays in the shared cache, the fragment is fetched fresh on every request. An upstream cache that does not resolve ESI delivers either the placeholder or the previous visitor's basket.

Without such a cache, Symfony renders the fragment inline — that is the normal case on simple hosting and harmless.

### 🔴 The basket cookie takes **every** page out of the shared cache

Measured on the home page of an installation — a page **without** any Consho element:

| | `Cache-Control` | `Contao-Cache` |
|---|---|---|
| without cookie | `public, s-maxage=86400` | `fresh` |
| with `consho_cart` | `private` | `miss` |

This is Contao's normal behaviour: `ContaoCache` → `StripCookiesSubscriber` only lets through cookies on an allow list; any other makes the request uncacheable. The list is in `.env` and is **commented out** by default.

**The consequence hits exactly what the mini basket was built as an ESI fragment for.** As soon as a visitor puts something into the basket, **every** page is built fresh for them — for them the split no longer achieves anything. Nothing points to it; it only shows when someone compares the headers.

```
COOKIE_ALLOW_LIST=PHPSESSID,csrf_https-contao_csrf_token,csrf_contao_csrf_token,trusted_device,REMEMBERME,consho_cart
```

> ⚠️ **Check first, then add it.** Putting the cookie on the allow list is only right if there is really nothing cookie-dependent in the cached page — otherwise the proxy serves one visitor's basket to the next. The mini basket is cleanly separated (its own `Response`, `renderer: 'esi'`); a project template that renders the basket **directly** into the page is not. After adding it, fetch the same URL once with two different baskets and compare the amounts.
>
> Whether the ESI half really takes effect afterwards is measured by **C-12 (13.2)** — until then it is an expectation, not a fact.

## Giro-Code on the invoice (C-8)

- **New requirement: the PHP extension GD** (`ext-gd`). Without it Composer refuses to install
  the bundle — intended, rather than invoices on which the code is silently missing. GD is listed in php-src
  as "Maintained" and active at most hosts; check with `php -m | grep -i gd`, and do so
  for the web PHP version, not only for the command line.
- New dependency `chillerlan/php-qrcode` — comes with `composer update … --with-dependencies`.
- The code is **off** until the operator ticks "Giro-Code auf der Rechnung" (Giro-Code on the invoice) on the
  payment method. Before that, check the **account holder** against the bank statement: since 9 October 2025 banks
  match name and IBAN, and a differing name warns every paying customer. At most 70 characters (SEPA).
- Snapshot version 3 — no migration, older invoices are read unchanged.

## Payment and shipping as events (D-1)

- New columns, created by `contao:migrate`: `tl_consho_order.cancelledAt`, `.paymentSettledAt`,
  `tl_consho_order_status.isCompleted`, `tl_consho_payment.lockedAt`, `tl_consho_delivery.reportedAt`,
  `.lockedAt`, `tl_consho_shop.statusAfterPayment`, `.statusAfterDelivery`, `.statusWhenSettled`.
- **Existing deliveries have no `reportedAt`** and count as **not shipped** afterwards. Anyone who recorded
  deliveries before D-1 reports them after the update with "Als versandt melden" (Report as shipped) — note that
  this sends the customer the shipping mail.
- **Existing statuses with "Gilt als bezahlt" (Counts as paid) or "Gilt als versendet" (Counts as shipped)** on
  orders whose balances do not support that stay as they are — only a change is checked. The list shows the
  contradiction in the signals.
- The operator sets up the automatic status change per shop (legend "Automatischer
  Bestellstatus" (Automatic order status)), off by default. A status for "everything done" should carry
  "Schließt die Bestellung ab" (Completes the order), otherwise it does not lock the payment change.

## Block C (C-12)

- **Reverse charge now requires the shop's VAT ID** (shop configuration, "Eigene UID-Nummer" (Own VAT ID)). A
  shop that delivers to businesses in other EU countries enters it **and** the reverse charge note for the
  invoice; if the note is missing, the dashboard reports `reverseChargeNoteMissing`. Without a VAT ID the
  checkout charges tax even when the customer's VAT ID has been verified.
- A shop that was copied **before** this version has inherited the counter values of its original — before the
  first real document in its number ranges, check them and set them to 0 if needed.
- **Page cache and basket cookie:** Contao sends `Vary: Cookie` on every cached page; whoever carries
  `consho_cart` gets every page freshly rendered. Since C-12 the cookie goes after the purchase, as soon as no
  open basket is attached to it any more — before, it stayed for 30 days.
- **Contao forms on cached catalogue pages** (login, newsletter, comments) can fail with 400
  "Invalid CSRF token" if the visitor loaded the page **without** a cookie and received the basket
  cookie **afterwards**, for instance in a second tab. The buy button is not affected
  (own route). Ways out: put the form on an uncached page, or include it as an ESI fragment.

## Online payment (C-11)

- New columns `tl_consho_payment.attempt` (unique, `NULL` for manual entry) and
  `tl_consho_payment_attempt.returnState` — `contao:migrate` creates them.
- New routes `/_consho/payment/notify/{token}` (POST, without request token — the provider's signature
  stands in for it) and `/_consho/payment/return/{token}` (GET). They must be **reachable from outside**; a
  maintenance mode or access protection in front of them makes providers repeat their notifications until it is gone.
- **`CONSHO_TEST_PAYMENT=1` only on test installations.** The test provider confirms payments without
  accepting money; as long as the variable is set, the dashboard warns. The test provider calls its own
  server via HTTPS — so the server must be able to reach itself.
- A `QUERY_PARAMS_ALLOW_LIST` that is set needs `consho_.*`; the finding `noticeParamStripped` now also names
  the parameters of the test provider.

## Order page (C-10)

- **Right after the update, put the content element "Bestellseite" (Order page) on every order page.** Until
  then the finding `orderElementMissing` locks the checkout — intended: without the element, the buy click and
  the link in the confirmation lead to an empty page.
- New column `tl_consho_order_item.taxClass`. Orders from before the update carry 0; for them the payment method
  can only be switched between methods without an amount (discount).
- Whoever may switch the payment method needs a **number range for cancellation invoices** — the readiness check
  has required it since C-6, as soon as a method invoices at the time of ordering.

## Links to the order page and limits against mass requests (D-2, D-4, D-6)

- **The migration `OrderTokenExpiryMigration` puts an expiry on existing links**, counted from when they were
  issued: from mails 90 days, from redirects one day. Links from mails from before C-10 cannot be told apart from
  redirects and then only last one day; whoever has such a link requests a new one on the order page. The daily
  Contao cron clears the rest — without a configured cron the rows stay behind (they no longer open anything).
- 🔴 **The forms are limited per IP**: the withdrawal form (`consho.rate_limit.withdrawal_form`, 5 in
  15 minutes) and "Neuen Link anfordern" (Request a new link) (`consho.rate_limit.order_link_ip`, 5 in
  15 minutes); in addition, per address, `order_link_address` and `email_confirmation` (3 per hour each).
  **Behind a proxy or CDN without `TRUSTED_PROXIES`** the IP is that of the proxy — then all customers of the
  shop share five attempts per quarter of an hour. Before the rollout, set `TRUSTED_PROXIES` and check that
  `Request::getClientIp()` returns the visitor's address.

## Kind on the product type (Block Dienstleistung)

The product type carries the **kind** — physical goods, digital or service —, and the order line and subscription
freeze it at the time of purchase. Nothing reads the old columns `isPhysical` (product type, order line) and
`withdrawalKind` (product, order line, subscription) any more, and **they are not carried over** — no legacy data
is migrated just to keep a test installation running.

- **New installation:** nothing to do. Every type starts as "physical goods" — for digital products and
  services, set the kind on the type **before** selling.
- **Existing test installations:** set up anew instead of migrating. `contao:migrate --no-interaction` creates
  `kind`, `taxCountry` and `performedAt`, but does not delete the old columns (see above); old orders would
  then have `kind = physical` everywhere.
- **Tax rates:** A service is taxed in the shop's country — its rate must exist for the tax class of the
  service, otherwise it cannot be bought.
- Whether a service provided online is "electronically supplied" and therefore digital is decided by the operator
  when creating the type; the help text on the field says so.

## Stock (Block G)

- **`keyPoolWarning` is dropped** (G-5). `contao:migrate --no-interaction` creates the new columns,
  but does not delete the old one (see above) — run `contao:migrate` interactively once, or
  explicitly delete the column `tl_consho_product.keyPoolWarning`. A value in it is not
  carried over: the new threshold `stockWarning` sits on the main product.
- **Stock is switched off** until "Bestand führen" (Keep stock) is set on the product. After switching it on,
  the stock of every article is 0 — the article is then "derzeit nicht verfügbar" (currently unavailable) until
  the stock has been booked in the product form. **Book first, then publish**, or temporarily
  set "Auch ohne Bestand verkaufen" (Sell without stock).
- **Key pools** count as stock immediately; an empty pool makes the article no longer
  orderable. Before rolling out, check the pools (finding "Bestand knapp oder leer" (Stock low or gone)).
- **Cron:** "Benachrichtigen, wenn wieder da" (Notify me when back) runs hourly via Contao's cron, like the release.
- **The database must support READ COMMITTED** — InnoDB does; the buy click sets it for its own
  transaction.

## Customer accounts for subscriptions (Abo-8)

A subscription needs an account (P12), and **Consho does not create accounts** — customers come through Contao's
registration, or the operator creates them. Without a registration path, only guests buy in the shop, one-off.

- **Registration module with account activation by e-mail** ("Kontoaktivierung" (Account activation),
  `reg_activate`). Contao creates every new member disabled (`ModuleRegistration`: `disable = 1`); without an
  activation mail it stays that way until the operator enables it in the back end — the operator only gets a
  notification.
  Or Consho enables it itself: **"Konten sofort freischalten" (Activate accounts at once)** on the registration
  module (Nachlese KK-1) — the account is active immediately and is additionally put into the shop's customer
  group. Excludes the activation mail.
  The finding for a missing registration path reports a shop with subscriptions that lacks both.
- **Default group = the shop's customer group** (`reg_groups` = `tl_consho_shop.customerGroup`). An account
  without the group is missing from the customer list.
- 🔴 **Allow protected customer pages for all customer groups** — "Meine Abos" (My subscriptions)
  (`consho_subscriptionsPage`), "Meine Bestellungen" (My orders), member area: customer group **and** net groups.
  For protected pages Contao knows no "all members", only groups and "guests". If the group is missing, there is
  a 403, and without a 403 page on the start page Contao's generic error page **without a log entry**. Create a
  403 page on the start page. Since the Nachlese (KK-2), the findings "customer page locks customers out" (also
  with inherited protection) and "login page missing" (protected customer page, but no 401 page below the start
  page) report this.

## Test the mails before going live (D-7, M8)

- 🔴 **Send every kind of mail once from the real shop domain** to a fresh Gmail and a fresh Outlook mailbox —
  confirmation, payment received, shipped, acknowledgement of a withdrawal, rejection, refund,
  new link — and **look at the spam folder**, not only the inbox. In testing, "payment
  received" and the acknowledgement of receipt landed in spam at Gmail, although SPF, DKIM and DMARC passed.
- Mailboxes that already know the sender, or in which a mail was marked as "not spam", tell you
  nothing: the filter has then been trained.
- Beforehand: the test mail page of the mail configuration shows SPF, DMARC and whether the sender matches the
  login; the start page reports `senderDomainMismatch` if the sender domain matches no start page.

## Order confirmation (C-9)

- **New dependencies** `symfony/mailer` (so far only came through Contao) and
  `symfony/microsoft-graph-mailer`; PHP from **8.4.1**. `composer update webwerkwien/contao-consho-bundle
  --with-dependencies`.
- **Right after the update, choose the guarantee notice as a file on every start page** (PDF,
  PNG or JPEG, in colour, unaltered, in the language of the start page) — before that the field does not exist.
  Until then the finding `warrantyNoticeFileMissing` locks the checkout; intended, because every confirmation
  would otherwise be incomplete. The Commission's package "Harmonised notice in 24 languages" contains, per
  language, one PDF with a colour version (page 1) and a black-and-white version (page 2); online only the colour
  one applies. So schedule this for a maintenance window.
- 🔴 **`contao:migrate` in one call, without `--schema-only` or `--migrations-only`** — on the
  console, not separately through the Contao Manager. `ConfirmationBeforeMailMigration` marks the orders
  from before C-9 with `confirmationSentAt = 0` ("no confirmation due") and can only do that in the **first**
  pass, while `tl_page.consho_warrantyNotice` is still missing. Anyone who applies the schema on its own will then
  see every old order in the "not sent" warning.
  The same applies to `DecisionSentMigration` (E10): it creates `tl_consho_cancellation.decisionSentAt` itself
  and sets decisions from before to 0. If the schema creates the column first, it never runs, and every
  old decision shows up in the "decision not sent" warning.
- The mail goes out in the request of the buy click, after the commit. Ten seconds apply **per
  connection step**, not to the whole sending; if the transport fails on the customer mail, the
  operator copy is no longer attempted.
  So the mail configuration must be reachable from the **web server**, not only from the command line.
- The operator address and the sender should be different mailboxes (Microsoft 365, notice
  `operatorAddressIsSender`).
- **After the update, check the mail configuration with "Testmail senden" (Send test mail).** Passwords that were
  saved before C-9 and contain `# < > ( ) \ = " '` are stored mangled — enter them again.
- New column `tl_consho_order.paymentInstructions` (payment instructions, frozen at the time of purchase) — older
  orders have none; their confirmation takes them from the invoice, where there is one.

## Adjusting the tax label and other texts (Block Wertgutscheine, WG-7)

In the German shop, Consho consistently writes **"USt."** or "Umsatzsteuer". That is what the tax is called in the
law, in Austria as in Germany (§ 1 UStG; § 6 PAngV speaks of prices that "die Umsatzsteuer … enthalten").
"Mehrwertsteuer" is the colloquial name. As far as we have read, the PAngV does not prescribe a particular
abbreviation — **whoever wants "inkl. MwSt." changes the text, not the code.**

Contao loads the project's language files **after** those of the extensions. A file
`contao/languages/de/default.php` **in the project directory of the installation** (next to `composer.json`,
not in the bundle) therefore overrides any Consho text, key by key:

```php
<?php
// contao/languages/de/default.php — in the project, not in the bundle
$GLOBALS['TL_LANG']['CONSHO']['priceTaxNotice'] = 'inkl. MwSt., zzgl. Versandkosten';
$GLOBALS['TL_LANG']['CONSHO']['priceTaxOnly'] = 'inkl. MwSt.';
$GLOBALS['TL_LANG']['CONSHO']['priceTaxNoticeNet'] = 'zzgl. MwSt., zzgl. Versandkosten';
$GLOBALS['TL_LANG']['CONSHO']['priceTaxOnlyNet'] = 'zzgl. MwSt.';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['total']['gross'] = 'Gesamtsumme (inkl. MwSt.)';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['reviewNet'] = 'zzgl. MwSt.';
```

Where the tax is named:

| Key | Where | Default |
|---|---|---|
| `CONSHO.priceTaxNotice`, `priceTaxOnly` | below the price (product page, tile, basket, mini basket), gross | "inkl. USt., zzgl. Versandkosten" / "inkl. USt." |
| `CONSHO.priceTaxNoticeNet`, `priceTaxOnlyNet` | same place, for net customer groups | "zzgl. USt., …" |
| `CONSHO.checkout.total.gross`, `checkout.reviewNet` | review page of the checkout | "Gesamtsumme (inkl. USt.)" / "zzgl. USt." |
| `CONSHO.invoice.tax`, `taxRate`, `taxes`, `taxTotal` | invoice, order page, order confirmation | "USt.", "USt.-Satz", "Umsatzsteuer je Satz", "Umsatzsteuer" |

⚠️ **Clear the cache afterwards** (`cache:clear` or in the Contao Manager), otherwise the old text remains.
And: an **invoice** is a snapshot — it is shown with the text at the time of rendering,
the amount does not change.

Applies to the whole installation. A separate field per shop (for instance an AT and a DE shop with
different spellings) does not exist; it will come when an operator needs it.

For non-taxable articles (tax class "nicht steuerbar" (not taxable), gift vouchers) the sentence is dropped
entirely; if something is shipped, `CONSHO.priceShippingOnly` ("zzgl. Versandkosten") remains.

## Tax in the country of destination, tax report, e-invoice (Block R)

- 🔴 **Behaviour changed:** Until Block R, Consho always charged goods and digital services to consumers
  in other EU countries at the rate of the customer's country. Now, without the switch
  **"Steuer im Bestimmungsland" (Tax at destination)** (shop configuration, legend "Umsatzsteuer" (VAT), off by
  default), the rate of the shop's country applies. Whoever is above the delivery threshold of €10,000 or has
  waived it (OSS registration) switches it on **before the next sale**. It applies to all shops with
  the same VAT ID.
- **Tax report** (in the dashboard since Block Dashboard): counter against the threshold per VAT ID and the
  quarterly export as CSV. Orders from before Block R have no country of destination and are missing there.
- **E-invoice:** switch in the legend "Rechnungsabsender" (Invoice sender); needs company name, country, own VAT
  ID and the contact e-mail for invoices. Only business customers receive it.
- **Accounting modules** dock on via `Document\Handoff\IssuedDocumentHandlerInterface`: every
  issued document once, after the commit and after filing, with snapshot and PDF. Consho
  does not retry — a module that must not lose anything keeps its own queue.
- New dependency `horstoeko/zugferd` (pulls in `jms/serializer`, FPDI, among others). Install it **without**
  `--with-dependencies`: with the option, Composer in a test also upgraded 45 unrelated packages,
  among them `doctrine/dbal` 4.5, on which `contao:migrate` failed.

## Dashboard (Block Dashboard)

- **User groups:** Tick the new module "Dashboard" in the allowed modules. Without that permission
  an editor keeps seeing the notices on Contao's start page. The tax report is now a
  view of the dashboard (`do=consho_dashboard&view=taxReport`) and requires **Dashboard and
  orders** — the dashboard only shows tiles, key figures and the tax report to someone who may open the target
  module. The old "Steuermeldung" (Tax report) permission has no effect and may stay.
- Bookmarks to `do=consho_tax_report` lead nowhere; for the new address see above.
- `contao:migrate` creates the index on `tl_consho_order.createdAt`.

## Including the bundle during development

In the `composer.json` of the target installation:

```json
{
    "repositories": [
        { "type": "path", "url": "../contao-consho-bundle", "options": { "symlink": true } }
    ]
}
```

```bash
composer require webwerkwien/contao-consho-bundle:@dev
```

> ⚠️ **Keep the constraint loose.** A path repository with a narrow version leads to a constraint mismatch against the Packagist version as soon as there is one.

### 🔴 Force the vendor copy after every upload

**`composer update` does not renew the copy with `symlink: false` and an existing lock file.** The command runs through, reports success, clears the cache — and `vendor/` still holds the old code.

After uploading a changed `contao/config/config.php` and a successful `composer update`, the vendor folder still held the previous version. It only became visible by cross-checking.

```bash
cd <project-dir>
rm -rf vendor/webwerkwien/contao-consho-bundle
cp -R packages/contao-consho-bundle vendor/webwerkwien/contao-consho-bundle
COMPOSER_HOME=/tmp/.composer composer dump-autoload --no-plugins
vendor/bin/contao-console cache:clear
```

**Afterwards cross-check that the change has really arrived** — for instance with a `grep` for a line that did not exist before:

```bash
grep -n "<new line>" vendor/webwerkwien/contao-consho-bundle/<file>
```

> 🎯 Without this cross-check you look for the error in the code while it lies in the deployment. The cache had been cleared, the command reported success — only the file was the old one.

## 🔒 The key for the mail credentials

Consho encrypts SMTP passwords in the database. The key belongs **in the environment, not in the database** — otherwise it would sit next to what it protects, and one dump would yield both at once.

**Without any action on your part**, Consho derives the key from `APP_SECRET`, which Contao writes into `.env.local` itself during installation (HKDF with a fixed purpose, so not the same value). For an installation via the Contao Manager there is nothing to do.

**If you want your own key** — for instance so that a change of `APP_SECRET` does not affect the mail passwords:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Write the value as `CONSHO_SECRET_KEY` into `.env.local`, then `cache:clear`. It takes precedence over `APP_SECRET`.

| | |
|---|---|
| **`CONSHO_SECRET_KEY` not set** | Key from `APP_SECRET` (which Contao requires anyway — the installation does not start without it) |
| **`CONSHO_SECRET_KEY` set, but wrong** | Error at the first password, **no** silent fallback to `APP_SECRET` — otherwise the stored passwords would be unreadable without anyone knowing why |
| ⚠️ **`CONSHO_SECRET_KEY` empty** (`CONSHO_SECRET_KEY=`) or not Base64 | Counts as **not set** → key from `APP_SECRET`. Symfony's env processors do not distinguish empty from missing. Anyone who had their own key and empties the line makes the stored passwords unreadable |
| **Rotated** (your own key, or `APP_SECRET` where it is the source) | ⚠️ **All stored passwords are unreadable afterwards** and must be entered again. Not a routine operation. The same applies when **setting** `CONSHO_SECRET_KEY` **later**, if the key was previously derived from `APP_SECRET` |

> The key is stored in the file as 44 Base64 characters and becomes 32 bytes via the `base64` env processor. **There is no `hex2bin` processor in Symfony** — a hex key would produce 48 bytes of nonsense here and would only fail at the first sending attempt. `default::` makes a missing variable yield `null` instead of an `EnvNotFoundException` while the container is built — then `APP_SECRET` applies.

### 🔴 Public files need a step of their own

Consho ships `public/backend.css` — the icons of the three menu groups depend on it. The file must be published to `public/bundles/contaoconsho/`:

```bash
vendor/bin/contao-console assets:install public --symlink --relative
```

In a rollout via `composer install`, the Managed Edition does this by itself. **Not with a manual upload** — `composer dump-autoload` does not touch public files. Cross-check:

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://<host>/bundles/contaoconsho/backend.css
```

Expected: 200. A 404 means the `assets:install` step is missing.

### 🔴 An upload overwrites — it **does not delete**

A `tar` or `scp` upload puts new files in place and replaces existing ones. A file that was **deleted** in the project stays on the server.

A removed listener class stayed in the package folder, was copied into `vendor/` along with the rest and made `cache:clear` fail — with an error pointing to a class that no longer existed in the project at all.

**So empty the package folder before the upload:**

```bash
ssh <target> 'rm -rf <project-dir>/packages/contao-consho-bundle && mkdir -p <project-dir>/packages/contao-consho-bundle'
tar czf - --exclude='.git' --exclude='vendor' . | ssh <target> "cd <project-dir>/packages/contao-consho-bundle && tar xzf -"
```

> 🎯 The same family as the two traps below: **The command reports success, yet the target state is not right.**

### 🔴 Never send `cache:clear` to `/dev/null`

A deploy one-liner contained `vendor/bin/contao-console cache:clear >/dev/null 2>&1`. The command **failed** every time — on an invalid service definition — and printed the reason in full. The redirect threw it away.

What remained was a half-updated state: new files in `vendor/`, the old container in the cache. The symptoms were correspondingly contradictory, and the search went through the code instead of the deployment.

**Filtering** the output is fine, **suppressing** it is not:

```bash
vendor/bin/contao-console cache:clear 2>&1 | grep -v imagick | tail -2
```

The error is also in `var/logs/prod-YYYY-MM-DD.log` — not in the back end's system log and not in the Contao Manager's logs.

### 🔴 Running Messenger workers keep the old container

Contao 5 starts three `messenger:consume` workers with `--time-limit=55` via the per-minute CLI cron (`contao.cron.supervise_workers`) — the default from the Managed Edition skeleton. Each loads the container **once at start-up**. If `var/cache/prod` is deleted in the meantime, it is missing files when it shuts down:

```
php.CRITICAL: Uncaught Error: Failed opening required '…/var/cache/prod/Container…/get….php'
```

Recognisable by the timing: **55 seconds after the cron start**, usually at `:56`. No web request is affected, but the log still fills up on every deploy.

**`messenger:stop-workers` does not solve this reliably.** Observed in practice and confirmed in the code:

- The signal lives in the pool `cache.messenger.restart_workers_signal`, parent pool `cache.app` — that is, under `var/cache/prod/pools`. Deleting the cache takes it along.
- A worker only checks the signal before its pause (`--sleep` 5, 10 or 20 s), and not while it is processing a message.
- With a wait of 5 s and with one of 25 s, one deploy each was clean and one was not.

**What is reliable is running without permanent workers.** In the installation's `config/config.yaml`:

```yaml
contao:
    messenger:
        workers: []
```

`workers` replaces the list from the skeleton instead of extending it. The messages (search index, back-end search) are then processed by the web worker after page requests; `contao:cron` keeps running every minute, including Consho's boundary crons. With this setting, the deploys that followed left no errors in the log.

> ⚠️ The same applies to an update via the Contao Manager: its setup deletes the cache immediately, without `stop-workers`.
