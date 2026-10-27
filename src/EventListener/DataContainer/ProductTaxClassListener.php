<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Pricing\HomeCountries;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceEntry;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceInput;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceInputMode;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;

/**
 * A product changes its tax class: the price rows typed as gross keep their
 * gross, and their net is computed anew at the new rate.
 *
 * Net is what a price row stores (C22) — kept as it was, a gross row would
 * show another gross after the change, and the shop would charge it: a price
 * of 12,00 € typed as gross becomes 11,00 € when the class goes from 20 % to
 * 10 %, and nobody typed that number. A row typed as net was meant as net
 * and stays.
 *
 * The gross is the one the price mask shows (PriceEntry::toAmount()): at the
 * old class, in the row's home country, at the price's start or today.
 *
 * Two steps, because the old class is known only before the product is
 * written and the price rows may change only once it has been: onbeforesubmit
 * plans, onsubmit writes. A save refused in between leaves the prices alone.
 */
class ProductTaxClassListener
{
    /**
     * @var array<int, list<array{id: int, net: string, newNet: string, label: string}>>
     */
    private array $planned = [];

    /**
     * @var array<int, list<string>>
     */
    private array $unconverted = [];

    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly HomeCountries $homeCountries,
        private readonly PriceEntry $entry,
        private readonly MoneyFormatter $money,
        private readonly ContaoFramework $framework,
        private readonly \Closure|null $clock = null,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.onbeforesubmit')]
    public function onBeforeSubmit(array $values, DataContainer $dc): array
    {
        $productId = (int) $dc->id;
        unset($this->planned[$productId], $this->unconverted[$productId]);

        if (!\array_key_exists('taxClass', $values)) {
            return $values;
        }

        $old = (int) ($dc->getCurrentRecord()['taxClass'] ?? 0);
        $new = (int) $values['taxClass'];

        // Without an old class no gross was ever shown; without a new one
        // there is no rate to compute with (the field is mandatory).
        if ($old < 1 || $new < 1 || $old === $new) {
            return $values;
        }

        $now = null === $this->clock ? time() : ($this->clock)();
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'en');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, net, currency, inputCountry, validFrom FROM tl_consho_price WHERE pid = ? AND inputMode = ? AND tstamp > 0 ORDER BY id',
            [$productId, PriceInputMode::Gross->value],
        );

        foreach ($rows as $row) {
            $currency = (string) $row['currency'];
            $net = (string) $row['net'];
            $at = PriceInput::conversionTime((int) $row['validFrom'], $now);
            $country = $this->homeCountries->resolve($currency, (string) $row['inputCountry']);
            $newNet = null;
            $label = '';

            // Without a home country or a rate on either side the gross cannot
            // be carried over: the row keeps its net, and the message says so.
            if (null !== $country) {
                try {
                    $gross = $this->entry->toAmount(PriceInputMode::Gross, $net, $old, $country, $at, $currency);
                    $label = $this->money->format($gross, $currency, $language).' ('.$country.')';
                    $newNet = $this->entry->toNet(PriceInputMode::Gross, $gross, $new, $country, $at);
                } catch (TaxRateNotFound) {
                }
            }

            if (null === $newNet) {
                $this->unconverted[$productId][] = \sprintf($GLOBALS['TL_LANG']['CONSHO']['taxClassChange']['net'] ?? '%s net', $this->money->format($net, $currency, $language));

                continue;
            }

            if (!BigDecimal::of($newNet)->isEqualTo($net)) {
                $this->planned[$productId][] = ['id' => (int) $row['id'], 'net' => $net, 'newNet' => $newNet, 'label' => $label];
            }
        }

        return $values;
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $productId = (int) $dc->id;
        $planned = $this->planned[$productId] ?? [];
        $unconverted = $this->unconverted[$productId] ?? [];
        unset($this->planned[$productId], $this->unconverted[$productId]);

        $converted = [];

        foreach ($planned as $price) {
            // Only the net that was read: a row changed meanwhile is the
            // operator's, not this conversion's.
            if ($this->connection->executeStatement('UPDATE tl_consho_price SET net = ? WHERE id = ? AND net = ?', [$price['newNet'], $price['id'], $price['net']]) > 0) {
                $converted[] = $price['label'];
            }
        }

        $message = $this->framework->getAdapter(Message::class);

        if ([] !== $converted) {
            $message->addInfo(\sprintf($GLOBALS['TL_LANG']['CONSHO']['taxClassChange']['converted'], implode(', ', $converted)));
        }

        if ([] !== $unconverted) {
            $message->addError(\sprintf($GLOBALS['TL_LANG']['CONSHO']['taxClassChange']['unconverted'], implode(', ', $unconverted)));
        }
    }
}
