# Rollout

Consho legt eigene Tabellen an und erweitert `tl_page`. Contao leitet das Schema aus den DCA-Dateien ab; `contao:migrate` erzeugt die Spalten.

## Die Reihenfolge ist Cache → migrate → Cache

```bash
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
vendor/bin/contao-console cache:clear
```

**Nicht** migrate → Cache. Contao liest die DCA im `prod`-Environment aus dem Cache. Solange der alte Cache steht, kennt `contao:migrate` die neuen Felder nicht — der Trockenlauf meldet dann seelenruhig „All migrations completed", obwohl keine einzige Spalte existiert.

### 🔴 `--no-interaction` **fügt hinzu, löscht aber nie**

Ein umbenanntes Feld erzeugt zwei Änderungen: die neue Spalte anlegen, die alte entfernen. `contao:migrate --no-interaction` führt **nur die erste** aus und meldet trotzdem „All migrations completed" — die `DROP`-Zeilen stehen in der Ausgabe, unter ihnen aber „Executed 0 SQL queries".

Belegt am 2026-09-09: Nach einer Feldumbenennung standen alte und neue Spalte nebeneinander, mit den alten Werten darin.

```bash
vendor/bin/contao-console contao:migrate --no-interaction --with-deletes
```

> ⚠️ **Vor `--with-deletes` die Werte übernehmen.** Ein `UPDATE tabelle SET neu = alt` dauert eine Zeile — danach ist die alte Spalte weg und mit ihr der Inhalt. Die richtige Reihenfolge ist: migrieren (neue Spalte entsteht) → Werte kopieren → `--with-deletes`.
>
> Gegenprobe, ob wirklich nichts übrig blieb:
>
> ```sql
> SELECT COLUMN_NAME FROM information_schema.COLUMNS
> WHERE TABLE_NAME = 'tl_…' AND COLUMN_NAME IN ('alt', 'neu');
> ```

## Opcode-Cache des Webservers

Prüft der Webserver seinen OPcache nicht nach (`opcache.validate_timestamps = 0` oder ein langer
`revalidate_freq`), läuft nach einem Update im Web die **alte** Fassung jeder geänderten Klasse weiter,
während die Konsole — `cache:warmup`, `contao:migrate` — schon den neuen Stand hat. Nichts scheitert,
es sieht nur aus, als hätte die Änderung nicht gewirkt. Auf dem Testserver der Bundles so gemessen
(2026-10-08). Abhilfe: nach dem Update den Opcode-Cache leeren (Contao Manager → Wartung) oder PHP-FPM
neu starten.

## Danach gegenzählen

Seit Block I sagt ein Befehl, ob jeder Shop noch verkauft — Exit-Code 1, sobald einer es nicht tut,
mit den Gründen darunter:

```bash
vendor/bin/contao-console consho:check
```

Der Trockenlauf lügt nicht, er sieht nur nichts. Prüfen:

```sql
SELECT COUNT(*) FROM information_schema.COLUMNS
WHERE TABLE_NAME = 'tl_page' AND COLUMN_NAME = 'consho_shop';
```

Erwartet: 1

## Das Fenster zwischen Schritt 1 und 2

Nach dem ersten `cache:clear` kennt Contao die Felder, die Datenbank aber noch nicht.

| | |
|---|---|
| Backend | 🔴 Startpunkt bearbeiten wirft einen SQL-Fehler — das Formular fragt die Spalten namentlich ab |
| Frontend | 🔴 seit C-1a nicht mehr unbetroffen — Produktlisten fragen `tl_consho_product_list_price.country` ab und der minütliche Grenzen-Cron (`ListPriceBoundaryCron`) `tl_consho_shop.country`; beide Spalten fehlen noch, beide werfen einen SQL-Fehler, bis `contao:migrate` gelaufen ist |

Das Fenster ist kurz, aber real. Bei Kundeninstallationen in eine Randzeit legen oder kurz den Wartungsmodus setzen.

## Heimatland und Listenpreise je Land (C-1a)

Nach dem Deploy von C-1a zeigt **kein Shop Preise, bis sein Heimatland gesetzt ist** — ohne Heimatland gibt es kein Brutto. Die Backend-Startseite warnt („Heimatland fehlt").

1. Cache → migrate → Cache wie oben. `ListPriceMarketMigration` baut Listenpreise und Sichtbarkeit einmal neu und setzt dabei den Fingerabdruck der Märkte — das erste Speichern einer Seite danach baut **nicht** noch einmal neu.
2. Den eindeutigen Schlüssel prüfen — er wechselt von `(pid, currency)` auf `(pid, currency, country)`:

   ```sql
   SHOW INDEX FROM tl_consho_product_list_price WHERE Non_unique = 0;
   ```

   Erwartet: `PRIMARY` und ein Index über `pid`, `currency`, `country`. `contao:migrate` löscht den alten Index dabei regulär, wie jede andere Schemaänderung. Wer die Schemaänderung im interaktiven Lauf mit „no" ablehnt, lehnt die **ganze Liste** ab — dann fehlt auch die Spalte `country`, und die Migration wartet einfach. Neben einer vorhandenen Spalte `country` bleibt der alte Index nur stehen, wenn die Schemaänderung **teilweise** durchlief (etwa ein fehlgeschlagenes `DROP INDEX` oder ein Eingriff von Hand). Dann bricht `ListPriceMarketMigration` mit einer Meldung ab, statt die Projektion still zu leeren — der alte Schlüssel träfe sonst `INSERT … ON DUPLICATE KEY` und würde die Länder durcheinanderbringen. `contao:migrate` erneut ausführen, damit es den Index löscht; danach läuft diese Migration von selbst durch.
3. In jeder Shop-Konfiguration unter „Länder" das Heimatland eintragen und speichern — das Speichern berechnet die Preise dieses Marktes.
4. Gegenprobe:

   ```sql
   SELECT COUNT(*) FROM tl_consho_product_list_price WHERE country = '';
   ```

   Erwartet: 0. Dazu `consho:price:verify` und `consho:visibility:verify` ohne Abweichung.

> ⚠️ **Steuersätze wirken jetzt auf die Listen.** Beginnt oder endet ein Satz, schaltet `ListPriceBoundaryCron` das Brutto aller Artikel dieser Steuerklasse um. Bei der Normalklasse ist das der ganze Katalog in einem Lauf — Contaos Cron deshalb über die Kommandozeile laufen lassen, nicht über den Web-Cron mit seinem Zeitlimit.

## Preiseingabe und Wegfall von `gross` (C-1b)

1. Cache → migrate → Cache wie oben. `contao:migrate --no-interaction` legt `inputCountry` und `inputMode` an. Bestehende Preiszeilen bekommen „netto" und öffnen sich in der Maske mit ihrem Netto — dem Wert, mit dem seit C-1a gerechnet und angezeigt wird. Eine Migration der Preiszeilen und einen Befund für alte Zeilen, deren Brutto nicht zum Netto passte, gibt es nicht (Entscheidung 2026-09-15: keine produktive Installation).

   **Vorher lesend prüfen:** `SELECT id, pid, currency, net FROM tl_consho_price WHERE tstamp = 0;` — solche Zeilen (etwa außerhalb des Backends angelegt) zählen ab C-1b für keine Preisberechnung. Jede einzeln entscheiden: im Backend öffnen und speichern, oder löschen. Nie gespeicherte Zeilen aus einer geschlossenen Browser-Sitzung stehen als „EUR 0.0000 netto" in der Liste, wirken aber nirgends — Contaos normales Verhalten.
2. Die Spalte `gross` bleibt stehen, bis Löschungen ausdrücklich zugelassen werden — `--no-interaction` überspringt sie. Erst lesen, was gelöscht würde:

   ```bash
   php bin/console contao:migrate --dry-run --with-deletes --no-interaction --env=prod
   ```

   Steht darin **nur** das Löschen von `tl_consho_price.gross`, denselben Befehl ohne `--dry-run` ausführen. Stehen weitere `DROP`-Zeilen darin, **nicht** ausführen — sie gehören nicht zu diesem Schritt und brauchen eine eigene Entscheidung.
3. **Feldrechte der Benutzergruppen:** Die Felder `net` und `gross` gibt es in der Maske nicht mehr. Gruppen mit eingeschränkten Feldrechten brauchen für `tl_consho_price` die Felder **Währung, Eingabe als, Betrag und Land gemeinsam** — sonst blendet Contao die Eingabe aus, und eine neue Preiszeile lässt sich nicht speichern (Meldung „Ohne Betrag …").
4. Eine Preiszeile in einer Währung, die kein Shop führt, lehnt die Maske beim Speichern ab („In der Währung … verkauft kein Shop") — erst eine Währung eines Shops wählen. Eine Preiszeile, die angelegt, aber nie gespeichert wurde (`tstamp` 0), zählt für keine Preisberechnung.

## Versand- und Zahlungsarten (C-2)

1. Cache → migrate → Cache wie oben. `contao:migrate --no-interaction` legt `tl_consho_shipping_method`, `tl_consho_shipping_price`, `tl_consho_payment_method`, die Spalten `shippingMethods`/`paymentMethods` an `tl_consho_shop`, vier Spalten samt zwei Indizes an `tl_consho_order` und `instructions` an `tl_consho_translation` an. Nichts wird gelöscht.
2. **`symfony/validator`** steht jetzt in `composer.json` des Bundles. Es kommt ohnehin mit `contao/core-bundle`; ein `composer update` ist dafür nicht nötig.
3. **Einrichtung, in dieser Reihenfolge:** Steuerklasse vorhanden → Versandart anlegen (Steuerklasse ist Pflicht, auch bei „anteilig") → Betrag je Shop-Währung → Zahlungsart anlegen → beide veröffentlichen → in der Shop-Konfiguration anhaken und ordnen. Eine gewählte Versandart ohne Betrag in der Shop-Währung meldet die Startseite.
4. **Feldrechte der Benutzergruppen:** Für `tl_consho_shipping_price` gilt dasselbe wie für `tl_consho_price` — **Währung, Eingabe als, Betrag und Land gemeinsam** freigeben. Die Module „Versandarten" und „Zahlungsarten" müssen Gruppen ohne Adminrechte eigens erlaubt werden, ebenso bei eingeschränkten Feldrechten die Felder der Zahlungsart samt Bankverbindung (Kontoinhaber, IBAN, BIC, Bank) und an der Shop-Konfiguration „Versandarten" und „Zahlungsarten".
5. Eine Art, die in einer Bestellung vorkommt, hat einen ausgegrauten Löschen-Knopf; beim Löschen über „Mehrere bearbeiten" wird sie still übersprungen. Abschalten über „Veröffentlicht".
6. Beim Kopieren einer Versandart oder eines Produkts bleiben nie gespeicherte Betrags- bzw. Preiszeilen Entwürfe (`tstamp` 0) und stehen in der Liste wie gespeicherte Zeilen — sie zählen nirgends. Aufräumen: lesend `SELECT id, pid, currency FROM tl_consho_shipping_price WHERE tstamp = 0;` (ebenso `tl_consho_price`), dann im Backend öffnen und speichern oder löschen.

## Währung an den Startpunkt (C-1c)

> ⚠️ **Ein bestehender Shop verhält sich danach genau wie vorher.** Die Migration trägt seine Währung an alle seine Startpunkte und macht sie zu seiner einzigen erlaubten. Wer eine Währung führt, sieht kein neues Feld.

1. Cache → migrate → Cache wie oben. `contao:migrate --no-interaction` legt `currencies` an `tl_consho_shop` an, `consho_currency` an `tl_page` und `currency` an `tl_consho_product_visibility` (samt neuem Unique-Schlüssel und drei geänderten Indizes). Es ändert außerdem den Spaltenvorgabewert von `currency` an `tl_consho_price`, `tl_consho_shipping_price` **und `tl_consho_shop`** von `EUR` auf leer — drei `ALTER TABLE`. **Nichts wird gelöscht** — `tl_consho_shop.currency` bleibt stehen, siehe Schritt 5.

2. **Zwischen Upload und `contao:migrate` ist die Seite nicht benutzbar — kein Wartungsfenster von Stunden.** Die Spalten fehlen in diesem Moment, und der Code fragt sie ab:

   | Pfad | im Fenster |
   |---|---|
   | Produktliste, Filter, Sitemap | **HTTP 500** (`ProductQuery` vergleicht `m.currency`) |
   | Backend-Startseite | **HTTP 500** (die Befundquellen lesen `currencies` und `consho_currency`) |
   | Grenzen-Cron | scheitert |
   | Detailseite | 200, ohne Preis |
   | Warenkorb, Mini-Warenkorb | leer, aber sauber |

   Dass Warenkorb und Resolver das Fenster sauber abfangen, ist kein Gesamtbild — **es sind die zwei Pfade, für die es eigens gebaut wurde.** Cache → migrate → Cache in einem Zug, nicht über die Mittagspause verteilt.

   > **Dasselbe Fenster hat C-3b**, und dort ist es kürzer erklärt: Der eindeutige Schlüssel von
   > `tl_consho_cart` wandert von `(tokenHash)` auf `(tokenHash, shop)`. Bis `contao:migrate` lief,
   > steht der alte, engere Schlüssel noch — ein Besucher mit Warenkorb in Shop A, der in Shop B auf
   > „In den Warenkorb" klickt, bekommt in diesem Fenster einen **HTTP 500** statt eines 303.
   > **Betrifft nur Installationen mit mindestens zwei Shops.** Eine Migration braucht der Wechsel
   > nicht: `contao:migrate` tauscht Indizes selbst, `CommandCompiler` schützt nur Tabellen und
   > Spalten. Und er scheitert nicht an Bestandsdaten — was unter `(tokenHash)` eindeutig war, ist
   > es unter `(tokenHash, shop)` weiterhin.

3. **Gegenzählen, drei Abfragen (lesend):**

   ```sql
   SELECT COUNT(*) FROM tl_page WHERE type = 'root' AND consho_shop > 0;
   SELECT COUNT(*) FROM tl_page WHERE type = 'root' AND consho_shop > 0 AND consho_currency <> '';
   SELECT id, title, currency, currencies FROM tl_consho_shop;
   ```

   Die ersten beiden Zahlen müssen **gleich** sein, und jeder Shop muss in `currencies` die Währung führen, die in `currency` steht — **außer in drei Fällen, die die Migration absichtlich auslässt:**

   | Ausgelassen wird | weil |
   |---|---|
   | Startpunkt, dessen Shop **gelöscht** ist | es gibt niemanden, dessen Währung er erben könnte |
   | Shop mit **leerer** Altspalte | es gibt nichts zu übertragen |
   | Shop, dessen `currencies` **schon gepflegt** war | eine Wahl wird nicht durch die Altspalte ersetzt |

   Die ersten beiden vorab prüfen — dann weiß man vor dem Deploy, ob die Zahlen überhaupt gleich sein können:

   ```sql
   SELECT id, title, consho_shop FROM tl_page
    WHERE type = 'root' AND consho_shop > 0
      AND consho_shop NOT IN (SELECT id FROM tl_consho_shop);
   SELECT id, title FROM tl_consho_shop WHERE currency = '';
   ```

4. **Feldrechte der Benutzergruppen:** `currencies` an der Shop-Konfiguration und `consho_currency` am Startpunkt eigens freigeben.

5. **`tl_consho_shop.currency` fällt erst nach der Abnahme — und `--with-deletes` allein genügt dafür nicht.** Contao baut das Sollschema aus den DCA-Definitionen; solange das Feld dort mit `sql` steht, ist die Spalte Teil des Solls und **kein Probelauf schlägt etwas vor.** Der Ablauf ist: erst belegen, dass die Werte angekommen sind (Schritt 3), dann das Feld aus `contao/dca/tl_consho_shop.php` entfernen, dann `contao:migrate --with-deletes` mit Probelauf. Dasselbe Vorgehen wie bei `gross` in C-1b.

### Eine weitere Währung einführen — in dieser Reihenfolge

**Ohne Schritt 2 lehnt die Preismaske die neue Währung ab, und das sieht wie ein Fehler aus.** Die Maske bietet nur Währungen an, in denen auch verkauft wird; ein Markt entsteht erst, wenn ein Startpunkt die Währung führt.

1. In der Shop-Konfiguration die Währung bei **Währungen** zusätzlich anhaken.
2. Den Startpunkt anlegen oder kopieren, dort die neue Währung wählen — **und unveröffentlicht lassen.** Das genügt: Die Marktabfrage filtert bewusst **nicht** nach „veröffentlicht", damit ein depublizierter Startpunkt nicht die Preise seiner Währung aus der Projektion nimmt.
3. Preise pflegen. Jetzt nimmt die Maske die Währung an.
4. Warten, bis der Vollständigkeitsbefund auf der Startseite schweigt — er zählt die Produkte, die in dieser Währung noch keinen Preis haben.
5. Startpunkt veröffentlichen.

> 🔴 **Eine Währung am Shop wieder abzuwählen, die ein Startpunkt noch führt, geht durch — und zwar lautlos.** Es gibt dafür keine Sperre: Die Prüfung am Startpunkt läuft nur, wenn dort jemand speichert, und bleibt nur eine Währung übrig, verschwindet das Feld sogar aus der Maske. Der Startpunkt verkauft dann weiter in einer Währung, die sein Shop nicht mehr führt.
>
> Deshalb meldet es die Backend-Startseite: **„Startpunkt verkauft in einer Währung, die sein Shop nicht annimmt"** (Warnung). **Erst den Startpunkt umstellen, dann die Währung abwählen** — die Meldung ist die Rückfalllinie, nicht der vorgesehene Weg.

## Warenkorb (C-3)

1. Cache → migrate → Cache wie oben. `contao:migrate --no-interaction` legt `tl_consho_cart` und `tl_consho_cart_item` an, dazu `afterAddToCart` an `tl_consho_shop` und `consho_cartPage` an `tl_page`. Nichts wird gelöscht.
2. **Einrichtung:** Am **Startpunkt** die Warenkorbseite wählen (Legende „Consho", neben der Shop-Konfiguration) — auf diese Seite gehört ein Element **Warenkorb**. In der Shop-Konfiguration unter „Checkout" steht, wohin es nach dem Hinzufügen geht; ab Werk zum Warenkorb.
3. **Feldrechte der Benutzergruppen:** `afterAddToCart` an der Shop-Konfiguration und `consho_cartPage` am Startpunkt eigens freigeben. Die beiden neuen Inhaltselemente (`consho_cart`, `consho_mini_cart`) stehen in der Kategorie „Consho" und müssen Gruppen ohne Adminrechte erlaubt werden.
4. Die Warenkorbtabellen haben **kein Backend-Modul** — bewusst. Warenkörbe sind flüchtig und tragen Personendaten. Zum Nachsehen genügt lesendes SQL: `SELECT id, member, shop, state, createdAt, updatedAt FROM tl_consho_cart;`

### 🔴 Ohne eingerichteten Contao-Cron räumt niemand auf

Der Aufräum-Cron läuft **täglich** und löscht offene Warenkörbe nach 30 Tagen, umgewandelte nach 7. Beides sind feste Werte im Code, absichtlich ohne Schalter.

Er läuft aber nur, wenn Contaos Cron auf dieser Installation überhaupt ausgelöst wird — per Web-Cron, `contao:cron` aus der Kommandozeile oder einem Dienst. **Passiert das nicht, sammelt die Installation Warenkörbe ohne Ende** — und ab C-5 stehen darin Adressen, das Ergebnis der UID-Prüfung und ein Geburtsdatum. Nichts weist darauf hin; es fällt erst auf, wenn jemand in die Tabelle sieht.

Gegenprüfen: `SELECT COUNT(*), MIN(FROM_UNIXTIME(updatedAt)) FROM tl_consho_cart;` — ist der älteste Eintrag über 30 Tage alt, läuft der Cron nicht.

### 🔴 Die `Origin`-Prüfung hängt an `trusted_proxies`

„In den Warenkorb" läuft über eine eigene Route, die Contaos Anfrage-Token bewusst nicht prüft (die Katalogseite ist geteilt gecacht, ein Token darin gehörte einem fremden Besucher). An seiner Stelle steht ein Vergleich des `Origin`-Kopfs gegen `Request::getSchemeAndHttpHost()` — und der hängt an Symfonys `trusted_proxies`:

| Einstellung | Folge |
|---|---|
| zu weit gefasst | Ein Angreifer setzt `X-Forwarded-Host`, der Vergleich wird selbstbezüglich und damit **wertlos** |
| nicht gesetzt, aber ein Proxy davor | Die Prüfung weist **alles** ab — niemand kann etwas in den Warenkorb legen |

**Beides scheitert lautlos.** Nach dem Aufschalten hinter einem Proxy oder CDN einmal von Hand etwas in den Warenkorb legen.

### 🔴 Ein Proxy oder CDN ohne ESI zerlegt den Mini-Warenkorb

Der Mini-Warenkorb ist ein ESI-Fragment: Die Seite bleibt im geteilten Cache, das Fragment wird je Aufruf frisch geholt. Ein vorgeschalteter Cache, der ESI nicht auflöst, liefert entweder den Platzhalter oder den Warenkorb des vorigen Besuchers.

Ohne solchen Cache rendert Symfony das Fragment direkt mit — das ist der Normalfall auf einem einfachen Hosting und unbedenklich.

### 🔴 Das Warenkorb-Cookie nimmt **jede** Seite aus dem geteilten Cache

Gemessen auf dem Testserver bei der Abnahme von C-3 (2026-09-20), an der Startseite — einer Seite **ohne** jedes Consho-Element:

| | `Cache-Control` | `Contao-Cache` |
|---|---|---|
| ohne Cookie | `public, s-maxage=86400` | `fresh` |
| mit `consho_cart` | `private` | `miss` |

Das ist Contaos normales Verhalten: `ContaoCache` → `StripCookiesSubscriber` lässt nur Cookies einer Allow-List durch, jedes andere macht die Anfrage nicht cachebar. Die Liste steht in `.env` und ist ab Werk **auskommentiert**.

**Die Folge trifft genau das, wofür der Mini-Warenkorb als ESI-Fragment gebaut wurde.** Sobald ein Besucher etwas hineinlegt, wird ihm **jede** Seite frisch gebaut — für ihn bringt die Aufteilung nichts mehr. Nichts weist darauf hin; es fällt nur auf, wenn jemand die Kopfzeilen vergleicht.

```
COOKIE_ALLOW_LIST=PHPSESSID,csrf_https-contao_csrf_token,csrf_contao_csrf_token,trusted_device,REMEMBERME,consho_cart
```

> ⚠️ **Erst prüfen, dann eintragen.** Das Cookie auf die Allow-List zu setzen ist nur richtig, wenn in der gecachten Seite wirklich nichts Cookie-Abhängiges steht — sonst serviert der Proxy den Warenkorb des einen Besuchers dem nächsten. Der Mini-Warenkorb ist sauber getrennt (eigene `Response`, `renderer: 'esi'`); ein Projekt-Template, das den Warenkorb **direkt** in die Seite rendert, ist es nicht. Nach dem Eintragen einmal mit zwei verschiedenen Warenkörben dieselbe URL abrufen und die Beträge vergleichen.
>
> Ob die ESI-Hälfte danach wirklich greift, misst **C-12 (13.2)** — bis dahin ist sie eine Erwartung, keine Tatsache.

## Giro-Code auf der Rechnung (C-8)

- **Neue Voraussetzung: die PHP-Erweiterung GD** (`ext-gd`). Ohne sie verweigert Composer die
  Installation des Bundles — gewollt, statt Rechnungen, auf denen der Code still fehlt. GD ist in php-src
  als „Maintained" geführt und bei den meisten Hostern aktiv; prüfen mit `php -m | grep -i gd`, und zwar
  für die Web-PHP-Version, nicht nur für die Kommandozeile.
- Neue Abhängigkeit `chillerlan/php-qrcode` — kommt mit `composer update … --with-dependencies`.
- Der Code ist **aus**, bis der Betreiber an der Zahlungsart „Giro-Code auf der Rechnung" anhakt. Vorher
  den **Kontoinhaber** gegen den Kontoauszug prüfen: Seit 9. Oktober 2025 gleichen Banken Name und IBAN
  ab, ein abweichender Name warnt jeden zahlenden Kunden. Höchstens 70 Zeichen (SEPA).
- Schnappschuss Fassung 3 — keine Migration, ältere Rechnungen werden unverändert gelesen.

## Zahlung und Versand als Ereignisse (D-1)

- Neue Spalten, von `contao:migrate` angelegt: `tl_consho_order.cancelledAt`, `.paymentSettledAt`,
  `tl_consho_order_status.isCompleted`, `tl_consho_payment.lockedAt`, `tl_consho_delivery.reportedAt`,
  `.lockedAt`, `tl_consho_shop.statusAfterPayment`, `.statusAfterDelivery`, `.statusWhenSettled`.
- **Bestehende Lieferungen haben kein `reportedAt`** und zählen danach als **nicht versandt**. Wer vor D-1
  Lieferungen erfasst hat, meldet sie nach dem Update mit „Als versandt melden" — Achtung, das schickt
  dem Kunden die Versandmail.
- **Bestehende Status mit „Gilt als bezahlt" oder „Gilt als versendet"** an Bestellungen, deren Salden
  das nicht hergeben, bleiben stehen — geprüft wird erst ein Wechsel. Die Liste zeigt den Widerspruch
  an den Signalen.
- Den automatischen Statuswechsel stellt der Betreiber je Shop ein (Legende „Automatischer
  Bestellstatus"), ab Werk aus. Ein Status für „alles erledigt" sollte „Schließt die Bestellung ab"
  tragen, sonst sperrt er den Zahlungswechsel nicht.

## Abnahme von Block C (C-12)

- **Reverse Charge verlangt jetzt die UID des Shops** (Shop-Konfiguration, „Eigene UID-Nummer"). Ein
  Shop, der an Unternehmen im EU-Ausland liefert, trägt sie ein **und** den Reverse-Charge-Hinweis für die
  Rechnung; fehlt der Hinweis, meldet das Dashboard `reverseChargeNoteMissing`. Ohne UID rechnet der
  Checkout auch bei geprüfter Kunden-UID mit Steuer.
- Ein Shop, der **vor** diesem Stand kopiert wurde, hat die Zählerstände seines Originals geerbt — vor dem
  ersten echten Beleg in seinen Nummernkreisen prüfen und bei Bedarf auf 0 setzen.
- **Seiten-Cache und Warenkorb-Cookie** (gemessen 2026-09-26): Contao sendet an jeder gecachten Seite
  `Vary: Cookie`, wer `consho_cart` trägt, bekommt jede Seite frisch gerendert. Seit C-12 geht der Cookie
  nach dem Kauf, sobald kein offener Warenkorb mehr an ihm hängt — vorher blieb er 30 Tage.
- **Contao-Formulare auf gecachten Katalogseiten** (Login, Newsletter, Kommentare) können mit 400
  „Invalid CSRF token" scheitern, wenn der Besucher die Seite **ohne** Cookie geladen und den
  Warenkorb-Cookie **danach** bekommen hat, etwa in einem zweiten Tab. Der Kaufknopf ist nicht betroffen
  (eigene Route). Auswege: das Formular auf eine ungecachte Seite legen oder als ESI-Fragment einbinden.

## Online-Zahlung (C-11)

- Neue Spalten `tl_consho_payment.attempt` (eindeutig, `NULL` bei Handeingabe) und
  `tl_consho_payment_attempt.returnState` — `contao:migrate` legt sie an.
- Neue Routen `/_consho/payment/notify/{token}` (POST, ohne Anfrage-Token — die Signatur des Anbieters
  steht dafür) und `/_consho/payment/return/{token}` (GET). Sie müssen **von außen erreichbar** sein; ein
  Wartungsmodus oder Zugriffsschutz davor lässt Anbieter ihre Meldungen wiederholen, bis er weg ist.
- **`CONSHO_TEST_PAYMENT=1` nur auf Testinstallationen.** Der Test-Anbieter bestätigt Zahlungen, ohne
  Geld anzunehmen; solange die Variable steht, warnt das Dashboard. Der Test-Anbieter ruft den eigenen
  Server per HTTPS auf — der Server muss sich also selbst erreichen können.
- Eine gesetzte `QUERY_PARAMS_ALLOW_LIST` braucht `consho_.*`; der Befund `noticeParamStripped` nennt
  jetzt auch die Parameter des Test-Anbieters.

## Bestellseite (C-10)

- **Gleich nach dem Update auf jede Bestellseite das Inhaltselement „Bestellseite" setzen.** Bis dahin
  sperrt der Befund `orderElementMissing` den Checkout — gewollt: Ohne das Element führen der Kaufklick und
  der Link in der Bestätigung auf eine leere Seite.
- Neue Spalte `tl_consho_order_item.taxClass`. Bestellungen von vor dem Update tragen 0; bei ihnen lässt
  sich die Zahlungsart nur zwischen Arten ohne Betrag (Nachlass) wechseln.
- Wer die Zahlungsart wechseln darf, braucht einen **Nummernkreis für Stornorechnungen** — den verlangt
  die Bereitschaftsprüfung schon seit C-6, sobald eine Art beim Bestellen abrechnet.

## Links zur Bestellseite und Sperren gegen Massenanfragen (D-2, D-4, D-6)

- **Die Migration `OrderTokenExpiryMigration` befristet bestehende Links** ab ihrer Ausstellung: aus Mails
  90 Tage, aus Weiterleitungen einen Tag. Links aus Mails von vor C-10 lassen sich von Weiterleitungen
  nicht unterscheiden und gelten dann nur einen Tag; wer so einen hat, fordert auf der Bestellseite einen
  neuen an. Den Rest räumt der tägliche Contao-Cron — ohne eingerichteten Cron bleiben die Zeilen liegen
  (sie öffnen nichts mehr).
- 🔴 **Die Formulare begrenzen je IP**: Widerrufsformular (`consho.rate_limit.withdrawal_form`, 5 in
  15 Minuten) und „Neuen Link anfordern" (`consho.rate_limit.order_link_ip`, 5 in 15 Minuten); dazu je
  Adresse `order_link_address` und `email_confirmation` (je 3 in der Stunde). **Hinter einem Proxy oder
  CDN ohne `TRUSTED_PROXIES`** ist die IP die des Proxys — dann teilen sich alle Kunden des Shops fünf
  Versuche je Viertelstunde. Vor dem Rollout `TRUSTED_PROXIES` setzen und prüfen, dass
  `Request::getClientIp()` die Adresse des Besuchers liefert.

## Art am Produkttyp (Block Dienstleistung)

Der Produkttyp trägt die **Art** — körperliche Ware, digital oder Dienstleistung —, Bestellzeile und Abo frieren
sie beim Kauf ein. Die alten Spalten `isPhysical` (Produkttyp, Bestellzeile) und `withdrawalKind` (Produkt,
Bestellzeile, Abo) liest nichts mehr, und **sie werden nicht übernommen** (Michael, 2026-10-01: keine
„Altlasten mitschleppen um eine testinstallation am laufen zu halten").

- **Neuinstallation:** nichts zu tun. Jeder Typ beginnt als „körperliche Ware" — für digitale Produkte und
  Dienstleistungen die Art am Typ setzen, **bevor** verkauft wird.
- **Bestehende Testinstallationen:** neu aufsetzen statt migrieren. `contao:migrate --no-interaction` legt
  `kind`, `taxCountry` und `performedAt` an, löscht die alten Spalten aber nicht (siehe oben); alte Bestellungen
  hätten danach überall `kind = physical`.
- **Steuersätze:** Eine Dienstleistung wird im Land des Shops versteuert — dessen Satz muss für die Steuerklasse
  der Dienstleistung angelegt sein, sonst ist sie nicht kaufbar.
- Ob eine online erbrachte Leistung „elektronisch erbracht" und damit digital ist, entscheidet der Betreiber
  beim Anlegen des Typs; die Hilfe am Feld sagt es.

## Lager (Block G)

- **`keyPoolWarning` entfällt** (G-5). `contao:migrate --no-interaction` legt die neuen Spalten an,
  löscht die alte aber nicht (siehe oben) — `contao:migrate` einmal interaktiv laufen lassen oder
  die Spalte `tl_consho_product.keyPoolWarning` ausdrücklich löschen. Ein Wert darin wird nicht
  übernommen: die neue Schwelle `stockWarning` sitzt am Hauptprodukt.
- **Bestand ist ausgeschaltet**, bis „Bestand führen" am Produkt gesetzt ist. Nach dem Einschalten
  steht der Bestand jedes Artikels auf 0 — der Artikel ist dann „derzeit nicht verfügbar", bis der
  Bestand in der Produktmaske gebucht ist. **Erst buchen, dann veröffentlichen**, oder vorübergehend
  „auch ohne Bestand verkaufen" setzen.
- **Schlüsselvorräte** zählen sofort als Bestand; ein leerer Vorrat macht den Artikel nicht mehr
  bestellbar. Vor dem Ausrollen die Vorräte prüfen (Befund „Bestand knapp oder leer").
- **Cron:** „Benachrichtigen, wenn wieder da" läuft stündlich über Contaos Cron, wie die Freigabe.
- **Die Datenbank muss READ COMMITTED können** — InnoDB kann es; der Kaufklick setzt es für seine
  Transaktion selbst.

## Kundenkonten für Abos (Abnahme Abo-8)

Ein Abo braucht ein Konto (P12), und **Consho legt keine Konten an** — Kunden kommen über Contaos Registrierung
oder der Betreiber legt sie an. Ohne Registrierungsweg kaufen im Shop nur Gäste, einmalig.

- **Registrierungsmodul mit Kontoaktivierung per E-Mail** („Kontoaktivierung", `reg_activate`). Contao legt jedes
  neue Mitglied deaktiviert an (`ModuleRegistration`: `disable = 1`); ohne Aktivierungsmail bleibt es das, bis der
  Betreiber es im Backend freischaltet — er bekommt nur eine Benachrichtigung.
  Oder Consho schaltet selbst frei: **„Konten sofort freischalten"** am Registrierungsmodul (Nachlese KK-1) —
  das Konto ist sofort aktiv und kommt zusätzlich in die Kundengruppe des Shops. Schließt die Aktivierungsmail aus.
  Der Befund „Registrierungsweg fehlt" meldet einen Shop mit Abos, dem beides fehlt.
- **Standardgruppe = Kundengruppe des Shops** (`reg_groups` = `tl_consho_shop.customerGroup`). Ein Konto ohne die
  Gruppe fehlt in der Kundenliste.
- 🔴 **Geschützte Kundenseiten für alle Kundengruppen freigeben** — „Meine Abos" (`consho_subscriptionsPage`),
  „Meine Bestellungen", Mitgliederbereich: Kundengruppe **und** Nettogruppen. Contao kennt für geschützte Seiten
  kein „alle Mitglieder", nur Gruppen und „Gäste". Fehlt die Gruppe, gibt es 403, und ohne 403-Seite am Startpunkt
  Contaos allgemeine Fehlerseite **ohne Logeintrag**. Eine 403-Seite am Startpunkt
  anlegen. Seit der Nachlese (KK-2) melden das die Befunde „Kundenseite sperrt Kunden aus" (auch bei geerbtem
  Schutz) und „Anmeldeseite fehlt" (geschützte Kundenseite, aber keine 401-Seite unter dem Startpunkt).

## Mails vor dem Start messen (D-7, M8)

- 🔴 **Jede Mailart einmal von der echten Shop-Domain** an ein frisches Gmail- und ein Outlook-Postfach
  schicken — Bestätigung, Zahlung erhalten, versendet, Eingangsbestätigung Widerruf, Ablehnung, Erstattung,
  neuer Link — und **den Spam-Ordner ansehen**, nicht nur den Posteingang. Im Test landeten „Zahlung
  erhalten" und die Eingangsbestätigung bei Gmail im Spam, obwohl SPF, DKIM und DMARC bestanden.
- Postfächer, die den Absender schon kennen oder in denen eine Mail als „kein Spam" markiert wurde, sagen
  nichts: Der Filter ist dann trainiert.
- Vorher: die Testmail-Seite der Mail-Konfiguration zeigt SPF, DMARC und ob der Absender zur Anmeldung
  passt; die Startseite meldet `senderDomainMismatch`, wenn die Absender-Domain zu keinem Startpunkt passt.

## Bestellbestätigung (C-9)

- **Neue Abhängigkeiten** `symfony/mailer` (kam bisher nur über Contao) und
  `symfony/microsoft-graph-mailer`; PHP ab **8.4.1**. `composer update webwerkwien/contao-consho-bundle
  --with-dependencies`.
- **Gleich nach dem Update an jedem Startpunkt die Gewährleistungs-Mitteilung als Datei wählen** (PDF,
  PNG oder JPEG, farbig, unverändert, in der Sprache des Startpunkts) — vorher gibt es das Feld nicht.
  Bis dahin sperrt der Befund `warrantyNoticeFileMissing` den Checkout; gewollt, weil jede Bestätigung
  sonst unvollständig wäre. Das Paket der Kommission „Harmonised notice in 24 languages" enthält je
  Sprache eine PDF mit Farb- (Seite 1) und Schwarz-Weiß-Fassung (Seite 2); online gilt nur die farbige.
  Den Termin also in ein Wartungsfenster legen.
- 🔴 **`contao:migrate` in einem Aufruf, ohne `--schema-only` oder `--migrations-only`** — auf der
  Konsole, nicht getrennt über den Contao Manager. `ConfirmationBeforeMailMigration` markiert die Bestellungen
  von vor C-9 mit `confirmationSentAt = 0` („keine Bestätigung fällig") und kann das nur im **ersten**
  Durchgang, solange `tl_page.consho_warrantyNotice` noch fehlt. Wer das Schema allein einspielt, sieht danach
  jede alte Bestellung in der Warnung „nicht versendet".
  Dasselbe gilt für `DecisionSentMigration` (E10): Sie legt `tl_consho_cancellation.decisionSentAt` selbst an
  und setzt Entscheidungen von vorher auf 0. Legt zuerst das Schema die Spalte an, läuft sie nie, und jede
  alte Entscheidung steht in der Warnung „Entscheidung nicht versendet".
- Die Mail geht im Request des Kaufklicks hinaus, nach dem Commit. Zehn Sekunden gelten **je
  Verbindungsschritt**, nicht für den ganzen Versand; scheitert der Transport an der Kundenmail, wird die
  Betreiberkopie nicht mehr versucht.
  Die Mail-Konfiguration muss also vom **Webserver** aus erreichbar sein, nicht nur von der Kommandozeile.
- Betreiberadresse und Absender sollten verschiedene Postfächer sein (Microsoft 365, Hinweis
  `operatorAddressIsSender`).
- **Nach dem Update die Mail-Konfiguration mit „Testmail senden" prüfen.** Passwörter, die vor C-9
  gespeichert wurden und `# < > ( ) \ = " '` enthalten, sind verstümmelt gespeichert — neu eingeben.
- Neue Spalte `tl_consho_order.paymentInstructions` (Zahlungshinweis, beim Kauf eingefroren) — ältere
  Bestellungen haben keinen; ihre Bestätigung nimmt ihn aus der Rechnung, wo es eine gibt.

## Steuerbezeichnung und andere Texte anpassen (Block Wertgutscheine, WG-7)

Consho schreibt im deutschen Shop durchgehend **„USt."** bzw. „Umsatzsteuer" (Michael, 2026-10-06:
„einheitlich USt."). So heißt die Steuer im Gesetz, in Österreich wie in Deutschland (§ 1 UStG; § 6
PAngV spricht von Preisen, die „die Umsatzsteuer … enthalten"). „Mehrwertsteuer" ist der
umgangssprachliche Name. Eine bestimmte Abkürzung schreibt die PAngV nach dem, was wir gelesen haben,
nicht vor — **wer „inkl. MwSt." will, ändert den Text, nicht den Code.**

Contao lädt Sprachdateien des Projekts **nach** denen der Erweiterungen. Eine Datei
`contao/languages/de/default.php` **im Projektverzeichnis der Installation** (neben `composer.json`,
nicht im Bundle) überschreibt also jeden Consho-Text, Schlüssel für Schlüssel:

```php
<?php
// contao/languages/de/default.php — im Projekt, nicht im Bundle
$GLOBALS['TL_LANG']['CONSHO']['priceTaxNotice'] = 'inkl. MwSt., zzgl. Versandkosten';
$GLOBALS['TL_LANG']['CONSHO']['priceTaxOnly'] = 'inkl. MwSt.';
$GLOBALS['TL_LANG']['CONSHO']['priceTaxNoticeNet'] = 'zzgl. MwSt., zzgl. Versandkosten';
$GLOBALS['TL_LANG']['CONSHO']['priceTaxOnlyNet'] = 'zzgl. MwSt.';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['total']['gross'] = 'Gesamtsumme (inkl. MwSt.)';
$GLOBALS['TL_LANG']['CONSHO']['checkout']['reviewNet'] = 'zzgl. MwSt.';
```

Wo die Steuer genannt wird:

| Schlüssel | Wo | Vorgabe |
|---|---|---|
| `CONSHO.priceTaxNotice`, `priceTaxOnly` | unter dem Preis (Produktseite, Kachel, Warenkorb, Mini-Warenkorb), brutto | „inkl. USt., zzgl. Versandkosten" / „inkl. USt." |
| `CONSHO.priceTaxNoticeNet`, `priceTaxOnlyNet` | ebenda, für Netto-Kundengruppen | „zzgl. USt., …" |
| `CONSHO.checkout.total.gross`, `checkout.reviewNet` | Prüfseite der Kasse | „Gesamtsumme (inkl. USt.)" / „zzgl. USt." |
| `CONSHO.invoice.tax`, `taxRate`, `taxes`, `taxTotal` | Rechnung, Bestellseite, Bestellbestätigung | „USt.", „USt.-Satz", „Umsatzsteuer je Satz", „Umsatzsteuer" |

⚠️ **Danach den Cache leeren** (`cache:clear` bzw. im Contao Manager), sonst bleibt der alte Text.
Und: Eine **Rechnung** ist ein Schnappschuss — sie wird mit dem Text zur Zeit des Renderns gezeigt,
der Betrag ändert sich nicht.

Gilt für die ganze Installation. Ein eigenes Feld je Shop (etwa ein AT- und ein DE-Shop mit
verschiedener Schreibweise) gibt es nicht; es kommt, wenn ein Betreiber es braucht.

Bei nicht steuerbaren Artikeln (Steuerklasse „nicht steuerbar", Wertgutscheine) entfällt der Satz
ganz; wird etwas versandt, bleibt `CONSHO.priceShippingOnly` („zzgl. Versandkosten").

## Steuer im Bestimmungsland, Steuermeldung, E-Rechnung (Block R)

- 🔴 **Verhalten geändert:** Bis Block R berechnete Consho Ware und digitale Leistungen an Privatkunden
  in anderen EU-Ländern immer mit dem Satz des Kundenlandes. Jetzt gilt ohne den Schalter
  **„Steuer im Bestimmungsland"** (Shop-Konfiguration, Legende Umsatzsteuer, Standard aus) der Satz
  des Shoplandes. Wer über der Lieferschwelle von 10.000 € liegt oder auf sie verzichtet hat
  (OSS-Registrierung), schaltet ihn **vor dem nächsten Verkauf** ein. Er gilt für alle Shops mit
  derselben UID.
- **Steuermeldung** (seit Block Dashboard im Dashboard): Zähler gegen die Schwelle je UID und der Quartalsexport als CSV.
  Bestellungen von vor Block R haben kein Bestimmungsland und fehlen dort.
- **E-Rechnung:** Schalter in der Legende Rechnungsabsender, braucht Firmenname, Land, eigene UID und
  die Kontakt-E-Mail für Rechnungen. Nur Geschäftskunden bekommen sie.
- **Buchhaltungsmodule** docken über `Document\Handoff\IssuedDocumentHandlerInterface` an: jeder
  ausgestellte Beleg einmal, nach dem Commit und nach der Ablage, mit Schnappschuss und PDF. Consho
  wiederholt nicht — ein Modul, das nichts verlieren darf, führt eine eigene Warteschlange.
- Neue Abhängigkeit `horstoeko/zugferd` (zieht u. a. `jms/serializer`, FPDI). **Ohne**
  `--with-dependencies` installieren: mit der Option hob Composer im Test 45 fremde Pakete mit an,
  darunter `doctrine/dbal` 4.5, an dem `contao:migrate` scheiterte.

## Dashboard (Block Dashboard)

- **Benutzergruppen:** Das neue Modul „Dashboard" in den erlaubten Modulen ankreuzen. Ohne das Recht
  sieht ein Redakteur die Hinweise weiter auf Contaos Startseite. Die Steuermeldung ist jetzt eine
  Ansicht des Dashboards (`do=consho_dashboard&view=taxReport`) und verlangt **Dashboard und
  Bestellungen** — Kacheln, Kennzahlen und Steuermeldung zeigt das Dashboard nur, wer das Zielmodul
  öffnen darf. Das alte Recht „Steuermeldung" ist ohne Wirkung und darf stehen bleiben.
- Lesezeichen auf `do=consho_tax_report` führen ins Leere; neue Adresse siehe oben.
- `contao:migrate` legt den Index auf `tl_consho_order.createdAt` an.

## Einbinden während der Entwicklung

In der `composer.json` der Zielinstallation:

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

> ⚠️ **Constraint locker halten.** Ein Pfad-Repository mit einer engen Version führt zu einem Constraint-Mismatch gegen die Packagist-Fassung, sobald es eine gibt.

### 🔴 Nach jedem Upload die Vendor-Kopie erzwingen

**`composer update` erneuert die Kopie bei `symlink: false` und bestehendem Lock-File nicht.** Der Befehl läuft durch, meldet Erfolg, räumt den Cache auf — und im `vendor/` liegt weiterhin der alte Code.

Belegt am 2026-09-09 auf dem Testserver: Nach dem Upload einer geänderten `contao/config/config.php` und einem erfolgreichen `composer update` stand im Vendor-Ordner die vorherige Fassung. Sichtbar wurde es nur durch Gegenzählen.

```bash
cd /web
rm -rf vendor/webwerkwien/contao-consho-bundle
cp -R packages/contao-consho-bundle vendor/webwerkwien/contao-consho-bundle
COMPOSER_HOME=/tmp/.composer composer dump-autoload --no-plugins
vendor/bin/contao-console cache:clear
```

**Danach gegenprüfen, dass die Änderung wirklich angekommen ist** — etwa mit einem `grep` auf eine Zeile, die es vorher nicht gab:

```bash
grep -n "<neue Zeile>" vendor/webwerkwien/contao-consho-bundle/<datei>
```

> 🎯 Ohne diese Gegenprobe sucht man den Fehler im Code, während er im Deployment liegt. Der Cache war geleert, der Befehl meldete Erfolg — nur die Datei war die alte.

## 🔒 Der Schlüssel für die Mail-Zugangsdaten

Consho verschlüsselt SMTP-Passwörter in der Datenbank. Der Schlüssel gehört **in die Umgebung, nicht in die Datenbank** — sonst läge er neben dem, was er schützt, und ein Auszug gäbe beides auf einmal her.

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Den Wert als `CONSHO_SECRET_KEY` in die `.env.local` schreiben, dann `cache:clear`.

| | |
|---|---|
| **Nicht gesetzt** | Der Container baut normal, das Backend läuft. Erst wenn etwas eine Mail versenden will, meldet Consho „Der Schlüssel muss genau 32 Byte lang sein" |
| **Gedreht** | ⚠️ **Alle gespeicherten Passwörter sind danach unlesbar** und müssen neu eingegeben werden. Kein Routinevorgang |

> Der Schlüssel steht als 44 Base64-Zeichen in der Datei und wird über den `base64`-Env-Prozessor zu 32 Byte. **Einen `hex2bin`-Prozessor gibt es in Symfony nicht** — ein Hexschlüssel ergäbe hier 48 Byte Unsinn und scheiterte erst beim Versandversuch. `default::` sorgt dafür, dass eine fehlende Variable eine leere Zeichenkette ergibt statt einer `EnvNotFoundException` beim Bauen des Containers — der Fehler taucht dort auf, wo er hingehört: beim Versandversuch.

### 🔴 Öffentliche Dateien brauchen einen eigenen Schritt

Consho liefert `public/backend.css` mit — die Symbole der drei Menügruppen hängen daran. Die Datei muss nach `public/bundles/contaoconsho/` veröffentlicht werden:

```bash
vendor/bin/contao-console assets:install public --symlink --relative
```

Bei einem Rollout über `composer install` erledigt das die Managed Edition von selbst. **Beim Upload von Hand nicht** — `composer dump-autoload` rührt öffentliche Dateien nicht an. Gegenprobe:

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://<host>/bundles/contaoconsho/backend.css
```

Erwartet: 200. Bei 404 fehlt der `assets:install`-Schritt.

### 🔴 Ein Upload überschreibt — er **löscht nicht**

Ein `tar`- oder `scp`-Upload legt neue Dateien hin und ersetzt vorhandene. Eine Datei, die im Projekt **gelöscht** wurde, bleibt auf dem Server liegen.

Am 2026-09-09 vorgeführt: Eine entfernte Listener-Klasse lag weiter im Paketordner, wurde mit in den `vendor/` kopiert und ließ `cache:clear` scheitern — mit einem Fehler, der auf eine Klasse zeigte, die es im Projekt gar nicht mehr gab.

**Deshalb den Paketordner vor dem Upload leeren:**

```bash
ssh <ziel> 'rm -rf /web/packages/contao-consho-bundle && mkdir -p /web/packages/contao-consho-bundle'
tar czf - --exclude='.git' --exclude='vendor' . | ssh <ziel> "cd /web/packages/contao-consho-bundle && tar xzf -"
```

> 🎯 Dieselbe Familie wie die beiden Fallen darunter: **Der Befehl meldet Erfolg, der Zielzustand stimmt trotzdem nicht.**

### 🔴 `cache:clear` nie nach `/dev/null` schicken

Am 2026-09-09 kostete das eine Stunde. Im Deploy-Einzeiler stand `vendor/bin/contao-console cache:clear >/dev/null 2>&1`. Der Befehl **scheiterte** dabei jedes Mal — an einer ungültigen Service-Definition — und gab den Grund vollständig aus. Die Umleitung warf ihn weg.

Zurück blieb ein halb aktualisierter Stand: neue Dateien im `vendor/`, alter Container im Cache. Die Symptome waren entsprechend widersprüchlich, und die Suche lief im Code statt im Deployment.

Ausgabe **filtern** ist in Ordnung, **unterdrücken** nicht:

```bash
vendor/bin/contao-console cache:clear 2>&1 | grep -v imagick | tail -2
```

Der Fehler steht außerdem in `var/logs/prod-YYYY-MM-DD.log` — nicht im System-Log des Backends und nicht in den Logs des Contao Managers.

### 🔴 Laufende Messenger-Worker behalten den alten Container

Contao 5 startet über den minütlichen CLI-Cron (`contao.cron.supervise_workers`) drei Worker `messenger:consume` mit `--time-limit=55` — Voreinstellung aus dem Skeleton der Managed Edition. Jeder lädt den Container **einmal beim Start**. Wird währenddessen `var/cache/prod` gelöscht, fehlen ihm beim Beenden Dateien:

```
php.CRITICAL: Uncaught Error: Failed opening required '…/var/cache/prod/Container…/get….php'
```

Erkennbar am Zeitpunkt: **55 Sekunden nach dem Cron-Start**, meist `:56`. Keine Webanfrage ist betroffen, das Log füllt sich trotzdem bei jedem Deploy.

**`messenger:stop-workers` löst das nicht zuverlässig.** Belegt am 2026-09-14 auf dem Testserver, im Code nachgelesen:

- Das Signal liegt im Pool `cache.messenger.restart_workers_signal`, Elternpool `cache.app` — also unter `var/cache/prod/pools`. Das Löschen des Caches nimmt es mit.
- Ein Worker prüft das Signal nur vor seiner Pause (`--sleep` 5, 10 bzw. 20 s), und nicht, solange er eine Nachricht abarbeitet.
- Mit 5 s und mit 25 s Wartezeit war je ein Deploy sauber und einer nicht.

**Verlässlich ist ein Betrieb ohne dauerhafte Worker.** In `config/config.yaml` der Installation:

```yaml
contao:
    messenger:
        workers: []
```

`workers` ersetzt die Liste aus dem Skeleton, statt sie zu ergänzen. Die Nachrichten (Suchindex, Backend-Suche) arbeitet dann der Web-Worker nach Seitenaufrufen ab; `contao:cron` läuft weiter minutengenau, auch die Grenzen-Crons von Consho. Auf dem Testserver seit 2026-09-14 so — die Deploys danach blieben ohne Fehler im Log.

> ⚠️ Dasselbe gilt für ein Update über den Contao Manager: Dessen Setup löscht den Cache sofort, ohne `stop-workers`.
