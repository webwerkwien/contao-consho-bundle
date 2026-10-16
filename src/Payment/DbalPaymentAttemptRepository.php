<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalPaymentAttemptRepository implements PaymentAttemptRepositoryInterface
{
    private const COLUMNS = 'id, pid, method, methodType, state, amount, currency, providerReference, returnState, createdAt, charge';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function open(int $orderId, PaymentMethod $method, string $amount, string $currency, string $tokenHash, int $now, bool $charge = false): int
    {
        $this->connection->insert('tl_consho_payment_attempt', [
            'pid' => $orderId,
            'tstamp' => $now,

            // Reference plus copy, like the order head: the TYPE decides which
            // handler answers a notification, and it has to keep working after
            // the method row is reconfigured or deleted.
            'method' => $method->id,
            'methodType' => $method->type,
            'state' => AttemptState::Open->value,
            'amount' => $amount,
            'currency' => $currency,
            'tokenHash' => $tokenHash,
            'providerReference' => '',
            'createdAt' => $now,
            'updatedAt' => $now,
            'charge' => $charge ? 1 : 0,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function findByHash(string $tokenHash): PaymentAttempt|null
    {
        return self::attemptOf($this->connection->fetchAssociative(
            \sprintf('SELECT %s FROM tl_consho_payment_attempt WHERE tokenHash = ?', self::COLUMNS),
            [$tokenHash],
        ));
    }

    public function lock(int $attemptId): PaymentAttempt|null
    {
        return self::attemptOf($this->connection->fetchAssociative(
            \sprintf('SELECT %s FROM tl_consho_payment_attempt WHERE id = ? FOR UPDATE', self::COLUMNS),
            [$attemptId],
        ));
    }

    public function latestFor(int $orderId): PaymentAttempt|null
    {
        return self::attemptOf($this->connection->fetchAssociative(
            \sprintf('SELECT %s FROM tl_consho_payment_attempt WHERE pid = ? ORDER BY id DESC LIMIT 1', self::COLUMNS),
            [$orderId],
        ));
    }

    public function setState(int $attemptId, AttemptState $state, int $now, string $providerReference = ''): void
    {
        $this->connection->update(
            'tl_consho_payment_attempt',
            ['state' => $state->value, 'tstamp' => $now, 'updatedAt' => $now] + ('' === $providerReference ? [] : ['providerReference' => $providerReference]),
            ['id' => $attemptId],
        );
    }

    public function openBefore(int $before): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s FROM tl_consho_payment_attempt WHERE state = ? AND createdAt > 0 AND createdAt <= ? ORDER BY id', self::COLUMNS),
            [AttemptState::Open->value, $before],
        );

        return array_values(array_filter(array_map(self::attemptOf(...), $rows)));
    }

    public function cancelIfOpen(int $attemptId, int $now): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'UPDATE tl_consho_payment_attempt SET state = ?, tstamp = ?, updatedAt = ? WHERE id = ? AND state = ?',
            [AttemptState::Cancelled->value, $now, $now, $attemptId, AttemptState::Open->value],
        );
    }

    public function recordReturn(int $attemptId, AttemptState $returned, int $now): void
    {
        $this->connection->update('tl_consho_payment_attempt', ['returnState' => $returned->value, 'tstamp' => $now, 'updatedAt' => $now], ['id' => $attemptId]);

        if (AttemptState::Failed !== $returned && AttemptState::Cancelled !== $returned) {
            return;
        }

        // In the WHERE, not decided beforehand: the notification may have
        // turned the attempt to succeeded a moment ago.
        $this->connection->executeStatement(
            'UPDATE tl_consho_payment_attempt SET state = ? WHERE id = ? AND state IN (?)',
            [$returned->value, $attemptId, [AttemptState::Open->value, AttemptState::Pending->value]],
            [2 => ArrayParameterType::STRING],
        );
    }

    public function recordPayment(PaymentAttempt $attempt, string $amount, string $reference, string $methodTitle, int $now): void
    {
        $this->connection->insert('tl_consho_payment', [
            'pid' => $attempt->orderId,
            'tstamp' => $now,
            'receivedAt' => $now,
            'amount' => $amount,
            'method' => mb_substr($methodTitle, 0, 64),
            'reference' => mb_substr($reference, 0, 255),
            'attempt' => $attempt->id,
        ]);
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private static function attemptOf(array|false $row): PaymentAttempt|null
    {
        if (false === $row) {
            return null;
        }

        $returned = (string) ($row['returnState'] ?? '');

        return new PaymentAttempt(
            (int) $row['id'],
            (int) $row['pid'],
            (int) $row['method'],
            (string) $row['methodType'],

            // tryFrom with a fallback, not from(): a value written by a later
            // version must not make an older one throw while reading its own
            // table. An unknown state reads as open, which is the state that
            // grants nothing.
            AttemptState::tryFrom((string) $row['state']) ?? AttemptState::Open,
            (string) $row['amount'],
            (string) $row['currency'],
            (string) $row['providerReference'],

            // The URLs are built by the caller that knows the routes; an
            // attempt read back from the database carries none.
            '',
            '',
            '' === $returned ? null : AttemptState::tryFrom($returned),
            (int) ($row['createdAt'] ?? 0),
            (bool) ($row['charge'] ?? false),
        );
    }
}
