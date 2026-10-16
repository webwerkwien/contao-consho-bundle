<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;

/**
 * The confirmation page and the result page of a cancellation (Abo-7, P58,
 * P60, P61; § 312k Abs. 2 and 3 BGB), shared by "Verträge hier kündigen" and
 * "Meine Abos" — the same page, only the way the contract is identified
 * differs.
 *
 * The confirmation page asks what § 312k Abs. 2 Satz 2 lists: the kind of
 * cancellation and, for an extraordinary one, the reason; the name; the
 * contract; the date (empty = the earliest possible, Abs. 5). The e-mail way
 * of the confirmation is the subscription's address (P58).
 *
 * ── The result page ──────────────────────────────────────────────────────
 *
 * Abs. 3: the consumer must be able to save the declaration WITH the date and
 * time it was given. So the recorded cancellation is shown in full after the
 * redirect, not only "received". The address of that page is signed (the
 * UriSigner): whoever has it sees the declaration, nobody gets there by
 * counting numbers — the level of the order page's link. No session: the page
 * survives a reload and a bookmark, and a guest gets no cookie.
 */
class CancellationForm
{
    public const FIELDS = ['kind', 'reason', 'name', 'date'];

    public const RESULT_PARAM = 'consho_cancellation';

    /**
     * The signature's parameter — not Symfony's `_hash`, so that an allow
     * list of "consho_.*" keeps it (NoticeParamIssueSource).
     */
    public const HASH_PARAM = 'consho_hash';

    /** The signed expiry of the result page — also inside "consho_.*". */
    public const EXPIRES_PARAM = 'consho_expires';

    /**
     * The result page stays open 90 days (Michael, 2026-09-30: „L4 90 Tage"):
     * long enough to save it; the confirmation mail is the lasting record.
     */
    public const RESULT_LIFETIME = 90 * 86_400;

    public function __construct(
        private readonly Connection $connection,
        private readonly CancellationRecorder $recorder,
        private readonly SubscriptionCalendar $calendar,
        #[Autowire(service: 'consho.cancellation_signer')]
        private readonly UriSigner $signer,
    ) {
    }

    /**
     * What was typed, trimmed; the fields of the declaration only.
     *
     * @return array{kind: string, reason: string, name: string, date: string}
     */
    public static function read(Request $request): array
    {
        $input = ['kind' => 'ordinary', 'reason' => '', 'name' => '', 'date' => ''];

        foreach (self::FIELDS as $field) {
            $value = trim($request->request->getString('consho_cancellation_'.$field));

            if ('' !== $value) {
                $input[$field] = $value;
            }
        }

        return $input;
    }

    /**
     * Records it, or says what is wrong: `missing`, `reasonMissing`,
     * `dateInvalid`, `ended`, `alreadyEnding` — the last two with the result,
     * for the date the subscription ends.
     *
     * @param array{kind: string, reason: string, name: string, date: string} $input
     *
     * @return array{0: string|null, 1: CancellationResult|null} error key, result
     */
    public function declare(array $input, int $subscriptionId, CancellationSource $source, int $now): array
    {
        $kind = CancellationKind::tryFrom($input['kind']);

        if (null === $kind || '' === $input['name']) {
            return ['missing', null];
        }

        if (CancellationKind::Extraordinary === $kind && '' === $input['reason']) {
            return ['reasonMissing', null];
        }

        $requestedAt = null;

        if ('' !== $input['date']) {
            $requestedAt = $this->calendar->startOfDay($input['date']);

            if (null === $requestedAt) {
                return ['dateInvalid', null];
            }
        }

        $result = $this->recorder->record(new CancellationDeclaration($subscriptionId, $source, $kind, $input['name'], $requestedAt, $input['reason']), $now);

        return match ($result->outcome) {
            CancellationOutcome::Recorded => [null, $result],
            CancellationOutcome::AlreadyEnding => ['alreadyEnding', $result],
            CancellationOutcome::Ended => ['ended', $result],
            CancellationOutcome::UnknownSubscription => ['notFound', null],
        };
    }

    /**
     * The signed address of the result page, on the page the form was on.
     */
    public function resultUrl(Request $request, int $cancellationId, int $now): string
    {
        return $this->signer->sign(
            $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().'?'.http_build_query([self::RESULT_PARAM => $cancellationId]),
            $now + self::RESULT_LIFETIME,
        );
    }

    /**
     * A result page whose signature holds but whose 90 days are over — the
     * page says so instead of starting over.
     */
    public function expired(Request $request): bool
    {
        if ($request->query->getInt(self::RESULT_PARAM) < 1) {
            return false;
        }

        try {
            $this->signer->verify($request);
        } catch (ExpiredSignedUriException) {
            return true;
        } catch (SignedUriException) {
            return false;
        }

        return false;
    }

    /**
     * The declaration for the result page, formatted in the page's language —
     * null where the address is not signed or the cancellation is gone.
     *
     * @return array<string, mixed>|null
     */
    public function shown(Request $request, string $language): array|null
    {
        $id = $request->query->getInt(self::RESULT_PARAM);

        if ($id < 1 || !$this->signer->checkRequest($request)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT c.*, s.subscriptionNumber, i.productName
               FROM tl_consho_cancellation c
               JOIN tl_consho_subscription s ON s.id = c.pid
               LEFT JOIN tl_consho_order_item i ON i.id = s.orderItem
              WHERE c.id = ?',
            [$id],
        );

        if (false === $row) {
            return null;
        }

        return [
            'number' => (string) $row['number'],
            'subscription' => (string) $row['subscriptionNumber'],
            'product' => (string) ($row['productName'] ?? ''),
            'receivedAt' => self::moment((int) $row['receivedAt'], $language),
            'kind' => (string) $row['kind'],
            'reason' => (string) ($row['reason'] ?? ''),
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'requestedAt' => null === $row['requestedAt'] ? '' : self::day((int) $row['requestedAt'], $language),
            'effectiveAt' => null === $row['effectiveAt'] ? '' : self::moment((int) $row['effectiveAt'], $language),
            'earliestApplied' => (bool) $row['earliestApplied'],
            'earliestAt' => null === $row['earliestAt'] ? '' : self::moment((int) $row['earliestAt'], $language),
            'laterThanEarliest' => null !== $row['effectiveAt'] && null !== $row['earliestAt'] && (int) $row['effectiveAt'] > (int) $row['earliestAt'],
            'pending' => CancellationDecision::isPending($row),
            'confirmed' => null !== $row['confirmationSentAt'],
        ];
    }

    /**
     * Date and time with the zone — "2. Oktober 2026 um 14:03:05 MESZ".
     */
    public static function moment(int $timestamp, string $language): string
    {
        return (string) self::formatter($language, \IntlDateFormatter::LONG)->format($timestamp);
    }

    /**
     * The day only: the form asks for a day.
     */
    public static function day(int $timestamp, string $language): string
    {
        return (string) self::formatter($language, \IntlDateFormatter::NONE)->format($timestamp);
    }

    private static function formatter(string $language, int $time): \IntlDateFormatter
    {
        $locale = '' !== $language ? str_replace('-', '_', $language) : 'de';

        return new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, $time, date_default_timezone_get());
    }
}
