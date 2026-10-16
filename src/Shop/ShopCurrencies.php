<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shop;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The currencies a shop can accept (C-1c, spec 4.0).
 *
 * A shop is one invoicing entity, not one currency: which currencies it can
 * take is a business rule of the seller — it hangs off the bank account, the
 * payment method and whether a flat shipping rate carries an amount in that
 * currency. Which of them applies to a visitor is a property of the AUDIENCE
 * and therefore sits on the start page (tl_page), not here.
 *
 * Loaded once per request, since the back end asks from several callbacks, and
 * dropped on kernel.reset so a worker does not keep yesterday's shops — the
 * same arrangement as Pricing\HomeCountries.
 */
final class ShopCurrencies implements ResetInterface
{
    /**
     * @var array<int, list<string>>|null shop id => sorted currency codes
     */
    private array|null $byShop = null;

    /**
     * @var array<int, string> shop id => title, filled alongside $byShop
     */
    private array $titles = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The currencies this shop can accept.
     *
     * ⚠️ An empty or unreadable selection yields an EMPTY list, never ['EUR'].
     * A silent default is exactly the fallback spec section 7 forbids: a
     * misconfigured shop would sell in euro without anyone having said so.
     * The caller has to refuse instead — loudly, where the operator sees it.
     *
     * @return list<string> sorted ISO 4217 codes; empty for an unknown shop
     */
    public function of(int $shopId): array
    {
        return $this->byShop()[$shopId] ?? [];
    }

    /**
     * The currency that applies, given what is stored on a start page.
     *
     * The stored one if the shop still accepts it, the only one if the shop
     * accepts exactly one, otherwise none — the same three lines as
     * Pricing\HomeCountries::resolve(), and deliberately in the same place:
     * on the domain service, not on the DCA listener that happens to be the
     * first caller. C-1c has at least three of them — the back end mask, the
     * migration of task 3 ("a shop with exactly one currency writes it to its
     * start pages" IS this rule) and the finding of task 7. Left private on
     * the listener, two of the three would rebuild it untested.
     *
     * Returns null where the shop offers a choice: nobody but the editor may
     * make it, and a silent pick is what spec section 7 rules out.
     */
    public function resolve(int $shopId, string $stored): string|null
    {
        $currencies = $this->of($shopId);

        if (\in_array($stored, $currencies, true)) {
            return $stored;
        }

        return 1 === \count($currencies) ? $currencies[0] : null;
    }

    /**
     * The shop's title, for an error message that has to name it — an operator
     * running several shops cannot act on "the shop of this start page".
     *
     * Empty for an unknown shop, so the caller can tell the two cases apart
     * without a second query.
     */
    public function titleOf(int $shopId): string
    {
        $this->byShop();

        return $this->titles[$shopId] ?? '';
    }

    public function reset(): void
    {
        // Only $byShop, and that is enough: byShop() rebuilds both maps in one
        // pass, so $titles is replaced wholesale on the next read. Clearing it
        // here too would be a line no test could ever turn red.
        $this->byShop = null;
    }

    /**
     * @return array<int, list<string>>
     */
    private function byShop(): array
    {
        if (null === $this->byShop) {
            $map = [];
            $titles = [];

            // The title rides along rather than costing a second query: it is
            // needed only on the error path (titleOf()), but the row is read
            // here anyway.
            foreach ($this->connection->fetchAllAssociative('SELECT id, title, currencies FROM tl_consho_shop') as $row) {
                $titles[(int) $row['id']] = (string) ($row['title'] ?? '');

                /*
                 * Dropped, not refused — unlike ShopCurrenciesListener, which
                 * throws at the same finding. This runs while a page is being
                 * rendered, where the only useful answer is the part that can
                 * be honoured; the mask is where a wrong value gets named.
                 * Both readings come out of CurrencySelection so the two can
                 * never disagree about WHAT is wrong, only about the reaction.
                 */
                $map[(int) $row['id']] = CurrencySelection::fromBlob($row['currencies'])->valid;
            }

            $this->byShop = $map;
            $this->titles = $titles;
        }

        return $this->byShop;
    }
}
