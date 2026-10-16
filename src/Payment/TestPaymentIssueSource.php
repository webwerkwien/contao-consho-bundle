<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Says so whenever the test provider is switched on (spec 11.3), whether or
 * not a method of that type exists yet: the variable is what makes one
 * possible, and it is what has to go before a shop is real.
 */
final class TestPaymentIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly PaymentHandlers $handlers)
    {
    }

    public function issues(): array
    {
        return $this->handlers->testPaymentEnabled() ? [new ConfigurationIssue(ConfigurationFinding::TestPaymentActive)] : [];
    }
}
