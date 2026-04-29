<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;
use VenneMedia\VenneSearchContaoBundle\VenneSearchContaoBundle;

/**
 * Contao-Manager-Plugin: registriert das Bundle im richtigen Load-Order
 * und legt zusätzlich die Frontend-API-Route /vsearch/api fest.
 */
class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(VenneSearchContaoBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        $file = __DIR__.'/../Resources/config/routes.yaml';
        $loader = $resolver->resolve($file);
        if ($loader === false) {
            return null;
        }

        $collection = $loader->load($file);

        return $collection instanceof RouteCollection ? $collection : null;
    }
}
