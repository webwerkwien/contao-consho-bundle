<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

interface PaymentAttemptRepositoryInterface
{
    /**
     * Opens an attempt and returns it, token hash included.
     *
     * The PLAINTEXT token never comes back from here — it goes into the return
     * and notify URLs the core builds, and those are built by the caller that
     * generated it. A repository that could hand out the plaintext would make
     * a database dump enough to confirm a payment.
     *
     * @param bool $charge opened by the subscription cron to charge without
     *                     the customer (Abo-4b, P94)
     */
    public function open(int $orderId, PaymentMethod $method, string $amount, string $currency, string $tokenHash, int $now, bool $charge = false): int;

    /**
     * @return PaymentAttempt|null null when the hash is unknown
     */
    public function findByHash(string $tokenHash): PaymentAttempt|null;

    /**
     * The attempt, locked FOR UPDATE until the caller's transaction ends —
     * the lock between return and notification (spec 7.4, step 3).
     */
    public function lock(int $attemptId): PaymentAttempt|null;

    /**
     * The newest attempt of an order, or null for an order that has none
     * (every order placed before C-11).
     */
    public function latestFor(int $orderId): PaymentAttempt|null;

    public function setState(int $attemptId, AttemptState $state, int $now, string $providerReference = ''): void;

    /**
     * Open attempts started at or before $before (C-11, the cleanup cron) —
     * inclusive, the same boundary as PaymentStarter::lapsed().
     *
     * @return list<PaymentAttempt>
     */
    public function openBefore(int $before): array;

    /**
     * Cancels an attempt only if it is still open — the notification may
     * have paid it since it was read. True when this call cancelled it.
     */
    public function cancelIfOpen(int $attemptId, int $now): bool;

    /**
     * Records what a browser return said (C-11). Failed or cancelled also
     * becomes the state — but only of an attempt still open or pending: a
     * return must never undo a payment the notification already confirmed.
     */
    public function recordReturn(int $attemptId, AttemptState $returned, int $now): void;

    /**
     * The money, as a row in tl_consho_payment tied to its attempt. The column
     * is unique: a second row for the same attempt is refused by the database
     * (UniqueConstraintViolationException), whatever the lock did.
     */
    public function recordPayment(PaymentAttempt $attempt, string $amount, string $reference, string $methodTitle, int $now): void;
}
