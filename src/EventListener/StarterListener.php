<?php

namespace Bolt\Starter\EventListener;

use Bolt\Translation\Translator as Trans;
use Silex\Application;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event listeners to help people out in a friendly way.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 *         Bob den Otter <bob@twokings.nl>
 */
class StarterListener implements EventSubscriberInterface
{
    /** @var \Silex\Application $app */
    protected $app;

    /**
     * Constructor function.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Kernel request listener callback.
     *
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $request = $event->getRequest();

        $this->mailConfigCheck($request);
        $this->liveCheck($request);
        $this->gdCheck();
    }

    /**
     * No mail transport has been set. We should gently nudge the user to set
     * the mail configuration.
     *
     * @see https://github.com/bolt/bolt/issues/2908
     *
     * @param Request $request
     */
    protected function mailConfigCheck(Request $request)
    {
        if (!$request->hasPreviousSession()) {
            return;
        }

        if (!$this->app['config']->get('general/mailoptions') && $this->app['users']->getCurrentuser() && $this->app['users']->isAllowed('files:config')) {
            $notice = "The <strong>mail configuration parameters</strong> have not been set up. This may interfere with password resets, and extension functionality. Please set up the <tt>mailoptions</tt> in config.yml.";
            $this->app['logger.flash']->configuration(Trans::__($notice));
        }
    }

    /**
     * Check whether or not the GD-library can be used in PHP. Needed for making thumbnails.
     */
    protected function gdCheck()
    {
        if (!function_exists('imagecreatetruecolor')) {
            $notice = "The current version of PHP doesn't have the <strong>GD library enabled</strong>. Without this, Bolt will not be able to generate thumbnails. Please enable <tt>php-gd</tt>, or ask your system-administrator to do so.";
            $this->app['logger.flash']->configuration(Trans::__($notice));
        }
    }


    /**
     * Check whether the site is live or not.
     */
    protected function liveCheck(Request $request)
    {
        if (!$this->app['debug']) {
            return;
        }

        $host = $request->getHttpHost();
        $domainpartials = $this->app['config']->get('general/debug_local_domains', []);
        foreach ($domainpartials as $partial) {
            if (strpos($host, $partial) !== false) {
                return;
            }
        }
        $notice = "It seems like this website is running on a <strong>non-development environment</strong>, while 'debug' is enabled. Make sure debug is disabled in production environments. Failure to do so will result in an extremely large <tt>app/cache</tt> folder and reduced performance.";
        $this->app['logger.flash']->configuration(Trans::__($notice));
    }

    /**
     * Return the events to subscribe to.
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest', 0],
        ];
    }
}
