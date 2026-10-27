<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Symfony\Component\HttpFoundation\InputBag;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The withdrawal function on the order page (D-2, spec 6.1, W1): what the page
 * shows, and what the "Widerruf bestätigen" button does.
 *
 * Its own class rather than more of OrderController: the page already does
 * documents, payment change and payment retry, and the withdrawal is the one
 * part the law describes step by step.
 *
 * ── Three steps, no session ──────────────────────────────────────────────
 *
 *  1. "Vertrag widerrufen" — a GET to the page with `consho_withdraw=1`. A GET
 *     changes nothing; it only shows the declaration.
 *  2. The declaration: the name (prefilled, editable — "angibt oder
 *     bestätigt"), the order, the address the confirmation goes to, the
 *     whole order or single items (W3), a message.
 *  3. "Widerruf bestätigen" — the POST that records it (WithdrawalRecorder).
 *
 * The address is the order's and is shown, not asked: the token link came to
 * that address, and a field would let whoever holds the link send the
 * confirmation anywhere (W2 on the form, same reasoning here).
 */
class OrderPageWithdrawal
{
    public const FORM_PARAM = 'consho_withdraw';

    public function __construct(
        private readonly WithdrawalWindowReader $windows,
        private readonly DbalWithdrawalRepository $withdrawals,
        private readonly WithdrawalRecorder $recorder,
        private readonly MoneyFormatter $money,
    ) {
    }

    /**
     * What the template gets.
     *
     * `state`: `open` (button or form), null (no function: period 0, or
     * cancelled before shipping — nothing is said then, there is nothing to
     * withdraw from).
     *
     * `late` (WA): the window's estimated end has passed. The
     * button stays — the window counts from shipping, the period from
     * receipt — with the sentence "Nach unseren Daten ist die Frist
     * abgelaufen. Der Widerruf wird entsprechend geprüft." (worded
     * neutral between du and Sie). Until then it was an `expired` state with
     * the shop's address instead of the button.
     *
     * @param array<string, string> $order a row of tl_consho_order
     *
     * @return array{state: string|null, late: bool, form: bool, name: string, email: string, items: list<array{id: int, name: string, left: string, max: string, whole: bool}>, received: list<array{number: string, receivedAt: string, confirmed: bool}>}
     */
    public function view(int $orderId, array $order, bool $wantsForm, string $language, int $now): array
    {
        $window = $this->windows->of($orderId);
        $late = null !== $window && $window->isLateAt($now);
        $state = match (true) {
            null === $window, WindowState::Open !== $window->state => null,
            $late, $window->isOpenAt($now) => 'open',
            default => null,
        };

        $items = [];

        if ('open' === $state) {
            foreach ($this->withdrawals->goods($orderId) as $id => $good) {
                $left = BigDecimal::of($good['left']);

                if ($left->isPositive()) {
                    $items[] = [
                        'id' => $id,
                        'name' => $good['name'],
                        'left' => $this->money->quantity($good['left'], $language),
                        'max' => (string) $left->strippedOfTrailingZeros(),
                        'whole' => $good['whole'],
                    ];
                }
            }

            // Everything already withdrawn — the whole order, or every
            // position (Abo-8): no button for nothing.
            if ([] === $items) {
                $state = null;
            }
        }

        $formatter = new \IntlDateFormatter('' !== $language ? str_replace('-', '_', $language) : 'de', \IntlDateFormatter::LONG, \IntlDateFormatter::MEDIUM, date_default_timezone_get());

        return [
            'state' => $state,
            'late' => 'open' === $state && $late,
            'form' => 'open' === $state && $wantsForm,
            'name' => trim(($order['firstname'] ?? '').' '.($order['lastname'] ?? '')),
            'email' => (string) ($order['email'] ?? ''),
            'items' => $items,
            'received' => array_map(
                static fn (array $row): array => [
                    'number' => $row['number'],
                    'receivedAt' => (string) $formatter->format($row['receivedAt']),
                    'confirmed' => $row['confirmed'],
                ],
                $this->withdrawals->received($orderId),
            ),
        ];
    }

    /**
     * "Widerruf bestätigen". Returns the notice for the page, and whether the
     * buyer goes back to the declaration (a correctable input) or to the order.
     *
     * @param InputBag<bool|float|int|string> $post
     *
     * @return array{notice: string, form: bool}
     */
    public function declare(int $orderId, InputBag $post, int $now): array
    {
        $name = trim($post->getString('consho_withdraw_name'));

        if ('' === $name) {
            return ['notice' => 'withdrawNameMissing', 'form' => true];
        }

        $items = [];

        if ('items' === $post->getString('consho_withdraw_scope')) {
            foreach ($post->all('consho_withdraw_quantity') as $itemId => $quantity) {
                // A nested field (quantity[1][x]=1) is not a quantity. Refused
                // here, not converted — `(string)` on an array is a warning,
                // and under Symfony's error handler a 500 (D-2).
                if (!\is_scalar($quantity)) {
                    return ['notice' => 'withdrawInvalid', 'form' => true];
                }

                $quantity = trim(str_replace(',', '.', (string) $quantity));

                try {
                    $positive = '' !== $quantity && BigDecimal::of($quantity)->isPositive();
                } catch (MathException) {
                    return ['notice' => 'withdrawInvalid', 'form' => true];
                }

                if ($positive) {
                    $items[(int) $itemId] = $quantity;
                }
            }

            // "Einzelne Artikel" with nothing entered is not "the whole
            // order": that would record the opposite of what was chosen.
            if ([] === $items) {
                return ['notice' => 'withdrawNothingSelected', 'form' => true];
            }
        }

        $result = $this->recorder->record(new WithdrawalDeclaration(
            $orderId,
            WithdrawalSource::OrderPage,
            $name,
            $items,
            '',
            $post->getString('consho_withdraw_message'),
        ), $now);

        return match ($result->outcome) {
            WithdrawalOutcome::Recorded => ['notice' => $result->confirmed ? 'withdrawn' : 'withdrawnUnconfirmed', 'form' => false],
            WithdrawalOutcome::InvalidItems => ['notice' => 'withdrawInvalid', 'form' => true],
            WithdrawalOutcome::Closed, WithdrawalOutcome::UnknownOrder => ['notice' => 'withdrawClosed', 'form' => false],
        };
    }
}
