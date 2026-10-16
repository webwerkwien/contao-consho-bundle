<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;

/**
 * "Leistung erbracht" only where ServicePerformed would take it (DL3): a
 * released service line without subscription, not yet reported. Everywhere
 * else the button would only produce an error message.
 *
 * With a question first (spec 6.4): it cannot be taken back.
 */
class ServicePerformedButtonListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[AsCallback(table: 'tl_consho_order_item', target: 'list.operations.performed.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        $row = $operation->getRecord() ?? [];

        $offered = ProductKind::Service === ProductKind::stored((string) ($row['kind'] ?? ''))
            && 'product' === (string) ($row['lineType'] ?? '')
            && 0 === (int) ($row['subscriptionRule'] ?? 0)
            && null === ($row['performedAt'] ?? null)
            && false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_entitlement WHERE orderItem = ? AND revoked = 0 LIMIT 1', [(int) ($row['id'] ?? 0)]);

        if (!$offered) {
            $operation->hide();

            return;
        }

        $question = (string) ($GLOBALS['TL_LANG']['tl_consho_order_item']['performedConfirm'] ?? 'OK?');
        $operation['attributes']->set('onclick', 'if(!confirm('.json_encode($question, JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE).'))return false');
    }
}
