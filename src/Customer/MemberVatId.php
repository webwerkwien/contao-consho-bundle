<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Address\VatIdValidatorInterface;

/**
 * The VIES result of a member's VAT ID, kept on the member and reused.
 *
 * How often a VAT ID has to be confirmed is not laid down in law, and asking
 * twice on the same day is pointless: a confirmation holds for the whole day
 * of the query (the Austrian finance ministry's business service portal on
 * the confirmation procedure). Hence two rules:
 *
 *   - a result holds twelve months — registration, personal data, back end,
 *     and a purchase that is taxed anyway;
 *   - a purchase with reverse charge wants a result from the same day. The
 *     exemption of an intra-community supply rests on the VAT ID the customer
 *     gave (Art. 7 (1) no. 4 of the internal market annex to the Austrian VAT
 *     act), and a query on the day of the supply is the plainest evidence.
 *
 * A stored result is only reused for the same VAT ID and the same requester:
 * VIES issues the consultation number for the business that asked, and an
 * installation may run shops of more than one business. "Unavailable" is
 * stored for the record but never reused — it is no verdict.
 */
final class MemberVatId
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VatIdValidatorInterface $validator,
    ) {
    }

    /**
     * The member's result, checked with VIES where the stored one does not
     * hold. Null where the member has no VAT ID (the stored result is cleared
     * then) or does not exist.
     *
     * @param string $requester the asking shop's VAT ID, '' for none
     * @param bool   $sameDay   only a result from today holds (reverse charge)
     */
    public function ensure(int $memberId, string $requester, bool $sameDay, int $now): VatIdCheckResult|null
    {
        $row = $this->row($memberId);

        if (null === $row) {
            return null;
        }

        $vatId = self::normalize($row['consho_vatId']);
        $requester = self::normalize($requester);

        if ('' === $vatId) {
            if ('' !== $row['consho_vatIdStatus']) {
                $this->connection->update('tl_member', [
                    'consho_vatIdStatus' => '',
                    'consho_vatIdCheckedAt' => null,
                    'consho_vatIdRequestIdentifier' => '',
                    'consho_vatIdRequester' => '',
                    'consho_vatIdChecked' => '',
                ], ['id' => $memberId]);
            }

            return null;
        }

        $stored = self::storedOf($row, $requester);

        if (null !== $stored && self::holds($stored, $sameDay, $now)) {
            return $stored;
        }

        $result = $this->validator->check($vatId, '' === $requester ? null : $requester);
        $this->write($memberId, $vatId, $requester, $result);

        return $result;
    }

    /**
     * A result that came about elsewhere — on an order, in a subscription
     * run. Only written while the member still has that VAT ID: a member who
     * changed it in between keeps the result for the new one.
     */
    public function record(int $memberId, string $vatId, string $requester, VatIdCheckResult $result): void
    {
        $row = $this->row($memberId);

        if (null === $row || '' === self::normalize($vatId) || self::normalize($row['consho_vatId']) !== self::normalize($vatId)) {
            return;
        }

        $this->write($memberId, self::normalize($vatId), self::normalize($requester), $result);
    }

    /**
     * Whether this is the VAT ID on the member — the checkout only goes
     * through the member's result for the member's own ID.
     */
    public function hasVatId(int $memberId, string $vatId): bool
    {
        $row = $this->row($memberId);

        return null !== $row && '' !== self::normalize($vatId) && self::normalize($row['consho_vatId']) === self::normalize($vatId);
    }

    /**
     * The member's stored result for this VAT ID and requester, whatever its
     * age; null where there is none or it is for another ID or requester.
     */
    public function stored(int $memberId, string $vatId, string $requester): VatIdCheckResult|null
    {
        $row = $this->row($memberId);

        if (null === $row || '' === self::normalize($vatId) || self::normalize($row['consho_vatId']) !== self::normalize($vatId)) {
            return null;
        }

        return self::storedOf($row, self::normalize($requester));
    }

    public static function holds(VatIdCheckResult $result, bool $sameDay, int $now): bool
    {
        if (VatIdStatus::Unavailable === $result->status || $result->checkedAt > $now) {
            return false;
        }

        if ($sameDay) {
            return date('Y-m-d', $result->checkedAt) === date('Y-m-d', $now);
        }

        return $result->checkedAt > strtotime('-1 year', $now);
    }

    /**
     * As the checkout stores it: upper case, without spaces and hyphens.
     */
    public static function normalize(string $vatId): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/u', '', $vatId));
    }

    /**
     * The stored result, if it was asked for the VAT ID the member has now
     * and by this requester.
     *
     * @param array{consho_vatId: string, consho_vatIdStatus: string, consho_vatIdCheckedAt: int|null, consho_vatIdRequestIdentifier: string, consho_vatIdRequester: string, consho_vatIdChecked: string} $row
     */
    private static function storedOf(array $row, string $requester): VatIdCheckResult|null
    {
        $status = VatIdStatus::tryFrom($row['consho_vatIdStatus']);

        if (
            null === $status
            || null === $row['consho_vatIdCheckedAt']
            || self::normalize($row['consho_vatIdRequester']) !== $requester
            || self::normalize($row['consho_vatIdChecked']) !== self::normalize($row['consho_vatId'])
        ) {
            return null;
        }

        return new VatIdCheckResult(
            $status,
            $row['consho_vatIdCheckedAt'],
            null,
            '' === $row['consho_vatIdRequestIdentifier'] ? null : $row['consho_vatIdRequestIdentifier'],
        );
    }

    private function write(int $memberId, string $vatId, string $requester, VatIdCheckResult $result): void
    {
        $this->connection->update('tl_member', [
            'consho_vatIdStatus' => $result->status->value,
            'consho_vatIdCheckedAt' => $result->checkedAt,
            'consho_vatIdRequestIdentifier' => mb_substr((string) $result->requestIdentifier, 0, 64),
            'consho_vatIdRequester' => mb_substr($requester, 0, 32),
            'consho_vatIdChecked' => mb_substr($vatId, 0, 32),
        ], ['id' => $memberId]);
    }

    /**
     * @return array{consho_vatId: string, consho_vatIdStatus: string, consho_vatIdCheckedAt: int|null, consho_vatIdRequestIdentifier: string, consho_vatIdRequester: string, consho_vatIdChecked: string}|null
     */
    private function row(int $memberId): array|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT consho_vatId, consho_vatIdStatus, consho_vatIdCheckedAt, consho_vatIdRequestIdentifier, consho_vatIdRequester, consho_vatIdChecked FROM tl_member WHERE id = ?',
            [$memberId],
        );

        if (false === $row) {
            return null;
        }

        return [
            'consho_vatId' => (string) $row['consho_vatId'],
            'consho_vatIdStatus' => (string) $row['consho_vatIdStatus'],
            'consho_vatIdCheckedAt' => null === $row['consho_vatIdCheckedAt'] ? null : (int) $row['consho_vatIdCheckedAt'],
            'consho_vatIdRequestIdentifier' => (string) $row['consho_vatIdRequestIdentifier'],
            'consho_vatIdRequester' => (string) $row['consho_vatIdRequester'],
            'consho_vatIdChecked' => (string) $row['consho_vatIdChecked'],
        ];
    }
}
