# Consho

E-Commerce für Contao. Mehrere Shops in einer Installation, für EU-Recht gebaut.

> **Version 0.x.** Consho ist vollständig benutzbar, aber noch vor 1.0: Schnittstellen und Datenmodell können sich
> zwischen Minor-Versionen ändern. Vor jedem Update den [CHANGELOG](CHANGELOG.md) lesen.

## Was Consho kann

Der Kern ist frei (MIT) und betreibt einen vollständigen Shop:

| Bereich | |
|---|---|
| **Katalog** | Produkte mit Varianten, Eigenschaften und Zusatzfeldern; Liste, Filter, Landingpages, Detailseite, Sitemap, JSON-LD; Grundpreis, Herstellerangaben, GARAN-Label |
| **Warenkorb und Checkout** | Gastbestellung, Kundengruppen, Netto für Geschäftskunden, Reverse Charge mit VIES-Prüfung; Button-Lösung, AGB-Zustimmung, Widerrufsbelehrung |
| **Versand und Zahlung** | Versandarten mit Gewichtsstaffel, Ländern und Sperrgut; Vorkasse, „Auf Rechnung", Nachnahme; Andockstellen für Zahlungsmodule und Paketdienste |
| **Nach dem Kauf** | Rechnung als PDF und HTML (mit Giro-Code), E-Rechnung ZUGFeRD EN 16931 an Geschäftskunden; Mails; Bestellseite über Token; Widerrufsfunktion (§ 356a BGB, § 13a FAGG); Kundenkonto |
| **Digitale Güter und Abos** | Dateien und Lizenzschlüssel, Laufzeiten, Abos mit Aboregeln, Folgebestellungen, Kündigungsschaltfläche, Pausen |
| **Preise** | Aktionen mit Streichpreis nach § 9a PrAG / § 11 PAngV, Rabattregeln, Rabattcodes, Wertgutscheine |
| **Lager** | Bestand, Verfügbarkeitsanzeige, Benachrichtigung bei Wiederverfügbarkeit, Einkaufslisten |
| **Steuer** | Steuerklassen und -sätze je Land, Steuer im Bestimmungsland mit Schwellen-Zähler und Quartalsexport (EU-OSS) |
| **DSGVO** | Auskunft als ZIP, Löschantrag, Anonymisieren nach Ablauf der Aufbewahrungsfrist |
| **Betrieb** | Dashboard mit offenen Aufgaben und Befunden, Einrichtungsseite, `consho:check` |

Consho setzt keine Rechtstexte. Der Kern bringt mit, was technisch nötig ist — Felder, Prüfungen, das Einfrieren beim
Kauf —, den Inhalt verantwortet der Betreiber.

**Kostenpflichtige Erweiterungen** sind in Vorbereitung, als erste **Consho Connect** (Produktfeeds, KI-lesbarer
Katalog, Schreibbefehle für [contao-ai](https://github.com/webwerkwien/contao-ai-core-bundle)). Was einmal im freien
Kern war, bleibt darin.

## Grundsätze

| | |
|---|---|
| **Contao zuerst** | Bilder, Dateien, DCA, Versionierung, Voter, Mitglieder, Mehrsprachigkeit, Routing und Cache kommen aus Contao, nicht nachgebaut |
| **Contao 6** | Nichts, was Contao 6 widerspricht: nur Twig, kein Input-Encoding, kein `$GLOBALS['TL_CONFIG']` |
| **Barrierefreiheit** | WCAG 2.1 AA als Ziel für alle Frontend-Teile |

## Anforderungen

- PHP 8.4 mit `gd`, `sodium` und `zip`
- Contao 5.7

## Installation

Im Contao Manager nach `webwerkwien/contao-consho-bundle` suchen, oder:

```bash
composer require webwerkwien/contao-consho-bundle
```

Danach die Datenbank aktualisieren (`contao:migrate`) und im Backend unter **Consho → Dashboard → Einrichtung**
beginnen.

## Entwicklung

```bash
composer install
composer ci        # PHPStan + PHPUnit
```

## Lizenz

MIT — siehe [LICENSE](LICENSE).
