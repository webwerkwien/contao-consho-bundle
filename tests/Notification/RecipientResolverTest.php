<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Notification\RecipientResolver;

class RecipientResolverTest extends TestCase
{
    private RecipientResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new RecipientResolver();
    }

    public function testTheCustomerGetsTheOrderConfirmation(): void
    {
        $recipients = $this->resolver->forCustomer(
            ['email' => 'kunde@example.org', 'firstname' => 'Michael', 'lastname' => 'Morgenbesser', 'language' => 'de'],
        );

        self::assertCount(1, $recipients);
        self::assertSame('kunde@example.org', $recipients[0]->email);
        self::assertSame('Michael Morgenbesser', $recipients[0]->name);
        self::assertSame('de', $recipients[0]->language);
    }

    /**
     * The order carries its own language since task 1, and this is what it is
     * for: a mail resent a year later goes out in the language the customer
     * ordered in, not that of the back end user pressing the button.
     */
    public function testTheOrderLanguageDecidesNotTheBackendLanguage(): void
    {
        $recipients = $this->resolver->forCustomer(
            ['email' => 'kunde@example.org', 'firstname' => '', 'lastname' => '', 'language' => 'en'],
        );

        self::assertSame('en', $recipients[0]->language);
    }

    public function testAnOrderWithoutAnEmailYieldsNoRecipient(): void
    {
        $recipients = $this->resolver->forCustomer(
            ['email' => '', 'firstname' => '', 'lastname' => '', 'language' => 'de'],
        );

        self::assertSame([], $recipients);
    }

    /**
     * Whitespace is not an address. An order whose email is a stray space must
     * not produce a recipient that the mailer then refuses.
     */
    public function testAnEmailOfNothingButSpacesYieldsNoRecipient(): void
    {
        $recipients = $this->resolver->forCustomer(
            ['email' => '   ', 'firstname' => 'A', 'lastname' => 'B', 'language' => 'de'],
        );

        self::assertSame([], $recipients);
    }

    public function testTheOperatorGetsTheOperatorAddress(): void
    {
        $recipients = $this->resolver->forOperator(
            ['operatorAddress' => 'shop@web.example.net', 'operatorName' => 'web.example.net'],
            'de',
        );

        self::assertCount(1, $recipients);
        self::assertSame('shop@web.example.net', $recipients[0]->email);
    }

    /**
     * A shop in Vienna does not want its own copy in Portuguese because a
     * customer from Lisbon ordered.
     */
    public function testTheOperatorAlwaysGetsTheShopLanguageNotTheCustomers(): void
    {
        $recipients = $this->resolver->forOperator(
            ['operatorAddress' => 'shop@web.example.net', 'operatorName' => ''],
            'de',
        );

        self::assertSame('de', $recipients[0]->language);
    }

    public function testAShopWithoutAnOperatorAddressYieldsNoOperatorRecipient(): void
    {
        self::assertSame([], $this->resolver->forOperator(['operatorAddress' => '', 'operatorName' => ''], 'de'));
    }

    /**
     * A missing language must not produce an empty locale that later resolves
     * to whatever the server happens to be set to.
     */
    public function testAMissingLanguageFallsBackRatherThanStayingEmpty(): void
    {
        $recipients = $this->resolver->forCustomer(
            ['email' => 'kunde@example.org', 'firstname' => '', 'lastname' => '', 'language' => ''],
        );

        self::assertNotSame('', $recipients[0]->language);
    }
}
