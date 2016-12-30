<?php

namespace Bolt\ConfigurationNotices\Provider;

use Bolt\ConfigurationNotices\EventListener\ConfigurationNoticesListener;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\EventDispatcher\Debug\TraceableEventDispatcherInterface;

/**
 * Configuration Notices service provider.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class ConfigurationNoticesProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Application $app)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Application $app)
    {
        /** @var TraceableEventDispatcherInterface $dispatcher */
        $dispatcher = $app['dispatcher'];
        $dispatcher->addSubscriber(new ConfigurationNoticesListener($app));
    }
}
