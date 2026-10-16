<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttemptRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;

/**
 * Versuche und Zahlungen im Speicher, mit denselben Regeln wie die Tabelle:
 * eine Zahlung je Versuch (eindeutiger Schlüssel), eine Rückkehr setzt
 * Abbruch oder Fehler nur auf offene Versuche. lock() zählt mit.
 */
final class InMemoryPaymentAttempts implements PaymentAttemptRepositoryInterface
{
    /** @var array<int, PaymentAttempt> */
    public array $attempts = [];

    /** @var array<int, string> hash per attempt id */
    public array $hashes = [];

    /** @var list<array{attempt: int, amount: string, reference: string, method: string}> */
    public array $payments = [];

    public int $locks = 0;

    public function add(PaymentAttempt $attempt, string $token): void
    {
        $this->attempts[$attempt->id] = $attempt;
        $this->hashes[$attempt->id] = hash('sha256', $token);
    }

    public function open(int $orderId, PaymentMethod $method, string $amount, string $currency, string $tokenHash, int $now, bool $charge = false): int
    {
        $id = \count($this->attempts) + 1;
        $this->attempts[$id] = new PaymentAttempt($id, $orderId, $method->id, $method->type, AttemptState::Open, $amount, $currency, '', '', '', null, 0, $charge);
        $this->hashes[$id] = $tokenHash;

        return $id;
    }

    public function findByHash(string $tokenHash): PaymentAttempt|null
    {
        $id = array_search($tokenHash, $this->hashes, true);

        return false === $id ? null : $this->attempts[$id];
    }

    public function lock(int $attemptId): PaymentAttempt|null
    {
        ++$this->locks;

        return $this->attempts[$attemptId] ?? null;
    }

    public function latestFor(int $orderId): PaymentAttempt|null
    {
        $mine = array_filter($this->attempts, static fn (PaymentAttempt $a): bool => $a->orderId === $orderId);

        return [] === $mine ? null : $mine[max(array_keys($mine))];
    }

    public function setState(int $attemptId, AttemptState $state, int $now, string $providerReference = ''): void
    {
        $this->replace($attemptId, state: $state, reference: '' === $providerReference ? null : $providerReference);
    }

    public function openBefore(int $before): array
    {
        return array_values(array_filter(
            $this->attempts,
            static fn (PaymentAttempt $a): bool => AttemptState::Open === $a->state && $a->createdAt > 0 && $a->createdAt <= $before,
        ));
    }

    public function cancelIfOpen(int $attemptId, int $now): bool
    {
        if (AttemptState::Open !== ($this->attempts[$attemptId]->state ?? null)) {
            return false;
        }

        $this->replace($attemptId, state: AttemptState::Cancelled);

        return true;
    }

    public function recordReturn(int $attemptId, AttemptState $returned, int $now): void
    {
        $current = $this->attempts[$attemptId];
        $state = (AttemptState::Failed === $returned || AttemptState::Cancelled === $returned) && \in_array($current->state, [AttemptState::Open, AttemptState::Pending], true) ? $returned : null;

        $this->replace($attemptId, state: $state, returned: $returned);
    }

    public function recordPayment(PaymentAttempt $attempt, string $amount, string $reference, string $methodTitle, int $now): void
    {
        foreach ($this->payments as $payment) {
            if ($payment['attempt'] === $attempt->id) {
                throw new UniqueConstraintViolationException(\Doctrine\DBAL\Driver\PDO\Exception::new(new \PDOException('duplicate')), null);
            }
        }

        $this->payments[] = ['attempt' => $attempt->id, 'amount' => $amount, 'reference' => $reference, 'method' => $methodTitle];
    }

    private function replace(int $id, AttemptState|null $state = null, string|null $reference = null, AttemptState|null $returned = null): void
    {
        $a = $this->attempts[$id];

        $this->attempts[$id] = new PaymentAttempt(
            $a->id,
            $a->orderId,
            $a->methodId,
            $a->methodType,
            $state ?? $a->state,
            $a->amount,
            $a->currency,
            $reference ?? $a->providerReference,
            $a->returnUrl,
            $a->notifyUrl,
            $returned ?? $a->returned,
            $a->createdAt,
            $a->charge,
        );
    }
}
