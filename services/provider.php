<?php

/**
 * @package     AI Alias
 * @subpackage  plg_system_aialias
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Gss\Plugin\System\AiAlias\Extension\AiAlias;
use Joomla\CMS\Application\ApplicationInterface;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $config = (array) PluginHelper::getPlugin('system', 'aialias');

                // Joomla 5.4+ plugins take only the config; older versions expect the dispatcher first
                $first  = (new \ReflectionMethod(CMSPlugin::class, '__construct'))->getParameters()[0]->getName();
                $plugin = $first === 'config'
                    ? new AiAlias($config)
                    : new AiAlias($container->get(DispatcherInterface::class), $config);

                // Factory::getApplication() is deprecated for Joomla 6; prefer the container when it knows the app
                $plugin->setApplication(
                    $container->has(ApplicationInterface::class)
                        ? $container->get(ApplicationInterface::class)
                        : Factory::getApplication()
                );

                return $plugin;
            }
        );
    }
};
