# Consho — rules for agents

Applies to every coding agent working in this repository. Tool-specific instruction files only import this file,
so that there is **one** version.

## What this is

The free shop core for Contao (MIT): catalogue, cart and checkout, shipping and payment, documents after the
purchase, digital goods and subscriptions, prices, stock, tax, GDPR — several shops in one installation. Paid
extensions (such as Consho Connect) build on the core's read services; whatever was once in the free core stays
there.

**The specification lives in the private development repository** (`docs/block-*.md`, one file per block, with
its plans and open points) and is not published. With access: read the block's file before every change and update
its plan line afterwards. Without access, README, CHANGELOG and code apply.

## Principles

- **Agent-neutral:** no instructions, paths or file names tied to a particular tool.
- **Public files carry no internals, and they are English.** Everything that ships with the package or the
  repository — code, comments, tests, test data, configuration, README, this file, CHANGELOG, commit messages, tag
  and release texts — names no people, customers or testers (the copyright in LICENSE and `composer.json` stays),
  no notes, plans or other documents outside the repository, no servers, hosts, SSH users or server paths, no real
  test data (order, invoice or customer numbers, e-mail addresses — use `example.org`/`example.net`), no keys or
  key ids, no review or acceptance shorthands, sessions, agents or models, and no decision dates. A reason goes
  into the comment as a statement of fact. Block references such as "(C-9)" or "(Block D, 6.1)" stay. Comments,
  docblocks and docs are English, also in tests and also where a file still has German ones; language files and
  German UI texts stay German. Removing internals is not announced.
- **Consho supplies no legal texts.** The core provides what is technically required — fields, checks, freezing
  at the time of purchase — and the operator is responsible for the content.
- **Nothing left to guess.** A command that rejects an input names what is valid: an unknown shop id lists the
  existing ones (`Command\KnownShops`), an unreadable date gives the expected form. A test checks the end of the
  message — `expectExceptionMessage` and `assertStringContainsString` on its beginning let a missing hint through.

## Language and style

- Code comments, test comments, docs and commit messages in English; test method names and data-set labels are
  German.
- Follow the style of the neighbouring classes; class comments explain decisions, not the obvious.

## CHANGELOG

One `CHANGELOG.md`, public with the release. New entries go under `[Unreleased]`, in English, with the block or
issue in brackets at the end of the entry; at a release the section becomes `[x.y.z] – date`. It says only what
changes for operators: behaviour, fields, commands, migrations, what they have to do. The GitHub release note is a
copy of that section.

## Before every commit

```bash
vendor/bin/phpunit --no-coverage
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

- **The whole suite**, not only the tests you touched: some tests read the source code (such as
  `AddressShapeIsOwnedInOnePlaceTest`) and fail on files you did not think you had changed.
- A test that is green on its first run gets a **mutation check**: mutate a copy of the code, the test must turn
  red, copy it back — never `git checkout`/`restore`/`stash`/`reset`.
- **Commits with explicit paths** (`git commit -- <paths>`): other work may be going on in the same working tree;
  never commit or reset changes that are not yours.
- **Decimal columns in tests:** SQLite returns `3`, MySQL/MariaDB `3.0000`. Test normalisation (trimming zeros,
  formatting) directly with the MySQL form, not through the database; formatted output goes through
  `MoneyFormatter`.
- **Test schemas use the production column names** — build them from the DCA where possible. A schema that renames
  a reserved word (`order`) hides exactly the quoting bug it should find; Doctrine does not quote reserved column
  names in `insert()`/`update()`, use `quoteIdentifier()`.
- **A falsy value is not a missing one:** `'rte' => false` in a DCA breaks `contao:migrate`. To switch an option
  off, leave it out.
