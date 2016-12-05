<?php

namespace Bolt\Starter\Provider;

use Bolt\Starter\EventListener\StarterListener;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\EventDispatcher\Debug\TraceableEventDispatcherInterface;

/**
 * Starter-kit service provider.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class StarterProvider implements ServiceProviderInterface
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
        $dispatcher->addSubscriber(new StarterListener($app));
    }
}
