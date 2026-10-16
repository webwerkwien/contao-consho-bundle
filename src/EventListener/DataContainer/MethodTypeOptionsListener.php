<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;

/**
 * The type of a method is chosen from what is installed, not from a list in
 * the DCA: a carrier module registers its service and appears here.
 *
 * Labels from $GLOBALS['TL_LANG'][<table>]['types'], the key where a module
 * brings no text — choosable is better than hidden.
 */
class MethodTypeOptionsListener
{
    public function __construct(
        private readonly ShippingMethodTypes $shippingTypes,
        private readonly PaymentHandlers $paymentHandlers,
    ) {
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_shipping_method', target: 'fields.type.options')]
    public function shippingTypes(): array
    {
        return self::labelled($this->shippingTypes->keys(), 'tl_consho_shipping_method');
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_payment_method', target: 'fields.type.options')]
    public function paymentTypes(): array
    {
        return self::labelled($this->paymentHandlers->keys(), 'tl_consho_payment_method');
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private static function labelled(array $keys, string $table): array
    {
        $options = [];

        foreach ($keys as $key) {
            $options[$key] = (string) ($GLOBALS['TL_LANG'][$table]['types'][$key] ?? $key);
        }

        asort($options);

        return $options;
    }
}
