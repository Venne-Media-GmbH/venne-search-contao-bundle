<?php

declare(strict_types=1);

namespace VenneMedia\VenneSearchContaoBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Symfony-DI-Extension. Naming-Konvention: <BundleName>Extension — also
 * `VenneSearchContaoExtension` zur Bundle-Klasse `VenneSearchContaoBundle`.
 * Symfony lädt diese Klasse automatisch beim Bundle-Boot.
 *
 * Über `prepend()` registrieren wir den Twig-Namespace `@VenneSearchContao`,
 * der auf src/Resources/views/ zeigt — so können wir im Controller via
 * `@VenneSearchContao/backend/index_browser.html.twig` rendern.
 */
final class VenneSearchContaoExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config'),
        );
        $loader->load('services.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('twig', [
            'paths' => [
                __DIR__.'/../Resources/views' => 'VenneSearchContao',
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'venne_search_contao';
    }
}
