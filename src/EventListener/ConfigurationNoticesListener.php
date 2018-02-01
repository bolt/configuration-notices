<?php

namespace Bolt\ConfigurationNotices\EventListener;

use Bolt\Collection\Bag;
use Bolt\Storage\Entity\LogChange;
use Bolt\Storage\Entity\LogSystem;
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
    /** @var Application $app */
    protected $app;

    protected $logThreshold = null;

    protected $defaultDomainPartials = ['.dev', 'dev.', 'devel.', 'development.', 'test.', '.test', 'new.', '.new', '.local', 'local.'];

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
        $route = $request->get('_route');

        // Only do these 'expensive' checks on a select few backend pages.
        if (!in_array($route, ['dashboard', 'login', 'userfirst'])) {
            return;
        }

        $this->logThreshold = $this->app['config']->get('general/configuration_notices/log_threshold', 10000);

        $this->app['stopwatch']->start('bolt.configuration_notices');

        $this->singleHostnameCheck($request);
        $this->ipAddressCheck($request);
        $this->topLevelCheck($request);
        $this->writableFolderCheck();

        // Do these only when logged in, on the dashboard
        if ($route === 'dashboard') {
            $this->mailConfigCheck();
            $this->developmentCheck();
            $this->liveCheck($request);
            $this->gdCheck();
            $this->thumbsFolderCheck();
            $this->canonicalCheck($request);
            $this->imageFunctionsCheck();
            $this->maintenanceCheck();
            $this->thumbnailConfigCheck();
            $this->changelogCheck();
            $this->systemlogCheck();
        }

        $this->app['stopwatch']->stop('bolt.configuration_notices');
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
                'notice'   => 'The <strong>mail configuration parameters</strong> have not been set up. This may interfere with password resets, and extension functionality. Please set up the <code>mailoptions</code> in <code>config.yml</code>.',
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
                'notice'   => "The current version of PHP doesn't have the <strong>GD library enabled</strong>. Without this, Bolt will not be able to generate thumbnails. Please enable <code>php-gd</code>, or ask your system-administrator to do so.",
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

        $host = parse_url($request->getSchemeAndHttpHost());

        // If we have an IP-address, we assume it's "dev"
        if (filter_var($host['host'], FILTER_VALIDATE_IP) !== false) {
            return;
        }

        $domainPartials = (array) $this->app['config']->get('general/debug_local_domains', []);

        $domainPartials = array_unique(array_merge(
            (array) $domainPartials,
            $this->defaultDomainPartials
        ));

        foreach ($domainPartials as $partial) {
            if (strpos($host['host'], $partial) !== false) {
                return;
            }
        }

        $notice = json_encode([
            'severity' => 2,
            'notice'   => "It seems like this website is running on a <strong>non-development environment</strong>, while 'debug' is enabled. Make sure debug is disabled in production environments. If you don't do this, it will result in an extremely large <code>app/cache</code> folder and a measurable reduced performance across all pages.",
            'info'     => "If you wish to hide this message, add a key to your <code>config.yml</code> with a (partial) domain name in it, that should be seen as a development environment: <code>debug_local_domains: [ '.foo' ]</code>.",
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
                'notice'   => 'This is a <strong>development version of Bolt</strong>, so it might contain bugs and unfinished features. Use at your own risk!',
                'info'     => "For 'production' websites, we advise you to stick with the official stable releases.",
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * Check whether or not we're running on a hostname without TLD, like 'http://localhost'.
     */
    protected function singleHostnameCheck(Request $request)
    {
        $hostname = $request->getHttpHost();

        if (strpos($hostname, '.') === false) {
            $message = "You are using <code>$hostname</code> as host name. Some browsers have problems with sessions on hostnames that do not have a <code>.tld</code> in them.";
            $info = 'If you experience difficulties logging on, either configure your webserver to use a hostname with a dot in it, or use another browser.';

            $notice = json_encode([
                'severity' => 1,
                'notice'   => $message,
                'info'     => $info,
            ]);

            $this->app['logger.flash']->configuration($notice);
            if (in_array($this->app['request']->get('_route'), ['login', 'userfirst'])) {
                $this->app['logger.flash']->error($message . ' ' . $info);
            }
        }
    }

    /**
     * Check whether or not we're running on a hostname without TLD, like 'http://localhost'.
     */
    protected function ipAddressCheck(Request $request)
    {
        $hostname = $request->getHttpHost();

        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            $message = "You are using the <strong>IP address</strong> <code>$hostname</code> as host name. This is known to cause problems with sessions.";
            $info = 'If you experience difficulties logging on, either configure your webserver to use a proper hostname, or use another browser.';

            $notice = json_encode([
                'severity' => 1,
                'notice'   => $message,
                'info'     => $info,
            ]);

            $this->app['logger.flash']->configuration($notice);
            if (in_array($this->app['request']->get('_route'), ['login', 'userfirst'])) {
                $this->app['logger.flash']->error($message . ' ' . $info);
            }
        }
    }

    /**
     * Check whether or not we're running on a hostname without TLD, like 'http://localhost'.
     *
     * @param Request $request
     */
    protected function topLevelCheck(Request $request)
    {
        $base = $request->getBaseUrl();

        if (!empty($base)) {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => 'You are using Bolt in a subfolder, <strong>instead of the webroot</strong>.',
                'info'     => "It is recommended to use Bolt from the 'web root', so that it is in the top level. If you wish to use Bolt for only part of a website, we recommend setting up a subdomain like <code>news.example.org</code>. If you are having trouble setting up Bolt in the top level, look into the <a href='https://docs.bolt.cm/howto/troubleshooting-outside-webroot#option-2-use-the-flat-structure-distribution'>Flat Structure</a> distribution, or one of the other options listed on that page.",
            ]);

            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * Check if some common file locations are writable.
     */
    protected function writableFolderCheck()
    {
        $fileName = '/configtester_' . date('Y-m-d-h-i-s') . '.txt';

        $fileSystems = [
            ['name' => 'files', 'folder' => '', 'label' => '<code>files/</code> in the webroot'],
            ['name' => 'extensions', 'folder' => '', 'label' => '<code>extensions/</code> in the webroot'],
            ['name' => 'config', 'folder' => ''],
            ['name' => 'cache', 'folder' => ''],
        ];
        if ($this->app['config']->get('general/database/driver') === 'pdo_sqlite') {
            $fileSystems[] = ['name' => 'app', 'folder' => 'database'];
        }
        $fileSystems = Bag::fromRecursive($fileSystems);

        foreach ($fileSystems as $fileSystem) {
            $contents = $this->isWritable($fileSystem, $fileName);
            if ($contents != 'ok') {
                $folderName = $this->getFoldername($fileSystem);
                $notice = json_encode([
                    'severity' => 1,
                    'notice'   => 'Bolt needs to be able to <strong>write files to</strong> the folder <code>' . $folderName . "</code>, but it doesn't seem to be writable.",
                    'info'     => 'Make sure the folder exists, and is writable to the webserver.',
                ]);
                $this->app['logger.flash']->configuration($notice);
            }
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

        $filePath = 'configtester_' . date('Y-m-d-h-i-s') . '.txt';
        $contents = $this->isWritable(Bag::from(['name' => 'web', 'folder' => 'thumbs']), $filePath);
        if ($contents != 'ok') {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => "Bolt is configured to save thumbnails to disk for performance, but the <code>thumbs/</code> folder doesn't seem to be writable.",
                'info'     => 'Make sure the folder exists, and is writable to the webserver.',
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * Check if the current url matches the canonical.
     *
     * @param Request $request
     */
    protected function canonicalCheck(Request $request)
    {
        $hostname = strtok($request->getUri(), '?');
        $canonical = strtok($this->app['canonical']->getUrl());

        if (!empty($canonical) && ($hostname != $canonical)) {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => "The <code>canonical hostname</code> is set to <code>$canonical</code> in <code>config.yml</code>, but you are currently logged in using another hostname. This might cause issues with uploaded files, or links inserted in the content.",
                'info'     => sprintf(
                    "Log in on Bolt using the proper URL: <code><a href='%s'>%s</a></code>.",
                    $this->app['canonical']->getUrl(),
                    $this->app['canonical']->getUrl()
                ),
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * Check if the exif, fileinfo and gd extensions are enabled / compiled into PHP.
     */
    protected function imageFunctionsCheck()
    {
        if (!extension_loaded('exif') || !function_exists('exif_read_data')) {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => 'The function <code>exif_read_data</code> does not exist, which means that Bolt can not create thumbnail images.',
                'info'     => "Make sure the <code>php-exif</code> extension is enabled and/or compiled into your PHP setup. See <a href='http://php.net/manual/en/exif.installation.php'>here</a>.",
            ]);
            $this->app['logger.flash']->configuration($notice);
        }

        if (!extension_loaded('fileinfo') || !class_exists('finfo')) {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => 'The class <code>finfo</code> does not exist, which means that Bolt can not create thumbnail images.',
                'info'     => "Make sure the <code>fileinfo</code> extension is enabled and/or compiled into your PHP setup. See <a href='http://php.net/manual/en/fileinfo.installation.php'>here</a>.",
            ]);
            $this->app['logger.flash']->configuration($notice);
        }

        if (!extension_loaded('gd') || !function_exists('gd_info')) {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => 'The function <code>gd_info</code> does not exist, which means that Bolt can not create thumbnail images.',
                'info'     => "Make sure the <code>gd</code> extension is enabled and/or compiled into your PHP setup. See <a href='http://php.net/manual/en/image.installation.php'>here</a>.",
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * If the site is in maintenance mode, show this on the dashboard.
     */
    protected function maintenanceCheck()
    {
        if ($this->app['config']->get('general/maintenance_mode', false)) {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => "Bolt's <strong>maintenance mode</strong> is enabled. This means that non-authenticated users will not be able to see the website.",
                'info'     => 'To make the site available to the general public again, set <code>maintenance_mode: false</code> in your <code>config.yml</code> file.',
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * Check if Changelog is enabled, and if doesn't contain too many rows.
     */
    protected function changelogCheck()
    {
        if (!$this->app['config']->get('general/changelog/enabled', false)) {
            return;
        }

        // Get the number of items in the changelog
        $count = $this->app['storage']->getRepository(LogChange::class)->count();

        if ($count > $this->logThreshold) {
            $message = sprintf(
                "Bolt's <strong>changelog</strong> is enabled, and there are more than %s rows in the table.",
                $this->logThreshold
            );
            $info = sprintf(
                "Be sure to clean it up periodically, using a Cron job or on the <a href='%s'>Changelog page</a>.",
                $this->app['url_generator']->generate('changelog')
            );
            $notice = json_encode([
                'severity' => 1,
                'notice'   => $message,
                'info'     => $info,
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * Check if systemlog doesn't contain too many rows.
     */
    protected function systemlogCheck()
    {
        // Get the number of items in the changelog
        $count = $this->app['storage']->getRepository(LogSystem::class)->count();

        if ($count > $this->logThreshold) {
            $message = sprintf(
                "Bolt's <strong>systemlog</strong> is enabled, and there are more than %s rows in the table.",
                $this->logThreshold
            );
            $info = sprintf(
                "Be sure to clean it up periodically, using a Cron job or on the <a href='%s'>Systemlog page</a>.",
                $this->app['url_generator']->generate('systemlog')
            );
            $notice = json_encode([
                'severity' => 1,
                'notice'   => $message,
                'info'     => $info,
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * Check if the thumbnail config has been updated for 3.3+ .
     */
    protected function thumbnailConfigCheck()
    {
        $thumbConfig = $this->app['config']->get('general/thumbnails');

        if ((strpos($thumbConfig['notfound_image'] . $thumbConfig['error_image'], '://') === false)) {
            $notice = json_encode([
                'severity' => 1,
                'notice'   => 'Your configuration settings for <code>thumbnails/notfound_image</code> or <code>thumbnails/error_image</code> contain a value that needs to be updated.',
                'info'     => 'Update the value with a namespace, for example: <code>bolt_assets://img/default_notfound.png</code>.',
            ]);
            $this->app['logger.flash']->configuration($notice);
        }
    }

    /**
     * @param Bag    $fileSystem
     * @param string $filePath
     *
     * @return bool|string
     */
    private function isWritable(Bag $fileSystem, $filePath)
    {
        /** @var \Bolt\Filesystem\FilesystemInterface $fs */
        $fs = $this->app['filesystem']->getFilesystem($fileSystem->get('name'));

        $filePath = $fileSystem->get('folder') . '/' . $filePath;

        try {
            $fs->put($filePath, 'ok');
            $contents = $fs->read($filePath);
            $fs->delete($filePath);
        } catch (\Exception $e) {
            return false;
        }

        return $contents;
    }

    /**
     * @param Bag $fileSystem
     *
     * @return string
     */
    private function getFolderName(Bag $fileSystem)
    {
        if ($fileSystem->has('label')) {
            return $fileSystem->get('label');
        }

        return sprintf('%s://%s', $fileSystem->get('name'), $fileSystem->get('folder'));
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
