<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Address;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Address\ViesVatIdValidator;

class ViesVatIdValidatorTest extends TestCase
{
    public function testAValidIdIsReportedAsValid(): void
    {
        $validator = $this->validatorReturning([
            'isValid' => true,
            'name' => 'Musterfirma GmbH',
            'requestIdentifier' => 'WAPIAAAAX123',
        ]);

        $result = $validator->check('ATU12345678');

        self::assertSame(VatIdStatus::Valid, $result->status);
        self::assertSame('Musterfirma GmbH', $result->name);
        self::assertSame('WAPIAAAAX123', $result->requestIdentifier);
        self::assertTrue($result->status->grantsReverseCharge());
    }

    public function testAnInvalidIdIsReportedAsInvalid(): void
    {
        $validator = $this->validatorReturning(['isValid' => false, 'userError' => 'INVALID']);

        $result = $validator->check('ATU00000000');

        self::assertSame(VatIdStatus::Invalid, $result->status);
        self::assertFalse($result->status->grantsReverseCharge());
    }

    #[DataProvider('serviceErrors')]
    public function testAServiceErrorIsNotAnInvalidId(string $userError): void
    {
        // VIES answers an outage with isValid: false, exactly as for an invalid
        // number. Reading isValid alone would declare every Austrian VAT ID
        // invalid whenever the Austrian member state service is down.
        $validator = $this->validatorReturning(['isValid' => false, 'userError' => $userError]);

        $result = $validator->check('ATU12345678');

        self::assertSame(VatIdStatus::Unavailable, $result->status);
        self::assertFalse($result->status->grantsReverseCharge());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function serviceErrors(): iterable
    {
        yield ['MS_UNAVAILABLE'];
        yield ['SERVICE_UNAVAILABLE'];
        yield ['TIMEOUT'];
        yield ['MS_MAX_CONCURRENT_REQ'];
        yield ['GLOBAL_MAX_CONCURRENT_REQ'];
    }

    public function testAQualifiedQueryReadsTheOtherValidityFieldAndYieldsEvidence(): void
    {
        /*
         * The qualified route answers `valid`, not `isValid`, and is the only
         * one that fills requestIdentifier — the consultation number a tax
         * audit asks for. Measured against the live service on 2026-09-09.
         */
        $validator = new ViesVatIdValidator(new MockHttpClient([
            $this->jsonResponse([
                'valid' => true,
                'name' => 'Musterfirma GmbH',
                'requestIdentifier' => 'cb54d61d-f2a4-4063-ab64-3dcac0319b7b',
            ]),
        ]));

        $result = $validator->check('DE123456789', 'ATU12345678');

        self::assertSame(VatIdStatus::Valid, $result->status);
        self::assertSame('cb54d61d-f2a4-4063-ab64-3dcac0319b7b', $result->requestIdentifier);
    }

    public function testAQualifiedNegativeIsStillCheckedAgainstTheStatusEndpoint(): void
    {
        // The qualified route sends no userError at all, so check-status is
        // the only thing standing between an outage and a wrong "invalid".
        $validator = new ViesVatIdValidator(new MockHttpClient([
            $this->jsonResponse(['valid' => false, 'requestIdentifier' => '']),
            $this->jsonResponse(['countries' => [['countryCode' => 'DK', 'availability' => 'Unavailable']]]),
        ]));

        self::assertSame(VatIdStatus::Unavailable, $validator->check('DK12345678', 'ATU12345678')->status);
    }

    public function testAnUnusableRequesterFallsBackToTheSimpleQuery(): void
    {
        // A shop that has not entered its own VAT ID still gets an answer,
        // just no evidence to keep.
        $validator = new ViesVatIdValidator(new MockHttpClient([
            $this->jsonResponse(['isValid' => true, 'name' => 'Musterfirma GmbH', 'requestIdentifier' => '']),
        ]));

        $result = $validator->check('ATU12345678', 'not a vat id');

        self::assertSame(VatIdStatus::Valid, $result->status);
        self::assertNull($result->requestIdentifier);
    }

    public function testAnInvalidAnswerFromAMemberStateThatIsDownIsUnavailable(): void
    {
        /*
         * Measured against the live service on 2026-09-09: VIES listed DK as
         * "Unavailable" on its status endpoint while answering the VAT query
         * for DK with userError INVALID — the same body it sends for a
         * genuinely wrong number. Believing it tells a Danish customer with a
         * perfectly good VAT ID that theirs is invalid.
         */
        $validator = new ViesVatIdValidator(new MockHttpClient([
            $this->jsonResponse(['isValid' => false, 'userError' => 'INVALID']),
            $this->jsonResponse(['countries' => [
                ['countryCode' => 'AT', 'availability' => 'Available'],
                ['countryCode' => 'DK', 'availability' => 'Unavailable'],
            ]]),
        ]));

        $result = $validator->check('DK12345678');

        self::assertSame(VatIdStatus::Unavailable, $result->status);
        self::assertFalse($result->status->grantsReverseCharge());
    }

    public function testAnInvalidAnswerFromAWorkingMemberStateStaysInvalid(): void
    {
        $validator = new ViesVatIdValidator(new MockHttpClient([
            $this->jsonResponse(['isValid' => false, 'userError' => 'INVALID']),
            $this->jsonResponse(['countries' => [
                ['countryCode' => 'AT', 'availability' => 'Available'],
            ]]),
        ]));

        self::assertSame(VatIdStatus::Invalid, $validator->check('ATU00000000')->status);
    }

    public function testATransportFailureYieldsUnavailableInsteadOfThrowing(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new class extends \RuntimeException implements TransportExceptionInterface {};
        });

        $validator = new ViesVatIdValidator($client);

        self::assertSame(VatIdStatus::Unavailable, $validator->check('ATU12345678')->status);
    }

    public function testAnUnparseableIdIsInvalidWithoutCallingTheService(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('The service must not be called for a malformed ID.');
        });

        $validator = new ViesVatIdValidator($client);

        self::assertSame(VatIdStatus::Invalid, $validator->check('')->status);
        self::assertSame(VatIdStatus::Invalid, $validator->check('X')->status);
        self::assertSame(VatIdStatus::Invalid, $validator->check('12345678')->status);
    }

    public function testAcceptsAnIdWrittenWithSpacesOrDashes(): void
    {
        // "ATU 123 456 78" is how it appears on letterheads.
        $validator = $this->validatorReturning(['isValid' => true, 'requestIdentifier' => 'WAPI1']);

        self::assertSame(VatIdStatus::Valid, $validator->check('ATU 123 456 78')->status);
    }

    public function testTreatsTheViesPlaceholderNameAsNoName(): void
    {
        // VIES returns "---" when the member state does not disclose the name.
        $validator = $this->validatorReturning(['isValid' => true, 'name' => '---']);

        self::assertNull($validator->check('ATU12345678')->name);
    }

    /**
     * The requirement: 2 s to connect, 5 s in total. Written next to the
     * expectation on purpose — a bare `5.0` in an assertion says what the code
     * does, not what anybody wanted.
     *
     * Without an option Symfony waits `default_socket_timeout` (60 s by
     * default) and has no overall cap at all, so leaving these off does not
     * fail anywhere — the checkout just hangs. That is why this is asserted
     * and not left to the constant being visible in the file.
     */
    public function testEveryViesRequestCarriesTheAgreedTimeout(): void
    {
        $seen = [];

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$options['timeout'] ?? null, $options['max_duration'] ?? null];

            return $this->jsonResponse(
                str_contains($url, 'check-status')
                    ? ['countries' => [['countryCode' => 'AT', 'availability' => 'Available']]]
                    : ['isValid' => false, 'userError' => 'INVALID'],
            );
        });

        // An invalid answer, so the status endpoint is asked as well: all three
        // routes through the class get looked at, not just the happy one.
        (new ViesVatIdValidator($client))->check('ATU00000000');
        (new ViesVatIdValidator($client))->check('ATU00000000', 'ATU12345678');

        self::assertSame(
            [[2.0, 5.0], [2.0, 2.0], [2.0, 5.0], [2.0, 2.0]],
            $seen,
            'simple/qualified get five seconds, the status call two — see the note on the constants.',
        );
    }

    /**
     * A negative answer now costs a second request, to the status endpoint —
     * so every "invalid" fixture needs one queued behind it.
     *
     * @param array<string, mixed> $payload
     */
    private function validatorReturning(array $payload): ViesVatIdValidator
    {
        return new ViesVatIdValidator(new MockHttpClient([
            $this->jsonResponse($payload),
            $this->jsonResponse(['countries' => [['countryCode' => 'AT', 'availability' => 'Available']]]),
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload): MockResponse
    {
        return new MockResponse(
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }
}
