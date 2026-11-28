<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Intl\Countries;

/**
 * Makes the order mask readable: the purchase is stored as IDs and codes,
 * the operator reads names.
 */
class OrderFormListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * The member by name instead of the raw ID. Only the displayed value: the
     * field is read-only, and Contao does not submit a read-only widget, so
     * the text never travels back into the ID column.
     */
    #[AsCallback(table: 'tl_consho_order', target: 'fields.member.load')]
    public function member(mixed $value): string
    {
        $id = (int) $value;
        $lang = $GLOBALS['TL_LANG']['tl_consho_order'] ?? [];

        if ($id < 1) {
            return (string) ($lang['memberGuest'] ?? 'guest');
        }

        $member = $this->connection->fetchAssociative('SELECT firstname, lastname, email FROM tl_member WHERE id = ?', [$id]);

        if (false === $member) {
            return \sprintf($lang['memberMissing'] ?? 'missing (%d)', $id);
        }

        return \sprintf('%s (%s)', trim($member['firstname'].' '.$member['lastname']), $member['email']);
    }

    /**
     * Every country, not the operator's delivery countries: an order keeps
     * the country it was placed with, even after the shop stops serving it.
     * The keys are upper-case ISO codes, the way the order stores them.
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_order', target: 'fields.billingCountry.options')]
    #[AsCallback(table: 'tl_consho_order', target: 'fields.shippingCountry.options')]
    public function countries(): array
    {
        return Countries::getNames($GLOBALS['TL_LANGUAGE'] ?? 'en');
    }

    /**
     * The empty status is offered only while the order has none. Once one is
     * set, "-" would only invite saving an order without a status. Without
     * one, the blank stays: dropping it would preselect the first status, and
     * saving the form would set it without anyone choosing.
     */
    #[AsCallback(table: 'tl_consho_order', target: 'config.onload')]
    public function statusBlank(DataContainer|null $dc = null): void
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $dc || null === $request || 'edit' !== $request->query->get('act')) {
            return;
        }

        $status = $this->connection->fetchOne('SELECT orderStatus FROM tl_consho_order WHERE id = ?', [(int) $dc->id]);

        if ((int) $status > 0) {
            $GLOBALS['TL_DCA']['tl_consho_order']['fields']['orderStatus']['eval']['includeBlankOption'] = false;
        }
    }
}
