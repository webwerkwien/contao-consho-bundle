<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;
use Webwerkwien\ContaoConshoBundle\ContaoConshoBundle;

class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(ContaoConshoBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }

    /**
     * A managed edition loads NOTHING from a bundle's config/routes.yaml — the
     * attribute routes of src/Controller reach the router only through here.
     *
     * Both calls name the same folder because they do different things:
     * resolve() picks the loader that can handle the type "attribute", and
     * load() then applies it to the path. The directory is walked recursively,
     * so the content element controllers below it are visited too; they carry
     * no Route attribute and contribute nothing.
     */
    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): RouteCollection|null
    {
        $loader = $resolver->resolve(__DIR__.'/../Controller', 'attribute');

        return false === $loader ? null : $loader->load(__DIR__.'/../Controller', 'attribute');
    }
}
