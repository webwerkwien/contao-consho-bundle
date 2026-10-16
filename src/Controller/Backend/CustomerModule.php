<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\BackendModule;
use Contao\System;

/**
 * The bridge Contao needs for a menu item without a table (D-4, K6).
 *
 * A back end module is either tables or a `callback` — a class Contao builds
 * with `new`, outside the container. This one does nothing but hand over to
 * the service, where the work and its dependencies live.
 */
class CustomerModule extends BackendModule
{
    public function generate(): string
    {
        /** @var CustomerBackendController $controller */
        $controller = System::getContainer()->get(CustomerBackendController::class);

        return $controller();
    }

    protected function compile(): void
    {
    }
}
