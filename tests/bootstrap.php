<?php declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Plain `vendor/bin/phpunit` runs the unit tests: services with injected
 * collaborators, no Contao framework, no database. That covers price
 * resolution, tax resolution, address normalisation and number ranges.
 *
 * Tests that need a real Contao container and database (migrations, DCA-driven
 * models) opt in via a trait and skip themselves when `CONTAO_ROOT` is not set,
 * so the suite stays green on a bare checkout. See the sibling
 * contao-ai-core-bundle for the same two-mode arrangement.
 */

require __DIR__ . '/../vendor/autoload.php';
