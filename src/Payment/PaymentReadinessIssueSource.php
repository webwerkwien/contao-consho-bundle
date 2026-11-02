<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Published payment methods of a module that will not work as expected
 * (PaymentReadinessInterface). One finding per method and reason: each
 * method has its own settings, so the same reason may hold for one and not
 * for the next.
 */
final class PaymentReadinessIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentHandlers $handlers,
    ) {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, title, type, jsonData FROM tl_consho_payment_method WHERE published = 1 ORDER BY id');
        $issues = [];

        foreach ($rows as $row) {
            $type = (string) $row['type'];

            // A type whose module is gone, or the test provider switched off:
            // the checkout leaves such a method out already.
            if (!$this->handlers->has($type)) {
                continue;
            }

            $handler = $this->handlers->get($type);

            if (!$handler instanceof PaymentReadinessInterface) {
                continue;
            }

            foreach (array_unique($handler->methodProblems(MethodSettings::decode($row['jsonData'] ?? null))) as $code) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::PaymentMethodNotReady, [
                    'method' => (string) $row['title'],
                    'reason' => $this->reason($type, $code),
                ]);
            }
        }

        return $issues;
    }

    /**
     * The module's sentence, or the bare code: a missing translation has to
     * look wrong rather than empty, as for delivery types.
     */
    private function reason(string $type, string $code): string
    {
        $text = $GLOBALS['TL_LANG']['CONSHO']['paymentReason'][$type][$code] ?? null;

        return \is_string($text) && '' !== $text ? $text : $type.': '.$code;
    }
}
