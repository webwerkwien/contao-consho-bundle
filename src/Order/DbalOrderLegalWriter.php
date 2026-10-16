<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Checkout\LegalText;

final class DbalOrderLegalWriter implements OrderLegalWriterInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function freeze(int $orderId, LegalText $text, int $capturedAt): void
    {
        $this->connection->insert('tl_consho_order_legal', [
            'pid' => $orderId,
            'tstamp' => $capturedAt,
            'kind' => $text->kind->value,
            'headline' => $text->headline,
            'text' => $text->text,

            // The checksum comes from LegalText, which took it over the
            // RESOLVED headline and text. Recomputing it here would be a
            // second implementation of the same rule, and the one the customer
            // saw is the one that counts.
            'checksum' => $text->checksum,
            'sourceElement' => $text->elementId,
            'capturedAt' => $capturedAt,
        ]);
    }
}
