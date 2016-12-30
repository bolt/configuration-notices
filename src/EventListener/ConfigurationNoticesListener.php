<?php

namespace Bolt\ConfigurationNotices\EventListener;

use Bolt\Translation\Translator as Trans;
use Bolt\Version;
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
class ConfigurationNoticesListener implements EventSubscriberInterface
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
        // Nothing to do here, if it's not the Master request.
        if (!$event->isMasterRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Only do these 'expensive' checks on the dashboard.
        if ($request->get('_route') !== 'dashboard') {
            return;
        }

        $this->mailConfigCheck();
        $this->developmentCheck();
        $this->liveCheck($request);
        $this->gdCheck();
        $this->thumbsFolderCheck();
        $this->canonicalCheck($request);
    }

    /**
     * No mail transport has been set. We should gently nudge the user to set
     * the mail configuration.
     *
     * @see https://github.com/bolt/bolt/issues/2908
     */
    protected function mailConfigCheck()
    {
        if (!$this->app['config']->get('general/mailoptions') && $this->app['users']->getCurrentuser() && $this->app['users']->isAllowed('files:config')) {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => "The <strong>mail configuration parameters</strong> have not been set up. This may interfere with password resets, and extension functionality. Please set up the <tt>mailoptions</tt> in <tt>config.yml</tt>."
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * Check whether or not the GD-library can be used in PHP. Needed for making thumbnails.
     */
    protected function gdCheck()
    {
        if (!function_exists('imagecreatetruecolor')) {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => "The current version of PHP doesn't have the <strong>GD library enabled</strong>. Without this, Bolt will not be able to generate thumbnails. Please enable <tt>php-gd</tt>, or ask your system-administrator to do so."
            ]);
            $this->app['logger.flash']->configuration($notice);
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

        $domainpartials = array_unique(array_merge(
            (array) $domainpartials,
            ['.dev', 'dev.', 'devel.', 'development.', 'test.', '.test', 'new.', '.new', 'localhost', '.local', 'local.']
        ));

        foreach ($domainpartials as $partial) {
            if (strpos($host, $partial) !== false) {
                return;
            }
        }

        $notice = json_encode([
            'severity' => 2,
            'notice'   => "It seems like this website is running on a <strong>non-development environment</strong>, while 'debug' is enabled. Make sure debug is disabled in production environments. If you don't do this, it will result in an extremely large <tt>app/cache</tt> folder and a measurable reduced performance across all pages.",
            'info'     => "If you wish to hide this message, add a key to your <tt>config.yml</tt> with a (partial) domain name in it, that should be seen as a development environment: <tt>debug_local_domains: [ '.foo' ]</tt>."
        ]);

        $this->app['logger.flash']->configuration($notice);
    }

    /**
     * Check whether or not we're running an alpha, beta or RC, and warn about that.
     */
    protected function developmentCheck()
    {
        if (!Version::isStable()) {

            $notice = json_encode([
                'severity' => 1,
                'notice'   => "This is a <strong>development version of Bolt</strong>, so it might contain bugs and unfinished features. Use at your own risk! ",
                'info'     => "For 'production' websites, we advise you to stick with the official stable releases."
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * Check if the thumbs/ folder is writable, if `save_files: true`
     */
    protected function thumbsFolderCheck()
    {
        if (!$this->app['config']->get('general/thumbnails/save_files')) {
            return;
        }

        $filename = '/thumbs/configtester_' . date('Y-m-d-h-i-s') . '.txt';

        try {
            $fs = $this->app['filesystem']->getFilesystem('web');
            $fs->put($filename, 'ok');
            $contents = $fs->read($filename);
            $fs->delete($filename);
        } catch (\Exception $e) {
            $contents = false;
        }

        if ($contents != 'ok') {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => "Bolt is configured to save thumbnails to disk for performance, but the <tt>thumbs/</tt> folder doesn't seem to be writable.",
                'info'     => "Make sure the folder exists, and is writable to the webserver."
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * Check if the current url matches the canonical.
     */
    protected function canonicalCheck(Request $request)
    {
        $hostname = strtok($request->getUri(), '?');
        $canonical = $this->app['canonical']->getUrl();

        if (!empty($canonical) && ($hostname != $canonical)) {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => "The <tt>canonical: </tt> is set in <tt>config.yml</tt>, but you are currently logged in using another hostname. This might cause issues with uploaded files, or links inserted in the content.",
                'info'     => sprintf(
                    "Log in on Bolt using the proper URL: <tt><a href='%s'>%s</a></tt>.",
                    $this->app['canonical']->getUrl(),
                    $this->app['canonical']->getUrl()
                )
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
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
