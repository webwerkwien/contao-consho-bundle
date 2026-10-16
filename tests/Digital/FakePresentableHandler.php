<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Digital;

use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveredItem;
use Webwerkwien\ContaoConshoBundle\Digital\Entitlement;
use Webwerkwien\ContaoConshoBundle\Digital\PresentableDeliveryHandlerInterface;

/**
 * A module as the licence package will be one (LZ-1): per entitlement a key
 * to copy with a download and a "release" button, and a second item without
 * either. Records what the core asked of it.
 */
final class FakePresentableHandler implements PresentableDeliveryHandlerInterface
{
    /** @var list<string> */
    public array $calls = [];

    public static function getType(): string
    {
        return 'testlicence';
    }

    public function release(Entitlement $entitlement): bool
    {
        return true;
    }

    public function deliveredItems(Entitlement $entitlement): array
    {
        return [
            new DeliveredItem('lic-'.$entitlement->id, 'Lizenz '.$entitlement->id, 'KEY-'.$entitlement->id, true, 'release', 'Installation freigeben'),
            new DeliveredItem('note', 'Hinweis', 'nur Text'),
        ];
    }

    public function download(Entitlement $entitlement, string $item): Response
    {
        $this->calls[] = 'download '.$item;

        // Public on purpose: the core must make it private.
        $response = new Response('ZIP-'.$item);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    public function act(Entitlement $entitlement, string $item, string $action): string
    {
        $this->calls[] = 'act '.$item.' '.$action;

        return 'testReleased';
    }
}
