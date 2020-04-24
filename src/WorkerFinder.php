<?php
declare(strict_types=1);

namespace DelayedJobs;

use Cake\Core\App;
use Cake\Core\Plugin;
use Cake\Filesystem\Folder;

/**
 * Class WorkerFinder
 */
class WorkerFinder
{
    /**
     * @var array
     */
    protected $workers = [];

    /**
     * Returns all possible workers.
     *
     * Makes sure that app workers are prioritized over plugin ones.
     *
     * @return array
     */
    public function allAppAndPluginWorkers()
    {
        if ($this->workers !== null) {
            return $this->workers;
        }
        $paths = App::path('Worker');
        $this->workers = [];
        foreach ($paths as $path) {
            $Folder = new Folder($path);
            $this->workers = $this->getAppPaths($Folder);
        }
        $plugins = Plugin::loaded();
        foreach ($plugins as $plugin) {
            $pluginPaths = App::path('Worker', $plugin);
            foreach ($pluginPaths as $pluginPath) {
                $Folder = new Folder($pluginPath);
                $pluginworkers = $this->getPluginPaths($Folder, $plugin);
                $this->workers = array_merge($this->workers, $pluginworkers);
            }
        }

        return $this->workers;
    }

    /**
     * @param \Cake\Filesystem\Folder $Folder Folder
     * @return array
     */
    protected function getAppPaths(Folder $Folder)
    {
        $res = array_merge((array)$this->workers, $Folder->findRecursive('.+Worker\.php', true));
        $basePath = $Folder->pwd();
        $quotedBasePath = preg_quote((string)$basePath, '#');
        array_walk($res, function (&$r) use ($quotedBasePath) {
            $r = preg_replace("#^{$quotedBasePath}(.+)Worker\.php$#", '$1', $r);
        });

        return $res;
    }

    /**
     * @param \Cake\Filesystem\Folder $Folder Folder
     * @param string $plugin Plugin name
     * @return array
     */
    protected function getPluginPaths(Folder $Folder, $plugin)
    {
        $res = $Folder->findRecursive('.+Worker\.php', true);
        $basePath = $Folder->pwd();
        $quotedBasePath = preg_quote((string)$basePath, '#');
        foreach ($res as $key => $r) {
            $name = preg_replace("#^{$quotedBasePath}(.+)Worker\.php$#", '$1', $r);
            if (in_array($name, (array)$this->workers)) {
                unset($res[$key]);
                continue;
            }
            $res[$key] = $plugin . '.' . $name;
        }

        return $res;
    }
}
