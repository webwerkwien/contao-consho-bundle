<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Address;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Checks a VAT ID against the EU VIES service.
 *
 * REST rather than SOAP, so the package does not impose ext-soap on the people
 * installing it. VIES offers two ways of asking, and they are not variations
 * of one another — measured against the live service:
 *
 * | | simple (GET) | qualified (POST) |
 * |---|---|---|
 * | validity field | `isValid` | **`valid`** |
 * | error field | `userError` | none at all |
 * | consultation number | always empty | **filled when valid** |
 * | needs the requester's own VAT ID | no | yes |
 *
 * The consultation number is the evidence a tax audit asks for, and it is
 * reachable only the qualified way. So a shop that has entered its own VAT ID
 * gets the qualified query; everything else falls back to the simple one, which
 * still answers valid or invalid but leaves no proof behind.
 *
 * ⚠️ Neither route distinguishes an outage from a wrong number on its own.
 * The simple one answers `isValid: false` with `userError: "INVALID"` while a
 * member state is down; the qualified one answers `valid: false` and says
 * nothing further. Both are byte for byte what a genuinely wrong number gets.
 * Hence the check-status consultation before any negative answer is reported.
 */
final class ViesVatIdValidator implements VatIdValidatorInterface
{
    private const SIMPLE_ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

    private const QUALIFIED_ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    private const STATUS_ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-status';

    /**
     * How long a visitor waits for VIES (C15, value decided in C-5).
     *
     * `timeout` is Symfony's idle timeout and also covers connecting;
     * `max_duration` caps the whole request. Two and five seconds: the EU
     * service is known to be slow and has scheduled maintenance windows, and
     * running out of time here is not a failure — the checkout then orders WITH
     * tax and offers "check again" (C15). Waiting longer buys a slightly better
     * chance at the price of a page that looks stuck.
     *
     * ⚠️ These are PER REQUEST, and a negative answer costs a second one (the
     * status endpoint). Worst case is therefore five plus two, not five. The
     * status call gets the shorter budget on purpose: it only refines
     * "invalid" into "unavailable", and failing it counts as available anyway,
     * so nothing is lost by giving up on it early.
     */
    private const TIMEOUT = 2.0;

    private const MAX_DURATION = 5.0;

    private const STATUS_MAX_DURATION = 2.0;

    /**
     * userError values that mean "could not check", as opposed to "invalid".
     */
    private const SERVICE_ERRORS = [
        'MS_UNAVAILABLE',
        'SERVICE_UNAVAILABLE',
        'TIMEOUT',
        'MS_MAX_CONCURRENT_REQ',
        'GLOBAL_MAX_CONCURRENT_REQ',
        'SERVER_BUSY',
    ];

    /**
     * The logger keeps the reason for "unavailable" — afterwards it is the
     * only place that tells a timeout from a member state that is down.
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param string|null $requesterVatId the shop's own VAT ID; supplying it is
     *                                    what makes VIES issue a consultation number
     */
    public function check(string $vatId, string|null $requesterVatId = null): VatIdCheckResult
    {
        $now = time();
        $parsed = $this->parse($vatId);

        if (null === $parsed) {
            /*
             * Malformed input is invalid without asking anyone. Calling the
             * service here would waste a request and count against the rate
             * limit that later turns a real check into "unavailable".
             */
            return new VatIdCheckResult(VatIdStatus::Invalid, $now);
        }

        [$country, $number] = $parsed;
        $requester = null !== $requesterVatId ? $this->parse($requesterVatId) : null;

        try {
            $data = null !== $requester
                ? $this->askQualified($country, $number, $requester)
                : $this->askSimple($country, $number);
        } catch (HttpExceptionInterface $e) {
            // Unreachable is "could not check", never "invalid".
            return $this->unavailable($country, $e->getMessage(), $now);
        }

        // `valid` on the qualified route, `isValid` on the simple one.
        $isValid = ($data['valid'] ?? $data['isValid'] ?? false) === true;

        if ($isValid) {
            $name = $data['name'] ?? null;
            $identifier = $data['requestIdentifier'] ?? null;

            return new VatIdCheckResult(
                VatIdStatus::Valid,
                $now,
                // VIES sends "---" where the member state does not disclose it.
                \is_string($name) && '---' !== $name && '' !== $name ? $name : null,
                \is_string($identifier) && '' !== $identifier ? $identifier : null,
            );
        }

        $userError = \is_string($data['userError'] ?? null) ? $data['userError'] : '';

        if (\in_array($userError, self::SERVICE_ERRORS, true)) {
            return $this->unavailable($country, $userError, $now);
        }

        // A negative answer does not prove the number is wrong — see the note
        // on the class. Ask the status endpoint before saying so.
        if (!$this->memberStateIsAvailable($country)) {
            return $this->unavailable($country, 'member state unavailable', $now);
        }

        return new VatIdCheckResult(VatIdStatus::Invalid, $now);
    }

    /**
     * The VAT ID stays out of the log; its country is enough to tell an
     * outage of one member state from a general one.
     */
    private function unavailable(string $country, string $reason, int $now): VatIdCheckResult
    {
        $this->logger->warning(\sprintf('VIES could not check a VAT ID from %s: %s', $country, $reason));

        return new VatIdCheckResult(VatIdStatus::Unavailable, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function askSimple(string $country, string $number): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->client
            ->request('GET', sprintf(self::SIMPLE_ENDPOINT, $country, $number), [
                'timeout' => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
            ])
            ->toArray(false)
        ;

        return $data;
    }

    /**
     * @param array{0: string, 1: string} $requester
     *
     * @return array<string, mixed>
     */
    private function askQualified(string $country, string $number, array $requester): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->client
            ->request('POST', self::QUALIFIED_ENDPOINT, [
                'json' => [
                    'countryCode' => $country,
                    'vatNumber' => $number,
                    'requesterMemberStateCode' => $requester[0],
                    'requesterNumber' => $requester[1],
                ],
                'timeout' => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
            ])
            ->toArray(false)
        ;

        return $data;
    }

    /**
     * Unreachable status endpoint counts as available: we already have an
     * answer from the VAT query, and inventing "unavailable" on top of a
     * second failure would only make the message vaguer.
     */
    private function memberStateIsAvailable(string $country): bool
    {
        try {
            /** @var array<string, mixed> $data */
            $data = $this->client
                ->request('GET', self::STATUS_ENDPOINT, [
                    'timeout' => self::TIMEOUT,
                    'max_duration' => self::STATUS_MAX_DURATION,
                ])
                ->toArray(false)
            ;
        } catch (HttpExceptionInterface) {
            return true;
        }

        foreach ($data['countries'] ?? [] as $entry) {
            if (!\is_array($entry) || ($entry['countryCode'] ?? null) !== $country) {
                continue;
            }

            return 'Available' === ($entry['availability'] ?? null);
        }

        return true;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parse(string $vatId): array|null
    {
        // Letterheads write it as "ATU 123 456 78" or with dashes.
        $clean = strtoupper((string) preg_replace('/[\s.\-]/', '', $vatId));

        if (!preg_match('/^([A-Z]{2})([0-9A-Z]{2,})$/', $clean, $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }
}
