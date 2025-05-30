<?php namespace System\Classes;

use App;
use Url;
use File;
use Lang;
use Http;
use Cache;
use Schema;
use Config;
use Request;
use ApplicationException;
use Cms\Classes\ThemeManager;
use System\Models\Parameter;
use System\Models\PluginVersion;
use System\Helpers\Cache as CacheHelper;
use October\Rain\Filesystem\Zip;
use Carbon\Carbon;
use Exception;

/**
 * Update manager
 *
 * Handles the CMS install and update process.
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class UpdateManager
{
    use \October\Rain\Support\Traits\Singleton;

    /**
     * @var \Illuminate\Console\OutputStyle
     */
    protected $notesOutput;

    /**
     * @var string Application base path.
     */
    protected $baseDirectory;

    /**
     * @var string A temporary working directory.
     */
    protected $tempDirectory;

    /**
     * @var \System\Classes\PluginManager
     */
    protected $pluginManager;

    /**
     * @var \Cms\Classes\ThemeManager
     */
    protected $themeManager;

    /**
     * @var \System\Classes\VersionManager
     */
    protected $versionManager;

    /**
     * @var string Secure API Key
     */
    protected $key;

    /**
     * @var string Secure API Secret
     */
    protected $secret;

    /**
     * @var boolean If set to true, core updates will not be downloaded or extracted.
     */
    protected $disableCoreUpdates = false;

    /**
     * @var array Cache of gateway products
     */
    protected $productCache;

    /**
     * @var \Illuminate\Database\Migrations\Migrator
     */
    protected $migrator;

    /**
     * @var \Illuminate\Database\Migrations\DatabaseMigrationRepository
     */
    protected $repository;

    /**
     * @var array An array of messages returned by migrations / seeders. Returned at the end of the update process.
     */
    protected $messages = [];

    /**
     * Initialize this singleton.
     */
    protected function init()
    {
        $this->pluginManager = PluginManager::instance();
        $this->themeManager = class_exists(ThemeManager::class) ? ThemeManager::instance() : null;
        $this->versionManager = VersionManager::instance();
        $this->tempDirectory = temp_path();
        $this->baseDirectory = base_path();
        $this->disableCoreUpdates = Config::get('cms.disableCoreUpdates', true);
        $this->bindContainerObjects();

        /*
         * Ensure temp directory exists
         */
        if (!File::isDirectory($this->tempDirectory)) {
            File::makeDirectory($this->tempDirectory, 0777, true);
        }
    }

    /**
     * These objects are "soft singletons" and may be lost when
     * the IoC container reboots. This provides a way to rebuild
     * for the purposes of unit testing.
     */
    public function bindContainerObjects()
    {
        $this->migrator = App::make('migrator');
        $this->repository = App::make('migration.repository');
    }

    /**
     * Creates the migration table and updates
     * @return self
     */
    public function update()
    {
        $firstUp = !Schema::hasTable($this->getMigrationTableName());
        if ($firstUp) {
            $this->repository->createRepository();
            $this->note('Migration table created');
        }

        /*
         * Update modules
         */
        $modules = Config::get('cms.loadModules', []);
        foreach ($modules as $module) {
            $this->migrateModule($module);
        }

        /*
         * Update plugins
         */
        $plugins = $this->pluginManager->getPlugins();
        foreach ($plugins as $code => $plugin) {
            $this->updatePlugin($code);
        }

        Parameter::set('system::update.count', 0);
        CacheHelper::clear();

        /*
         * Seed modules
         */
        if ($firstUp) {
            $modules = Config::get('cms.loadModules', []);
            foreach ($modules as $module) {
                $this->seedModule($module);
            }
        }

        // Print messages returned by migrations / seeders
        $this->printMessages();

        return $this;
    }

    /**
     * Checks for new updates and returns the amount of unapplied updates.
     * Only requests from the server at a set interval (retry timer).
     * @param boolean $force Ignore the retry timer.
     * @return int            Number of unapplied updates.
     */
    public function check($force = false)
    {
        /*
         * Already know about updates, never retry.
         */
        $oldCount = Parameter::get('system::update.count');
        if ($oldCount > 0) {
            return $oldCount;
        }

        /*
         * Retry period not passed, skipping.
         */
        if (!$force
            && ($retryTimestamp = Parameter::get('system::update.retry'))
            && Carbon::createFromTimeStamp($retryTimestamp)->isFuture()
        ) {
            return $oldCount;
        }

        try {
            $result = $this->requestUpdateList();
            $newCount = array_get($result, 'update', 0);
        } catch (Exception $ex) {
            $newCount = 0;
        }

        /*
         * Remember update count, set retry date
         */
        Parameter::set('system::update.count', $newCount);
        Parameter::set('system::update.retry', Carbon::now()->addHours(24)->timestamp);

        return $newCount;
    }

    /**
     * Requests an update list used for checking for new updates.
     * @param boolean $force Request application and plugins hash list regardless of version.
     * @return array
     */
    public function requestUpdateList($force = false)
    {
        $installed = PluginVersion::all();
        $versions = $installed->lists('version', 'code');
        $names = $installed->lists('name', 'code');
        $icons = $installed->lists('icon', 'code');
        $frozen = $installed->lists('is_frozen', 'code');
        $updatable = $installed->lists('is_updatable', 'code');
        $build = Parameter::get('system::core.build');
        $themes = [];

        if ($this->themeManager) {
            $themes = array_keys($this->themeManager->getInstalled());
        }

        $params = [
            'core'    => $this->getHash(),
            'plugins' => base64_encode(json_encode($versions)),
            'themes'  => base64_encode(json_encode($themes)),
            'build'   => $build,
            'force'   => $force
        ];

        $result = $this->requestServerData('core/update', $params);
        $updateCount = (int) array_get($result, 'update', 0);

        /*
         * Inject known core build
         */
        if ($core = array_get($result, 'core')) {
            $core['old_build'] = Parameter::get('system::core.build');
            $result['core'] = $core;
        }

        /*
         * Inject the application's known plugin name and version
         */
        $plugins = [];
        foreach (array_get($result, 'plugins', []) as $code => $info) {
            $info['name'] = $names[$code] ?? $code;
            $info['old_version'] = $versions[$code] ?? false;
            $info['icon'] = $icons[$code] ?? false;

            /*
             * If a plugin has updates frozen, or cannot be updated,
             * do not add to the list and discount an update unit.
             */
            if (
                (isset($frozen[$code]) && $frozen[$code]) ||
                (isset($updatable[$code]) && !$updatable[$code])
            ) {
                $updateCount = max(0, --$updateCount);
            } else {
                $plugins[$code] = $info;
            }
        }
        $result['plugins'] = $plugins;

        /*
         * Strip out themes that have been installed before
         */
        if ($this->themeManager) {
            $themes = [];
            foreach (array_get($result, 'themes', []) as $code => $info) {
                if (!$this->themeManager->isInstalled($code)) {
                    $themes[$code] = $info;
                }
            }
            $result['themes'] = $themes;
        }

        /*
         * If there is a core update and core updates are disabled,
         * remove the entry and discount an update unit.
         */
        if (array_get($result, 'core') && $this->disableCoreUpdates) {
            $updateCount = max(0, --$updateCount);
            unset($result['core']);
        }

        /*
         * Recalculate the update counter
         */
        $updateCount += count($themes);
        $result['hasUpdates'] = $updateCount > 0;
        $result['update'] = $updateCount;
        Parameter::set('system::update.count', $updateCount);

        return $result;
    }

    /**
     * Requests details about a project based on its identifier.
     * @param string $projectId
     * @return array
     */
    public function requestProjectDetails($projectId)
    {
        return $this->requestServerData('project/detail', ['id' => $projectId]);
    }

    /**
     * Roll back all modules and plugins.
     * @return self
     */
    public function uninstall()
    {
        /*
         * Rollback plugins
         */
        $plugins = array_reverse($this->pluginManager->getPlugins());
        foreach ($plugins as $name => $plugin) {
            $this->rollbackPlugin($name);
        }

        /*
         * Register module migration files
         */
        $paths = [];
        $modules = Config::get('cms.loadModules', []);

        foreach ($modules as $module) {
            $paths[] = $path = base_path() . '/modules/' . strtolower($module) . '/database/migrations';
        }

        /*
         * Rollback modules
         */
        if (isset($this->notesOutput)) {
            $this->migrator->setOutput($this->notesOutput);
        }

        while (true) {
            $rolledBack = $this->migrator->rollback($paths, ['pretend' => false]);

            if (count($rolledBack) == 0) {
                break;
            }
        }

        Schema::dropIfExists($this->getMigrationTableName());

        return $this;
    }

    /**
     * Determines build number from source manifest.
     *
     * This will return an array with the following information:
     *  - `build`: The build number we determined was most likely the build installed.
     *  - `modified`: Whether we detected any modifications between the installed build and the manifest.
     *  - `confident`: Whether we are at least 60% sure that this is the installed build. More modifications to
     *                  to the code = less confidence.
     *  - `changes`: If $detailed is true, this will include the list of files modified, created and deleted.
     *
     * @param bool $detailed If true, the list of files modified, added and deleted will be included in the result.
     * @return array
     */
    public function getBuildNumberManually($detailed = false)
    {
        $source = new SourceManifest();
        $manifest = new FileManifest(null, null, true);

        // Find build by comparing with source manifest
        return $source->compare($manifest, $detailed);
    }

    /**
     * Sets the build number in the database.
     *
     * @param bool $detailed If true, the list of files modified, added and deleted will be included in the result.
     * @return void
     */
    public function setBuildNumberManually($detailed = false)
    {
        $build = $this->getBuildNumberManually($detailed);

        if ($build['confident']) {
            $this->setBuild($build['build'], null, $build['modified']);
        }

        return $build;
    }

    //
    // Modules
    //

    /**
     * Returns the currently installed system hash.
     * @return string
     */
    public function getHash()
    {
        return Parameter::get('system::core.hash', md5('NULL'));
    }

    /**
     * Run migrations on a single module
     * @param string $module Module name
     * @return self
     */
    public function migrateModule($module)
    {
        if (isset($this->notesOutput)) {
            $this->migrator->setOutput($this->notesOutput);
        }

        $this->note($module);

        $this->migrator->run(base_path() . '/modules/'.strtolower($module).'/database/migrations');

        return $this;
    }

    /**
     * Run seeds on a module
     * @param string $module Module name
     * @return self
     */
    public function seedModule($module)
    {
        $className = '\\' . $module . '\Database\Seeds\DatabaseSeeder';
        if (!class_exists($className)) {
            return;
        }

        $seeder = App::make($className);
        $return = $seeder->run();

        if (isset($return) && (is_string($return) || is_array($return))) {
            $this->addMessage($className, $return);
        }

        $this->note(sprintf('<info>Seeded %s</info> ', $module));
        return $this;
    }

    /**
     * Downloads the core from the update server.
     * @param string $hash Expected file hash.
     * @return void
     */
    public function downloadCore($hash)
    {
        $this->requestServerFile('core/get', 'core', $hash, ['type' => 'update']);
    }

    /**
     * Extracts the core after it has been downloaded.
     * @return void
     */
    public function extractCore()
    {
        $filePath = $this->getFilePath('core');

        if (!Zip::extract($filePath, $this->baseDirectory)) {
            throw new ApplicationException(Lang::get('system::lang.zip.extract_failed', ['file' => $filePath]));
        }

        @unlink($filePath);
    }

    /**
     * Sets the build number and hash
     * @param string $hash
     * @param string $build
     * @param bool $modified
     * @return void
     */
    public function setBuild($build, $hash = null, $modified = false)
    {
        $params = [
            'system::core.build' => $build,
            'system::core.modified' => $modified,
        ];

        if ($hash) {
            $params['system::core.hash'] = $hash;
        }

        Parameter::set($params);
    }

    //
    // Plugins
    //

    /**
     * Looks up a plugin from the update server.
     * @param string $name Plugin name.
     * @return array Details about the plugin.
     */
    public function requestPluginDetails($name)
    {
        return $this->requestServerData('plugin/detail', ['name' => $name]);
    }

    /**
     * Looks up content for a plugin from the update server.
     * @param string $name Plugin name.
     * @return array Content for the plugin.
     */
    public function requestPluginContent($name)
    {
        return $this->requestServerData('plugin/content', ['name' => $name]);
    }

    /**
     * Runs update on a single plugin
     * @param string $name Plugin name.
     * @return self
     */
    public function updatePlugin($name)
    {
        /*
         * Update the plugin database and version
         */
        if (!($plugin = $this->pluginManager->findByIdentifier($name))) {
            $this->note('<error>Unable to find:</error> ' . $name);
            return;
        }

        $this->note($name);

        $this->versionManager->setNotesOutput($this->notesOutput);

        $this->versionManager->updatePlugin($plugin);

        return $this;
    }

    /**
     * Rollback an existing plugin
     *
     * @param string $name Plugin name.
     * @param string $stopOnVersion If this parameter is specified, the process stops once the provided version number is reached
     * @return self
     */
    public function rollbackPlugin(string $name, string $stopOnVersion = null)
    {
        /*
         * Remove the plugin database and version
         */
        if (!($plugin = $this->pluginManager->findByIdentifier($name))
            && $this->versionManager->purgePlugin($name)
        ) {
            $this->note('<info>Purged from database:</info> ' . $name);
            return $this;
        }

        if ($stopOnVersion && !$this->versionManager->hasDatabaseVersion($plugin, $stopOnVersion)) {
            throw new ApplicationException(Lang::get('system::lang.updates.plugin_version_not_found'));
        }

        if ($this->versionManager->removePlugin($plugin, $stopOnVersion, true)) {
            $this->note('<info>Rolled back:</info> ' . $name);

            if ($currentVersion = $this->versionManager->getCurrentVersion($plugin)) {
                $this->note('<info>Current Version:</info> ' . $currentVersion . ' (' . $this->versionManager->getCurrentVersionNote($plugin) . ')');
            }

            return $this;
        }

        $this->note('<error>Unable to find:</error> ' . $name);

        return $this;
    }

    /**
     * Downloads a plugin from the update server.
     * @param string $name Plugin name.
     * @param string $hash Expected file hash.
     * @param boolean $installation Indicates whether this is a plugin installation request.
     * @return self
     */
    public function downloadPlugin($name, $hash, $installation = false)
    {
        $fileCode = $name . $hash;
        $this->requestServerFile('plugin/get', $fileCode, $hash, [
            'name'         => $name,
            'installation' => $installation ? 1 : 0
        ]);
    }

    /**
     * Extracts a plugin after it has been downloaded.
     */
    public function extractPlugin($name, $hash)
    {
        $fileCode = $name . $hash;
        $filePath = $this->getFilePath($fileCode);
        $innerPath = str_replace('.', '/', strtolower($name));

        if (!Zip::extract($filePath, plugins_path($innerPath))) {
            throw new ApplicationException(Lang::get('system::lang.zip.extract_failed', ['file' => $filePath]));
        }

        @unlink($filePath);
    }

    //
    // Themes
    //

    /**
     * Looks up a theme from the update server.
     * @param string $name Theme name.
     * @return array Details about the theme.
     */
    public function requestThemeDetails($name)
    {
        return $this->requestServerData('theme/detail', ['name' => $name]);
    }

    /**
     * Downloads a theme from the update server.
     * @param string $name Theme name.
     * @param string $hash Expected file hash.
     * @return self
     */
    public function downloadTheme($name, $hash)
    {
        $fileCode = $name . $hash;

        $this->requestServerFile('theme/get', $fileCode, $hash, ['name' => $name]);
    }

    /**
     * Extracts a theme after it has been downloaded.
     */
    public function extractTheme($name, $hash)
    {
        $fileCode = $name . $hash;
        $filePath = $this->getFilePath($fileCode);
        $innerPath = str_replace('.', '-', strtolower($name));

        if (!Zip::extract($filePath, themes_path($innerPath))) {
            throw new ApplicationException(Lang::get('system::lang.zip.extract_failed', ['file' => $filePath]));
        }

        if ($this->themeManager) {
            $this->themeManager->setInstalled($name);
        }

        @unlink($filePath);
    }

    //
    // Products
    //

    public function requestProductDetails($codes, $type = null)
    {
        if ($type != 'plugin' && $type != 'theme') {
            $type = 'plugin';
        }

        $codes = (array) $codes;
        $this->loadProductDetailCache();

        /*
         * New products requested
         */
        $newCodes = array_diff($codes, array_keys($this->productCache[$type]));
        if (count($newCodes)) {
            $dataCodes = [];
            $data = $this->requestServerData($type . '/details', ['names' => $newCodes]);
            foreach ($data as $product) {
                $code = array_get($product, 'code', -1);
                $this->cacheProductDetail($type, $code, $product);
                $dataCodes[] = $code;
            }

            /*
             * Cache unknown products
             */
            $unknownCodes = array_diff($newCodes, $dataCodes);
            foreach ($unknownCodes as $code) {
                $this->cacheProductDetail($type, $code, -1);
            }

            $this->saveProductDetailCache();
        }

        /*
         * Build details from cache
         */
        $result = [];
        $requestedDetails = array_intersect_key($this->productCache[$type], array_flip($codes));

        foreach ($requestedDetails as $detail) {
            if ($detail === -1) {
                continue;
            }
            $result[] = $detail;
        }

        return $result;
    }

    /**
     * Returns popular themes found on the marketplace.
     */
    public function requestPopularProducts($type = null)
    {
        if ($type != 'plugin' && $type != 'theme') {
            $type = 'plugin';
        }

        $cacheKey = 'system-updates-popular-' . $type;

        if (Cache::has($cacheKey)) {
            return @unserialize(@base64_decode(Cache::get($cacheKey))) ?: [];
        }

        $data = $this->requestServerData($type . '/popular');
        $expiresAt = now()->addMinutes(60);
        Cache::put($cacheKey, base64_encode(serialize($data)), $expiresAt);

        foreach ($data as $product) {
            $code = array_get($product, 'code', -1);
            $this->cacheProductDetail($type, $code, $product);
        }

        $this->saveProductDetailCache();

        return $data;
    }

    protected function loadProductDetailCache()
    {
        $defaultCache = ['theme' => [], 'plugin' => []];
        $cacheKey = 'system-updates-product-details';

        if (Cache::has($cacheKey)) {
            $this->productCache = @unserialize(@base64_decode(Cache::get($cacheKey))) ?: $defaultCache;
        } else {
            $this->productCache = $defaultCache;
        }
    }

    protected function saveProductDetailCache()
    {
        if ($this->productCache === null) {
            $this->loadProductDetailCache();
        }

        $cacheKey = 'system-updates-product-details';
        $expiresAt = Carbon::now()->addDays(2);
        Cache::put($cacheKey, base64_encode(serialize($this->productCache)), $expiresAt);
    }

    protected function cacheProductDetail($type, $code, $data)
    {
        if ($this->productCache === null) {
            $this->loadProductDetailCache();
        }

        $this->productCache[$type][$code] = $data;
    }

    //
    // Changelog
    //

    /**
     * Returns the latest changelog information.
     */
    public function requestChangelog()
    {
        $result = Http::get('https://octobercms.com/changelog?json');

        if ($result->code == 404) {
            throw new ApplicationException(Lang::get('system::lang.server.response_empty'));
        }

        if ($result->code != 200) {
            throw new ApplicationException(
                strlen($result->body)
                ? $result->body
                : Lang::get('system::lang.server.response_empty')
            );
        }

        try {
            $resultData = json_decode($result->body, true);
        } catch (Exception $ex) {
            throw new ApplicationException(Lang::get('system::lang.server.response_invalid'));
        }

        return $resultData;
    }

    //
    // Notes
    //

    /**
     * Raise a note event for the migrator.
     * @param string $message
     * @return self
     */
    protected function note($message)
    {
        if ($this->notesOutput !== null) {
            $this->notesOutput->writeln($message);
        }

        return $this;
    }

    /**
     * Sets an output stream for writing notes.
     * @param Illuminate\Console\Command $output
     * @return self
     */
    public function setNotesOutput($output)
    {
        $this->notesOutput = $output;

        return $this;
    }

    //
    // Gateway access
    //

    /**
     * Contacts the update server for a response.
     * @param string $uri Gateway API URI
     * @param array $postData Extra post data
     * @return array
     */
    public function requestServerData($uri, $postData = [])
    {
        $result = Http::post($this->createServerUrl($uri), function ($http) use ($postData) {
            $this->applyHttpAttributes($http, $postData);
        });

        if ($result->code == 404) {
            throw new ApplicationException(Lang::get('system::lang.server.response_not_found'));
        }

        if ($result->code != 200) {
            throw new ApplicationException(
                strlen($result->body)
                ? $result->body
                : Lang::get('system::lang.server.response_empty')
            );
        }

        $resultData = false;

        try {
            $resultData = @json_decode($result->body, true);
        } catch (Exception $ex) {
            throw new ApplicationException(Lang::get('system::lang.server.response_invalid'));
        }

        if ($resultData === false || (is_string($resultData) && !strlen($resultData))) {
            throw new ApplicationException(Lang::get('system::lang.server.response_invalid'));
        }

        if (!$this->validateServerSignature($resultData, $result->headers['Rest-Sign'] ?? '')) {
            throw new ApplicationException(Lang::get('system::lang.server.response_invalid') . ' (Bad signature)');
        }

        return $resultData;
    }

    /**
     * Downloads a file from the update server.
     * @param string $uri Gateway API URI
     * @param string $fileCode A unique code for saving the file.
     * @param string $expectedHash The expected file hash of the file.
     * @param array $postData Extra post data
     * @return void
     */
    public function requestServerFile($uri, $fileCode, $expectedHash, $postData = [])
    {
        $filePath = $this->getFilePath($fileCode);

        $result = Http::post($this->createServerUrl($uri), function ($http) use ($postData, $filePath) {
            $this->applyHttpAttributes($http, $postData);
            $http->toFile($filePath);
        });

        if (in_array($result->code, [301, 302])) {
            if ($redirectUrl = array_get($result->info, 'redirect_url')) {
                $result = Http::get($redirectUrl, function ($http) use ($postData, $filePath) {
                    $http->toFile($filePath);
                });
            }
        }

        if ($result->code != 200) {
            throw new ApplicationException(File::get($filePath));
        }
    }

    /**
     * Calculates a file path for a file code
     * @param string $fileCode A unique file code
     * @return string           Full path on the disk
     */
    protected function getFilePath($fileCode)
    {
        $name = md5($fileCode) . '.arc';
        return $this->tempDirectory . '/' . $name;
    }

    /**
     * Set the API security for all transmissions.
     * @param string $key API Key
     * @param string $secret API Secret
     */
    public function setSecurity($key, $secret)
    {
        $this->key = $key;
        $this->secret = $secret;
    }

    /**
     * Create a complete gateway server URL from supplied URI
     * @param string $uri URI
     * @return string      URL
     */
    protected function createServerUrl($uri)
    {
        $gateway = Config::get('cms.updateServer', 'https://gateway.octobercms.com/api');
        if (substr($gateway, -1) != '/') {
            $gateway .= '/';
        }

        return $gateway . $uri;
    }

    /**
     * Modifies the Network HTTP object with common attributes.
     * @param Http $http Network object
     * @param array $postData Post data
     * @return void
     */
    protected function applyHttpAttributes($http, $postData)
    {
        $postData['protocol_version'] = '1.3';
        $postData['client'] = 'October CMS';

        $postData['server'] = base64_encode(json_encode([
            'php'   => PHP_VERSION,
            'url'   => Url::to('/'),
            'ip'    => Request::ip(),
            'since' => PluginVersion::orderBy('created_at')->value('created_at')
        ]));

        if ($projectId = Parameter::get('system::project.id')) {
            $postData['project'] = $projectId;
        }

        if (Config::get('cms.edgeUpdates', false)) {
            $postData['edge'] = 1;
        }

        if ($this->key && $this->secret) {
            $postData['nonce'] = $this->createNonce();
            $http->header('Rest-Key', $this->key);
            $http->header('Rest-Sign', $this->createSignature($postData, $this->secret));
        }

        if ($credentials = Config::get('cms.updateAuth')) {
            $http->auth($credentials);
        }

        $http->noRedirect();
        $http->data($postData);
    }

    /**
     * Create a nonce based on millisecond time
     * @return int
     */
    protected function createNonce()
    {
        $mt = explode(' ', microtime());
        return $mt[1] . substr($mt[0], 2, 6);
    }

    /**
     * Create a unique signature for transmission.
     * @return string
     */
    protected function createSignature($data, $secret)
    {
        return base64_encode(hash_hmac('sha512', http_build_query($data, '', '&'), base64_decode($secret), true));
    }

    /**
     * @return string
     */
    public function getMigrationTableName()
    {
        return Config::get('database.migrations', 'migrations');
    }

    /**
     * Adds a message from a specific migration or seeder.
     *
     * @param string|object $class
     * @param string|array $message
     * @return void
     */
    protected function addMessage($class, $message)
    {
        if (empty($message)) {
            return;
        }

        if (is_object($class)) {
            $class = get_class($class);
        }
        if (!isset($this->messages[$class])) {
            $this->messages[$class] = [];
        }

        if (is_string($message)) {
            $this->messages[$class][] = $message;
        } elseif (is_array($message)) {
            array_merge($this->messages[$class], $message);
        }
    }

    /**
     * Prints collated messages from the migrations and seeders
     *
     * @return void
     */
    protected function printMessages()
    {
        if (!count($this->messages)) {
            return;
        }

        // Add a line break
        $this->note('');

        foreach ($this->messages as $class => $messages) {
            $this->note(sprintf('<info>%s reported:</info>', $class));

            foreach ($messages as $message) {
                $this->note(' - ' . (string) $message);
            }
        }
    }

    /**
     * validateServerSignature checks the server has provided a valid signature
     *
     * @return bool
     */
    protected function validateServerSignature($data, $signature)
    {
        if (!$signature) {
            return false;
        }

        $signature = base64_decode($signature);

        $pubKey = '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAt+KwvTXqC8Mz9vV4KIvX
3y+aZusrlg26jdbNVUuhXNFbt1VisjJydHW2+WGsiEHSy2s61ZAV2dICR6f3huSw
jY/MH9j23Oo/u61CBpvIS3Q8uC+TLtJl4/F9eqlnzocfMoKe8NmcBbUR3TKQoIok
xbSMl6jiE2k5TJdzhHUxjZRIeeLDLMKYX6xt37LdhuM8zO6sXQmCGg4J6LmHTJph
96H11gBvcFSFJSmIiDykJOELZl/aVcY1g3YgpL0mw5Bw1VTmKaRdz1eBi9DmKrKX
UijG4gD8eLRV/FS/sZCFNR/evbQXvTBxO0TOIVi85PlQEcMl4SBj0CoTyNbcAGtz
4wIDAQAB
-----END PUBLIC KEY-----';

        $pubKey = Config::get('system.update_gateway_key', $pubKey);

        $data = base64_encode(json_encode($data));

        return openssl_verify($data, $signature, $pubKey) === 1;
    }
}
