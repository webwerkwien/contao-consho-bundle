<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class ContaoConshoBundle extends AbstractBundle
{
    public function loadExtension(
        array $config,
        ContainerConfigurator $containerConfigurator,
        ContainerBuilder $containerBuilder,
    ): void {
        $containerConfigurator->import('../config/services.yaml');
    }

    /*
     * The back end stylesheet — it gives the three Consho menu groups their
     * icons.
     *
     * `contao.backend.custom_css` is the supported way in: BackendTemplate
     * reads the parameter, pushes every entry through the asset packages and
     * merges the result into $GLOBALS['TL_CSS']. Prepending is additive, so a
     * site owner's own entries survive untouched.
     *
     * The alternative would be a kernel.request listener writing to TL_CSS
     * directly. It would work, but it would have to ask the scope matcher
     * whether this is a back end request on every single request — for a
     * parameter that nothing but the back end template ever reads.
     */
    public function prependExtension(
        ContainerConfigurator $containerConfigurator,
        ContainerBuilder $containerBuilder,
    ): void {
        $containerBuilder->prependExtensionConfig('contao', [
            'backend' => [
                // Published to public/bundles/contaoconsho/ by assets:install.
                // Symfony derives the folder from the bundle class name, so it
                // is not free to choose.
                'custom_css' => ['bundles/contaoconsho/backend.css'],

                // Tabs over the translation sections. Loaded on every back
                // end page like the stylesheet; it leaves at once where no
                // translation section exists. See the file for why this is
                // not a Stimulus controller.
                'custom_js' => ['bundles/contaoconsho/backend-tabs.js'],
            ],
        ]);
    }
}
