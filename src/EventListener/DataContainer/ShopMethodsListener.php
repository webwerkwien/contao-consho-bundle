<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;

/**
 * The choice of methods in the shop configuration (C2). Every method is
 * offered, unpublished ones marked: a shop is set up before a method goes
 * live, and the checkout skips it until then (DbalShippingMethodRepository,
 * DbalPaymentMethodRepository).
 */
class ShopMethodsListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_consho_shop', target: 'fields.shippingMethods.options')]
    public function shippingMethods(): array
    {
        // tstamp > 0: a row opened in the mask and never saved is no method.
        return $this->options('SELECT id, title, published FROM tl_consho_shipping_method WHERE tstamp > 0 ORDER BY title, id');
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_consho_shop', target: 'fields.paymentMethods.options')]
    public function paymentMethods(): array
    {
        // tstamp > 0: a row opened in the mask and never saved is no method.
        return $this->options('SELECT id, title, published FROM tl_consho_payment_method WHERE tstamp > 0 ORDER BY title, id');
    }

    /**
     * @return array<int, string>
     */
    private function options(string $sql): array
    {
        $marker = (string) ($GLOBALS['TL_LANG']['tl_consho_shop']['unpublishedMethod'] ?? 'unpublished');
        $options = [];

        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            $options[(int) $row['id']] = (string) $row['title'].((bool) $row['published'] ? '' : ' ('.$marker.')');
        }

        return $options;
    }
}
