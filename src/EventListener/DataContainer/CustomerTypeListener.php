<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\DC_Table;
use Contao\Message;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerType;

/**
 * Keeps the withdrawal period in line with the customer type of a shop.
 *
 *  - Businesses only: no withdrawal period. Period and delivery allowance are
 *    set to 0 after every save, whatever was submitted — the mask hides both
 *    fields, a console command could still send them.
 *  - Consumers, delivering into the EU or the EEA: a period of 0 entered over
 *    an existing period is refused. Where the period is 0 already and the save
 *    turns the switch back to consumers or changes the delivery countries, the
 *    statutory 14 days and an allowance of 7 (if it was 0) are set, and the
 *    operator is told. A save that changes neither leaves an existing 0 alone;
 *    the setup warning about a short period still shows it.
 *  - Consumers outside the EU and the EEA keep whatever they enter, 0
 *    included: no withdrawal right is owed there.
 *
 * The values being saved come from the active record: the mask lays the
 * submitted values over it, contao-ai's console writer the values it sets,
 * and both fields sit before the period in the palette. Whether a save
 * changes the switch or the countries is noted by their own save callbacks,
 * which run before the row is written; onsubmit runs after.
 *
 * The onsubmit write goes past the mask: it is one pair of numbers the
 * operator's own choice implies, and the message says what was set.
 */
class CustomerTypeListener
{
    public const STATUTORY_DAYS = 14;

    public const DEFAULT_GRACE_DAYS = 7;

    /** @var array<int, true> shops whose switch or delivery countries change in the current save */
    private array $changed = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
    ) {
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'fields.customerType.save')]
    public function noteCustomerType(mixed $value, DataContainer $dc): mixed
    {
        $stored = $this->stored((int) $dc->id);

        if (null !== $stored && CustomerType::of($stored['customerType']) !== CustomerType::of(\is_scalar($value) ? (string) $value : null)) {
            $this->changed[(int) $dc->id] = true;
        }

        return $value;
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'fields.deliveryCountries.save')]
    public function noteDeliveryCountries(mixed $value, DataContainer $dc): mixed
    {
        $stored = $this->stored((int) $dc->id);

        if (null !== $stored && self::countries($stored['deliveryCountries']) !== self::countries($value)) {
            $this->changed[(int) $dc->id] = true;
        }

        return $value;
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'fields.withdrawalDays.save')]
    public function refuseZero(mixed $value, DataContainer $dc): mixed
    {
        if (!\is_scalar($value) || '' === trim((string) $value) || (int) $value > 0) {
            return $value;
        }

        $stored = $this->stored((int) $dc->id);

        // Only a 0 entered over a period is refused; an existing 0 is aligned
        // in onsubmit where the save changes the switch or the countries.
        if (null === $stored || (int) $stored['withdrawalDays'] <= 0) {
            return $value;
        }

        $known = [...$stored, ...($dc instanceof DC_Table ? $dc->getActiveRecord() ?? [] : [])];

        if (CustomerType::Consumer === CustomerType::of($known['customerType'] ?? null) && CustomerType::deliversIntoEuOrEea($known['deliveryCountries'] ?? null)) {
            throw new \RuntimeException((string) ($GLOBALS['TL_LANG']['tl_consho_shop']['withdrawalZeroConsumer'] ?? 'A shop delivering to consumers in the EU or the EEA needs a withdrawal period.'));
        }

        return $value;
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $id = (int) $dc->id;
        $changed = isset($this->changed[$id]);
        unset($this->changed[$id]);

        $message = $this->align($id, time(), $changed);

        if (null !== $message) {
            $this->framework->getAdapter(Message::class)->addConfirmation($message);
        }
    }

    /**
     * @param bool $changed whether this save changed the switch or the delivery countries
     *
     * @return string|null what was set, or null where nothing was
     */
    public function align(int $shopId, int $now, bool $changed = true): string|null
    {
        $shop = $this->connection->fetchAssociative('SELECT customerType, withdrawalDays, withdrawalGraceDays, deliveryCountries FROM tl_consho_shop WHERE id = ?', [$shopId]);

        if (false === $shop) {
            return null;
        }

        $lang = $GLOBALS['TL_LANG']['tl_consho_shop'] ?? [];
        $days = (int) $shop['withdrawalDays'];
        $grace = (int) $shop['withdrawalGraceDays'];

        if (CustomerType::Business === CustomerType::of($shop['customerType'])) {
            if (0 === $days && 0 === $grace) {
                return null;
            }

            $this->connection->update('tl_consho_shop', ['withdrawalDays' => 0, 'withdrawalGraceDays' => 0, 'tstamp' => $now], ['id' => $shopId]);

            return (string) ($lang['customerTypeBusinessSet'] ?? 'Businesses only: withdrawal period and allowance set to 0.');
        }

        if (!$changed || $days > 0 || !CustomerType::deliversIntoEuOrEea($shop['deliveryCountries'])) {
            return null;
        }

        $grace = $grace > 0 ? $grace : self::DEFAULT_GRACE_DAYS;
        $this->connection->update('tl_consho_shop', ['withdrawalDays' => self::STATUTORY_DAYS, 'withdrawalGraceDays' => $grace, 'tstamp' => $now], ['id' => $shopId]);

        return \sprintf((string) ($lang['withdrawalDefaultsSet'] ?? 'Withdrawal period now %s days, allowance %s days.'), self::STATUTORY_DAYS, $grace);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stored(int $shopId): array|null
    {
        return $this->connection->fetchAssociative('SELECT customerType, deliveryCountries, withdrawalDays FROM tl_consho_shop WHERE id = ?', [$shopId]) ?: null;
    }

    /**
     * A country list as stored or as submitted (serialized, array, empty),
     * sorted, so the same countries compare equal.
     *
     * @return list<string>
     */
    private static function countries(mixed $value): array
    {
        $list = \is_array($value) ? $value : StringUtil::deserialize(\is_scalar($value) ? (string) $value : '', true);
        $list = array_values(array_filter(array_map(static fn (mixed $c): string => \is_scalar($c) ? strtoupper((string) $c) : '', $list)));
        sort($list);

        return $list;
    }
}
