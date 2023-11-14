<?php

declare(strict_types=1);

namespace araise\CrudBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @see http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class araiseCrudExtension extends Extension implements PrependExtensionInterface
{
    /**
     * @param string[] $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Breadcrumbs
        if (isset($config['breadcrumbs'])
            && isset($config['breadcrumbs']['home_text'])) {
            $container->setParameter('araise_crud.config.breadcrumbs.home.text', $config['breadcrumbs']['home_text']);
        } else {
            $container->setParameter('araise_crud.config.breadcrumbs.home.text', 'Dashboard');
        }

        if (isset($config['breadcrumbs'])
            && isset($config['breadcrumbs']['home_route'])) {
            $container->setParameter('araise_crud.config.breadcrumbs.home.route', $config['breadcrumbs']['home_route']);
        } else {
            $container->setParameter('araise_crud.config.breadcrumbs.home.route', 'araise_crud_dashboard');
        }

        // templates
        $templates = [
            'show' => '_boxes/show.html.twig',
            'create' => '_boxes/create.html.twig',
            'edit' => '_boxes/edit.html.twig',
        ];
        if (isset($config['templates'])) {
            $templates = $config['templates'];
        }
        $container->setParameter('araise_crud.config.templates', $templates);
        $container->setParameter('araise_crud.config.template_directory', $config['templateDirectory']);
        $container->setParameter('araise_crud.config.layout', $config['layout']);
        $container->setParameter('araise.enable_turbo', $config['enable_turbo']);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('araise_table') && !$container->hasExtension('araise_core')) {
            return;
        }
        $configs = $container->getExtensionConfig($this->getAlias());

        foreach (array_reverse($configs) as $config) {
            if (isset($config['enable_turbo'])) {
                $container->prependExtensionConfig('araise_table', [
                    'enable_turbo' => $config['enable_turbo'],
                ]);

                $container->prependExtensionConfig('araise_core', [
                    'enable_turbo' => $config['enable_turbo'],
                ]);
            }
        }
    }
}
