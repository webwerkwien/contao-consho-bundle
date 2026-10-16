<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutData;

/**
 * The only place that reads and writes tl_consho_cart and
 * tl_consho_cart_item.
 *
 * Every change to the CONTENTS moves updatedAt forward — adding, changing a
 * quantity, removing: the cleanup cron counts from there, so a basket
 * somebody keeps using never expires under them.
 *
 * The notice deliberately does NOT. It is display state, not use of the
 * basket: setNotice() records a success or what the visitor just did on the
 * basket page — a refusal at the buy button has ridden in the address since
 * C-3c and touches no row at all — and takeNotice() is a read that happens to
 * clear a flag. If either extended the
 * lifetime, a forgotten tab polling the basket page would keep personal data
 * alive for as long as it stays open — which is the opposite of what the
 * fixed lifetimes are for.
 *
 * Every method that touches a line takes the basket id as well as the line id
 * and puts both into the WHERE. A line id is a small integer in a form; the
 * basket id comes from the cookie. Without the pair, guessing a number would
 * empty somebody else's basket.
 */
final class DbalCartRepository implements CartConversionInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The token identifies the visitor, not the basket: one cookie carries a
     * basket per shop (C-3b). Without the shop in the condition a visitor
     * would drop an article of shop A into the basket of shop B.
     */
    public function findByToken(string $token, int $shopId): Cart|null
    {
        return $this->cartFrom($this->connection->fetchAssociative(
            "SELECT id, member, shop, updatedAt FROM tl_consho_cart WHERE tokenHash = ? AND shop = ? AND state = 'open'",
            [CartToken::hash($token), $shopId],
        ));
    }

    /**
     * Every open basket the token holds — one per shop (C-3b).
     *
     * Login is the one moment that has to look past a single shop: the request
     * carrying the login form belongs to whichever page held it, not to the
     * shop whose basket is waiting. Taking over only one would orphan the
     * rest — nothing asks for their token again, and the cron deletes them
     * after thirty days without the visitor ever seeing them.
     *
     * @return list<Cart>
     */
    public function findAllByToken(string $token): array
    {
        $carts = [];

        foreach ($this->connection->fetchAllAssociative(
            "SELECT id, member, shop, updatedAt FROM tl_consho_cart WHERE tokenHash = ? AND state = 'open' ORDER BY id",
            [CartToken::hash($token)],
        ) as $row) {
            $cart = $this->cartFrom($row);

            if (null !== $cart) {
                $carts[] = $cart;
            }
        }

        return $carts;
    }

    public function findForMember(int $memberId, int $shopId): Cart|null
    {
        if ($memberId < 1) {
            return null;
        }

        // A member should have exactly one open basket PER SHOP (C-3b) — the
        // account is shared across shops, the basket is not. If a second one
        // within the same shop ever appears, the newest wins: that is a
        // decision, not an accident of fetchAssociative() taking the first
        // row, so the query says LIMIT 1.
        return $this->cartFrom($this->connection->fetchAssociative(
            "SELECT id, member, shop, updatedAt FROM tl_consho_cart WHERE member = ? AND shop = ? AND state = 'open' ORDER BY id DESC LIMIT 1",
            [$memberId, $shopId],
        ));
    }

    public function create(string|null $tokenHash, int $memberId, int $shopId, int $now): int
    {
        $this->connection->insert('tl_consho_cart', [
            'tstamp' => $now,
            'tokenHash' => $tokenHash,
            'member' => $memberId,
            'shop' => $shopId,
            'state' => 'open',
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Adds a quantity, or adds it to the line that is already there.
     *
     * The caller has already brought the quantity onto the step (Quantity);
     * adding two valid multiples of a step keeps it a multiple, so nothing is
     * rounded again here.
     *
     * Returns false when the line would grow past what the column can hold.
     * Nothing has been written then — not even updatedAt. The caller answers
     * that with CartNotice::InvalidQuantity, the same notice it shows when
     * Quantity::normalise() refuses a single number for the same reason.
     *
     * 🔴 This is a concurrency problem, not a counting problem.
     *
     * Adding runs on a route that is deliberately exempt from Contao's CSRF
     * check (C11), because the catalogue page is shared-cached and a request
     * token baked into it would belong to a stranger. So there is nothing to
     * slow a visitor down: a double click on "add to basket", or two tabs, and
     * two requests for the same article really are in flight at once.
     *
     * NumberRangeAllocator solves its race with SELECT … FOR UPDATE inside a
     * transaction. This one deliberately does not, for two separate reasons:
     *
     * - There is nothing left to lock. "quantity = quantity + :add" reads and
     *   writes within one statement, so no window exists between reading the
     *   old value and writing the new one. A lock protects such a window; the
     *   fix here is to not have one. Read-then-add-in-PHP would need the lock.
     * - FOR UPDATE would never be executed by a test. This class is exercised
     *   against a real SQLite database (DbalCartRepositoryTest), and SQLite
     *   has no FOR UPDATE. NumberRangeAllocator can afford it because it is
     *   tested against a mock that asserts on the SQL string — there, the lock
     *   is the thing under test; here it would be an untested clause guarding
     *   the only write anybody makes.
     *
     * The steps in order: UPDATE first, because by the time somebody clicks
     * twice the line usually exists. Changed nothing? Then either there is no
     * line yet or there is one and the ceiling stopped it — and hasLine() is
     * the question that separates them, but its answer is a GUESS about a
     * moment that has already passed.
     *
     * Both of its answers therefore end in a retry rather than in a verdict,
     * because the other request can slip in on either side of it:
     *
     * - "No line": insert one. The INSERT can lose to a request that got there
     *   first; the unique index on (pid, product) catches that, and the add is
     *   retried as the addition it turned out to be.
     * - "A line": add to it again. If the line was inserted between our UPDATE
     *   and our look, this succeeds and the quantity is where the visitor put
     *   it. Only a second failure is the ceiling — and only then is
     *   CartNotice::InvalidQuantity the truth.
     *
     * Reading hasLine() as a verdict was wrong in exactly one of the two
     * interleavings, and wrong in the way that costs the most: the quantity was
     * dropped AND the visitor was told their perfectly ordinary number was
     * impossible.
     *
     * One retry per branch, never a loop. A third attempt could find nothing a
     * second one could not, so there would be no ground on which to stop; and
     * both outcomes of the second attempt are already a complete answer.
     */
    public function addItem(int $cartId, int $articleId, string $quantity, int $now, int $subscriptionRule = 0): bool
    {
        /*
         * "The line" is article AND plan since Abo-2 (P9): the unique index
         * runs over (pid, product, subscriptionRule), and every step below
         * names all three — an UPDATE that forgot the plan would add a monthly
         * licence onto the one-time line.
         */
        if (!$this->addToLine($cartId, $articleId, $quantity, $now, $subscriptionRule)) {
            if ($this->hasLine($cartId, $articleId, $subscriptionRule)) {
                // A line is there NOW — which does not say it was there when
                // the UPDATE ran. Asking again is the only way to tell the two
                // apart: succeeds, and the line appeared in between; fails
                // again, and it really is the ceiling.
                //
                // A second attempt, not a loop. Whatever the third would find
                // the fourth could find too, so a loop would have no reason to
                // stop — and the caller has a correct answer either way: the
                // quantity went in, or the column cannot hold it.
                if (!$this->addToLine($cartId, $articleId, $quantity, $now, $subscriptionRule)) {
                    return false;
                }
            } else {
                try {
                    $this->connection->insert('tl_consho_cart_item', [
                        'pid' => $cartId,
                        'tstamp' => $now,
                        'product' => $articleId,
                        'quantity' => $quantity,
                        'addedAt' => $now,
                        'subscriptionRule' => $subscriptionRule,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Somebody inserted the line between our UPDATE and here.
                    // It was an addition after all — and it can now hit the
                    // ceiling.
                    if (!$this->addToLine($cartId, $articleId, $quantity, $now, $subscriptionRule)) {
                        return false;
                    }
                }
            }
        }

        $this->touch($cartId, $now);

        return true;
    }

    /**
     * "Lizenz verlängern" (Abo-7b, P67): the line that renews a subscription,
     * with the old subscription's quantity. Never added up — a second click
     * finds the line there and leaves it as it is (the unique key includes
     * `renews`, and addItem() never touches a renewal line).
     */
    public function addRenewal(int $cartId, int $articleId, string $quantity, int $subscriptionRule, int $renews, int $now): void
    {
        try {
            $this->connection->insert('tl_consho_cart_item', [
                'pid' => $cartId,
                'tstamp' => $now,
                'product' => $articleId,
                'quantity' => $quantity,
                'addedAt' => $now,
                'subscriptionRule' => $subscriptionRule,
                'renews' => $renews,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already in the basket: the click before this one put it there.
        }

        $this->touch($cartId, $now);
    }

    public function setQuantity(int $cartId, int $itemId, string $quantity, int $now): void
    {
        $this->connection->update(
            'tl_consho_cart_item',
            ['tstamp' => $now, 'quantity' => $quantity],
            ['id' => $itemId, 'pid' => $cartId],
        );

        $this->touch($cartId, $now);
    }

    public function removeItem(int $cartId, int $itemId, int $now): void
    {
        $this->connection->delete('tl_consho_cart_item', ['id' => $itemId, 'pid' => $cartId]);

        // The last line takes the address of the checkout with it (spec C,
        // C-5 point 3): whoever empties the basket believes it cleared.
        if (false === $this->connection->fetchOne('SELECT 1 FROM tl_consho_cart_item WHERE pid = ?', [$cartId])) {
            $this->connection->update('tl_consho_cart', ['checkoutData' => null], ['id' => $cartId]);
        }

        $this->touch($cartId, $now);
    }

    /**
     * @return list<CartItemRow>
     */
    public function itemsOf(int $cartId): array
    {
        // By addedAt, then id: two lines added in the same second still have a
        // fixed order, so the basket does not shuffle between two views.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, product, quantity, addedAt, subscriptionRule, renews FROM tl_consho_cart_item WHERE pid = ? ORDER BY addedAt, id',
            [$cartId],
        );

        return array_map(
            static fn (array $row) => new CartItemRow((int) $row['id'], (int) $row['product'], (string) $row['quantity'], (int) $row['addedAt'], (int) $row['subscriptionRule'], (int) $row['renews']),
            $rows,
        );
    }

    /**
     * The article of one line — and 0 when the line is not in this basket.
     * The pair in the WHERE is the same rule as everywhere here: a line id is
     * a small integer in a form, so a guessed number must read as "not yours",
     * not as somebody else's line.
     */
    public function articleOf(int $cartId, int $itemId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT product FROM tl_consho_cart_item WHERE id = ? AND pid = ?',
            [$itemId, $cartId],
        );
    }

    /**
     * The subscription a line renews (Abo-7b), 0 for any other line — and for
     * a line that is not in this basket, by the same pair as articleOf().
     */
    public function renewsOf(int $cartId, int $itemId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT renews FROM tl_consho_cart_item WHERE id = ? AND pid = ?',
            [$itemId, $cartId],
        );
    }

    public function setNotice(int $cartId, CartNotice $notice): void
    {
        $this->connection->update('tl_consho_cart', ['notice' => $notice->value], ['id' => $cartId]);
    }

    /**
     * The discount code typed in the basket (block H, H-4, W1) — kept as
     * typed less the outer spaces (W6), at most the column's 64 characters.
     * '' takes it away. It stays on the basket whether or not it applies: the
     * basket says why not, and the customer may still reach the minimum.
     */
    public function setDiscountCode(int $cartId, string $code, int $now): void
    {
        $this->connection->update(
            'tl_consho_cart',
            ['discountCode' => mb_substr(trim($code), 0, 64), 'updatedAt' => $now, 'tstamp' => $now],
            ['id' => $cartId],
        );
    }

    public function discountCodeOf(int $cartId): string
    {
        return (string) $this->connection->fetchOne('SELECT discountCode FROM tl_consho_cart WHERE id = ?', [$cartId]);
    }

    /**
     * The voucher codes of the basket (block Wertgutscheine, E3), in the
     * order they were typed — the order they pay in.
     *
     * @return list<string>
     */
    public function vouchersOf(int $cartId): array
    {
        $stored = (string) $this->connection->fetchOne('SELECT vouchers FROM tl_consho_cart WHERE id = ?', [$cartId]);

        return '' === $stored ? [] : array_values(array_filter(explode(',', $stored), static fn (string $code): bool => '' !== $code));
    }

    /**
     * @param list<string> $codes normalised codes
     */
    public function setVouchers(int $cartId, array $codes, int $now): void
    {
        $this->connection->update(
            'tl_consho_cart',
            ['vouchers' => implode(',', array_values(array_unique($codes))), 'updatedAt' => $now, 'tstamp' => $now],
            ['id' => $cartId],
        );
    }

    /**
     * What the visitor has entered in the checkout (5.1, 7.2). The column has
     * existed since C-3 and nothing wrote to it until C-5.
     */
    public function checkoutDataOf(int $cartId): CheckoutData
    {
        return CheckoutData::fromJson(
            (string) $this->connection->fetchOne('SELECT checkoutData FROM tl_consho_cart WHERE id = ?', [$cartId]),
        );
    }

    /**
     * `updatedAt` moves with it, so the clean-up cron measures the basket by
     * the last time the visitor did anything — entering an address is doing
     * something, even when no line changed. Otherwise a long checkout could
     * have its basket swept away underneath it.
     */
    public function saveCheckoutData(int $cartId, CheckoutData $data, int $now): void
    {
        $this->connection->update(
            'tl_consho_cart',
            ['checkoutData' => $data->toJson(), 'checkoutDataAt' => $now, 'updatedAt' => $now, 'tstamp' => $now],
            ['id' => $cartId],
        );
    }

    /**
     * Locks a basket for the purchase click and reports what it already is
     * (7.3, C-7).
     *
     * ⚠️ Only meaningful inside a transaction, and only against MySQL. The
     * lock is what makes the double click safe: the second request waits here,
     * and by the time it gets the row the first one has set `converted`, so it
     * is redirected to the order that already exists rather than placing a
     * second one. `FOR UPDATE` is written out the way NumberRangeAllocator
     * writes it — DBAL 4 has no platform method for it any more, and SQLite
     * has no row locks at all, so this is tested against a mocked connection.
     *
     * @return array{state: string, order: int}|null null when the basket is gone
     */
    public function lockForPurchase(int $cartId): array|null
    {
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT state, %s AS orderId FROM tl_consho_cart WHERE id = ? FOR UPDATE',
                $this->connection->quoteIdentifier('order'),
            ),
            [$cartId],
        );

        if (false === $row) {
            return null;
        }

        return ['state' => (string) $row['state'], 'order' => (int) $row['orderId']];
    }

    /**
     * The basket becomes an order and stops being a basket.
     *
     * **The token hash goes to NULL**, and that is not tidiness (spec, open
     * points, decided for C-7). The unique key is (tokenHash, shop) while the
     * code means "one OPEN basket per token and shop" — the state is not in
     * the key. A converted basket that kept its hash would make the same
     * visitor's next click in the same shop run into a
     * UniqueConstraintViolationException in create(): HTTP 500 instead of 303.
     *
     * Of the three ways out, this is the one that moves the evidence to where
     * it belongs: "this visitor has bought" is a fact about the ORDER.
     *
     * 🔴 **And no fresh cookie follows**, although this said so until the
     * review of 2026-09-23 (F9). It was planned and turned out to be
     * unnecessary: with the hash gone, the next "add to basket" from the same
     * cookie finds nothing, creates a basket, and the unique key it would have
     * collided with is free. Issuing a cookie as well would log the visitor
     * out of a basket they still hold in another shop.
     *
     * What DOES follow is the late double click: the converted basket cannot
     * be resolved any more, so a purchase click that arrives after the commit
     * is answered by the checkout rather than by the placer (F2,
     * CheckoutController).
     *
     * 🔴 **Written out rather than passed as an array**, and that is the whole
     * reason this is not a three-line update(). `order` is a reserved word in
     * MySQL AND SQLite, and Connection::update()/insert() do NOT quote the
     * columns they are given — measured against DBAL 4.4.4 on 2026-09-23, and
     * the comment in tl_consho_cart.php claimed the opposite until then.
     *
     * Nothing had ever written this column, so nothing had ever found out. The
     * first caller would have been this one, and it would have failed on c5
     * with a syntax error that names the column and explains nothing.
     */
    public function markConverted(int $cartId, int $orderId, int $now): void
    {
        $this->connection->executeStatement(
            \sprintf(
                'UPDATE tl_consho_cart SET state = ?, %s = ?, tokenHash = NULL, tstamp = ?, updatedAt = ? WHERE id = ?',
                $this->connection->quoteIdentifier('order'),
            ),
            ['converted', $orderId, $now, $now, $cartId],
        );
    }

    /**
     * Reads the notice and clears it in the same breath — it is shown once
     * (7.1). tryFrom(): a value written by an older version must not throw.
     *
     * The gap between the two statements is accepted, the way TranslationStorage
     * accepts its own: worst case, two requests that overlap by milliseconds
     * both see the same "added to your basket". A notice shown twice is not a
     * wrong basket, and paying for it with a transaction or a lock on every
     * basket page view would be the more expensive mistake.
     */
    public function takeNotice(int $cartId): CartNotice|null
    {
        $value = (string) $this->connection->fetchOne('SELECT notice FROM tl_consho_cart WHERE id = ?', [$cartId]);

        if ('' === $value) {
            return null;
        }

        $this->connection->update('tl_consho_cart', ['notice' => ''], ['id' => $cartId]);

        return CartNotice::tryFrom($value);
    }

    /**
     * Adds to an existing line — but only while the sum stays inside the
     * column. Ceiling and write are one statement on purpose: checked
     * apart, two adds that each fit on their own could still meet and
     * overflow between the check and the write.
     *
     * CAST(… AS DECIMAL): a quantity travels as a string, because an article
     * can be sold by the kilo. MySQL would turn "2.5000" into a float for the
     * arithmetic and compare floats against the ceiling; the cast keeps both
     * sides decimal. SQLite reads it as NUMERIC, which is what it already does
     * with the column. Neither needs a platform special case.
     *
     * Impure on purpose: called a second time after a lost INSERT race, and
     * the row it looks at has changed in between.
     *
     * @phpstan-impure
     *
     * @return bool whether a row was changed
     */
    private function addToLine(int $cartId, int $articleId, string $quantity, int $now, int $subscriptionRule): bool
    {
        return (int) $this->connection->executeStatement(
            'UPDATE tl_consho_cart_item
             SET quantity = quantity + CAST(:add AS DECIMAL(10, 4)), tstamp = :now
             WHERE pid = :cart AND product = :article AND subscriptionRule = :rule AND renews = 0
               AND quantity + CAST(:add AS DECIMAL(10, 4)) <= CAST(:max AS DECIMAL(10, 4))',
            [
                'add' => $quantity,
                'now' => $now,
                'cart' => $cartId,
                'article' => $articleId,
                'rule' => $subscriptionRule,
                'max' => Quantity::MAX,
            ],
        ) > 0;
    }

    private function hasLine(int $cartId, int $articleId, int $subscriptionRule): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_cart_item WHERE pid = :cart AND product = :article AND subscriptionRule = :rule AND renews = 0 LIMIT 1',
            ['cart' => $cartId, 'article' => $articleId, 'rule' => $subscriptionRule],
        );
    }

    /**
     * Moves a guest basket onto a member (7.1, step 5), within its own shop.
     *
     * The whole basket is passed, not just its id, because the shop it belongs
     * to decides which member basket it may merge into (C-3b) — across that
     * line the articles belong to a different invoicing entity.
     *
     * Without a member basket in that shop the guest basket simply changes
     * owner — its lines, its age, its id stay. With one, the lines are merged
     * (same article: the quantities add up) and the guest basket is deleted.
     *
     * The token hash is cleared in both cases: two ways to the same basket is one
     * too many, and the cookie is deleted in the same response.
     *
     * In a transaction: a merge that stops halfway would either lose lines or
     * double them.
     */
    public function takeOver(Cart $guest, int $memberId, int $now): void
    {
        $guestCartId = $guest->id;

        $this->connection->transactional(function () use ($guest, $guestCartId, $memberId, $now): void {
            $memberCart = $this->findForMember($memberId, $guest->shopId);

            if (null === $memberCart) {
                $this->connection->update(
                    'tl_consho_cart',
                    ['member' => $memberId, 'tokenHash' => null, 'tstamp' => $now, 'updatedAt' => $now],
                    ['id' => $guestCartId],
                );

                return;
            }

            foreach ($this->itemsOf($guestCartId) as $item) {
                // addItem() answers false where the sum would pass what the column
                // holds. Here that line simply does not come along: the member
                // already has more of that article than the column can carry, and
                // refusing the whole login over it would be absurd. The guest
                // basket is deleted either way — merging is not an operation the
                // visitor can retry.
                if ($item->renews > 0) {
                    $this->addRenewal($memberCart->id, $item->articleId, $item->quantity, $item->subscriptionRule, $item->renews, $now);
                } else {
                    $this->addItem($memberCart->id, $item->articleId, $item->quantity, $now, $item->subscriptionRule);
                }
            }

            // The vouchers typed as a guest come along (WG8): they are money,
            // and logging in must not make them vanish from the basket.
            $vouchers = $this->vouchersOf($guestCartId);

            if ([] !== $vouchers) {
                $this->setVouchers($memberCart->id, array_slice([...$this->vouchersOf($memberCart->id), ...$vouchers], 0, CodeEntry::MAX_VOUCHERS), $now);
            }

            $this->connection->delete('tl_consho_cart_item', ['pid' => $guestCartId]);
            $this->connection->delete('tl_consho_cart', ['id' => $guestCartId]);
        });
    }

    private function touch(int $cartId, int $now): void
    {
        $this->connection->update('tl_consho_cart', ['tstamp' => $now, 'updatedAt' => $now], ['id' => $cartId]);
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function cartFrom(array|false $row): Cart|null
    {
        if (false === $row) {
            return null;
        }

        return new Cart((int) $row['id'], (int) $row['member'], (int) $row['shop'], (int) $row['updatedAt']);
    }
}
