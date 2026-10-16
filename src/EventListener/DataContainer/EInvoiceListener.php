<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * E-invoices only with what an e-invoice must name (block R, R10): the
 * seller's company, country, VAT ID and a contact e-mail (EN 16931 BG-4,
 * BT-31, BG-6). Switched on without them, every e-invoice would fail its
 * validation at the recipient — refused here instead, where the operator
 * can fill them in.
 *
 * The values as SUBMITTED, falling back to the stored ones: the fields sit
 * in the same form, and a save callback sees the record before the save.
 *
 * On the console there is no form (Connect Stufe 2, CW-5, I2): contao-ai's
 * writer hands the callback a record with every value being saved
 * (getActiveRecord()), so a command switching e-invoicing on together with
 * the company is judged on both. In the mask the active record holds only
 * the fields processed so far — the form stays the last word there.
 */
class EInvoiceListener
{
    public const REQUIRED = ['invoiceCompany', 'invoiceCountry', 'vatId', 'invoiceContactEmail'];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
    ) {
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'fields.eInvoice.save')]
    public function __invoke(mixed $value, DataContainer $dc): mixed
    {
        if (!$value) {
            return $value;
        }

        $missing = $this->missing((int) $dc->id, $dc instanceof DC_Table ? $dc->getActiveRecord() ?? [] : []);

        if ([] !== $missing) {
            $lang = $GLOBALS['TL_LANG']['tl_consho_shop'] ?? [];
            $labels = array_map(static fn (string $field): string => (string) ($lang[$field][0] ?? $field), $missing);

            throw new \RuntimeException(\sprintf((string) ($lang['eInvoiceIncomplete'] ?? 'Missing: %s'), implode(', ', $labels)));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $active the record with the values being saved, where known
     *
     * @return list<string> the required fields that are empty
     */
    public function missing(int $shopId, array $active = []): array
    {
        $stored = $this->connection->fetchAssociative('SELECT '.implode(', ', self::REQUIRED).' FROM tl_consho_shop WHERE id = ?', [$shopId]) ?: [];
        $known = [...$stored, ...$active];
        $posted = $this->requests->getCurrentRequest()?->request;
        $missing = [];

        foreach (self::REQUIRED as $field) {
            $value = null !== $posted && $posted->has($field) ? $posted->get($field) : ($known[$field] ?? '');

            if ('' === trim(\is_scalar($value) ? (string) $value : '')) {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
