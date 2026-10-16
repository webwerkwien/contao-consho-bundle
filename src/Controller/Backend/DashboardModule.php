<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\BackendModule;
use Contao\Input;
use Contao\System;

/**
 * The `callback` of the menu item "Dashboard" (DB1): no table of its own, like
 * "Kunden".
 *
 * With `view=taxReport` it shows the page "Steuermeldung" (DB6), which until
 * then had a menu item of its own; with `view=setup` the page "Einrichtung"
 * (block I, I5).
 */
class DashboardModule extends BackendModule
{
    public function generate(): string
    {
        $controller = System::getContainer()->get(match (Input::get('view')) {
            TaxReportBackendController::ROUTE['view'] => TaxReportBackendController::class,
            SetupBackendController::ROUTE['view'] => SetupBackendController::class,
            default => DashboardBackendController::class,
        });

        \assert($controller instanceof TaxReportBackendController || $controller instanceof SetupBackendController || $controller instanceof DashboardBackendController);

        return $controller();
    }

    protected function compile(): void
    {
    }
}
