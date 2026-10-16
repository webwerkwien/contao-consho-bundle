<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Subscription\SaleModes;

/**
 * Which contract a price row is for (Abo-1, A2, A4).
 *
 * The choice offers what the article is sold as and nothing else: "Einmalkauf"
 * where it is sold once, then the rules ticked at the (main) product — so a
 * price for a mode not on offer cannot be entered in the first place. The
 * save refuses the same, for a value that did not come from the list.
 *
 * In the list, a subscription price carries its rule in front; a one-time
 * price stays as it always looked.
 */
final class PriceSubscriptionRuleListener
{
    /**
     * @var array<int, string> rule titles, per request
     */
    private array $titles = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly SaleModes $saleModes,
        private readonly MoneyFormatter $money,
    ) {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_consho_price', target: 'fields.subscriptionRule.options')]
    public function options(DataContainer|null $dc = null): array
    {
        $articleId = $this->articleOf($dc);

        if ($articleId < 1) {
            return [];
        }

        $rules = $this->saleModes->of($articleId)->priceRules();
        $titles = $this->titles(array_values(array_filter($rules, static fn (int $id): bool => $id > 0)));
        $options = [];

        foreach ($rules as $rule) {
            $options[$rule] = 0 === $rule
                ? (string) ($GLOBALS['TL_LANG']['tl_consho_price']['oneTimeSale'] ?? 'Einmalkauf')
                : ($titles[$rule] ?? (string) $rule);
        }

        return $options;
    }

    #[AsCallback(table: 'tl_consho_price', target: 'fields.subscriptionRule.save')]
    public function refuseNotOffered(mixed $value, DataContainer $dc): mixed
    {
        /*
         * Digits only. `(int) 'Monatsabo'` is 0, and 0 — the one-time sale —
         * is on offer at most products: a label arriving as the value would
         * pass as a one-time price. That is what the mask did before the field
         * was marked isAssociative (Abo-1 acceptance).
         */
        if (!\is_scalar($value) || 1 !== preg_match('/^\d+$/', (string) $value) || !\array_key_exists((int) $value, $this->options($dc))) {
            throw new \RuntimeException((string) ($GLOBALS['TL_LANG']['tl_consho_price']['ruleNotOffered'] ?? 'Not offered.'));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_price', target: 'list.label.label')]
    public function label(array $row, string $label): string
    {
        // From the row, not the DCA's format: that printed decimal(10,4) raw,
        // "EUR 25.0000 netto ab 1.0000" (Prüfungsnotiz, Fall 30).
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $label = \sprintf(
            '%s netto <span class="tl_gray">ab %s</span>',
            htmlspecialchars($this->money->format((string) ($row['net'] ?? '0'), (string) ($row['currency'] ?? ''), $language), ENT_QUOTES),
            htmlspecialchars($this->money->quantity((string) ($row['minQuantity'] ?? '0'), $language), ENT_QUOTES),
        );

        $rule = (int) ($row['subscriptionRule'] ?? 0);

        if ($rule < 1) {
            return $label;
        }

        $title = $this->titles([$rule])[$rule] ?? (string) $rule;

        return \sprintf('<strong>%s</strong> · %s', htmlspecialchars($title, ENT_QUOTES), $label);
    }

    /**
     * The price row's article: `pid` of the row being edited.
     */
    private function articleOf(DataContainer|null $dc): int
    {
        // Not `$dc->id ?? 0`: DataContainer has __get but no __isset (WeightBracketListener).
        $id = null === $dc ? 0 : (int) $dc->id;

        if ($id < 1) {
            return 0;
        }

        return (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_price WHERE id = ?', [$id]);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function titles(array $ids): array
    {
        $missing = array_values(array_diff($ids, array_keys($this->titles)));

        if ([] !== $missing) {
            $rows = $this->connection->fetchAllKeyValue(
                'SELECT id, title FROM tl_consho_subscription_rule WHERE id IN (?)',
                [$missing],
                [ArrayParameterType::INTEGER],
            );

            foreach ($rows as $id => $title) {
                $this->titles[(int) $id] = (string) $title;
            }
        }

        return array_intersect_key($this->titles, array_flip($ids));
    }
}
