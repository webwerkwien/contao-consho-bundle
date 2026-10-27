# Consho

E-commerce for Contao. Several shops in one installation, built for EU law.

> **Version 0.x.** Consho is fully usable, but not yet at 1.0: interfaces and the data model may change between
> minor versions. Read the [CHANGELOG](CHANGELOG.md) before every update.

## What Consho does

The core is free (MIT) and runs a complete shop:

| Area | |
|---|---|
| **Catalogue** | Products with variants, properties and custom fields; list, filter, landing pages, detail page, sitemap, JSON-LD; unit price, manufacturer details, GARAN label |
| **Basket and checkout** | Guest orders, customer groups, net prices for business customers, reverse charge with VIES check; order button ("Button-Lösung"), consent to the terms and conditions, withdrawal information |
| **Shipping and payment** | Shipping methods with weight tiers, countries and bulky goods; prepayment, on account, cash on delivery; extension points for payment modules and parcel services |
| **After the purchase** | Invoice as PDF and HTML (with Giro-Code), cancellation invoice and invoice correction, delivery note per parcel (with its own number range), e-invoice ZUGFeRD EN 16931 for business customers; mails; order page via token; withdrawal function (§ 356a BGB, § 13a FAGG); customer account |
| **Digital goods and subscriptions** | Files and licence keys, terms, subscriptions with subscription rules, follow-up orders, cancellation button, pauses |
| **Prices** | Promotions with strike-through price under § 9a PrAG / § 11 PAngV, discount rules, discount codes, gift vouchers |
| **Stock** | Stock levels, availability display, back-in-stock notification, purchase lists |
| **Tax** | Tax classes and rates per country, tax in the country of destination with threshold counter and quarterly export (EU OSS) |
| **GDPR** | Data access request as ZIP, erasure request, anonymisation once the retention period has expired |
| **Operations** | Dashboard with open tasks and findings, setup page, `consho:check` |

Consho does not supply legal texts. The core provides what is technically required — fields, checks, freezing at
the time of purchase — while the operator is responsible for the content.

**Paid extensions** are in preparation, the first being **Consho Connect** (product feeds, AI-readable
catalogue, write commands for [contao-ai](https://github.com/webwerkwien/contao-ai-core-bundle)). Whatever was once
in the free core stays there.

## Principles

| | |
|---|---|
| **Contao first** | Images, files, DCA, versioning, voters, members, multilingual content, routing and cache come from Contao, not rebuilt |
| **Contao 6** | Nothing that conflicts with Contao 6: Twig only, no input encoding, no `$GLOBALS['TL_CONFIG']` |
| **Accessibility** | WCAG 2.1 AA as the target for all front-end parts |

## Requirements

- PHP 8.4 with `gd`, `sodium` and `zip`
- Contao 5.7

## Installation

Search for `webwerkwien/contao-consho-bundle` in the Contao Manager, or:

```bash
composer require webwerkwien/contao-consho-bundle
```

Then update the database (`contao:migrate`) and start in the back end under **Consho → Dashboard → Einrichtung**
(Setup).

## Development

```bash
composer install
composer ci        # PHPStan + PHPUnit
```

## Licence

MIT — see [LICENSE](LICENSE).
