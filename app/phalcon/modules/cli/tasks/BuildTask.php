<?php
namespace Webird\Cli\Tasks;

use Phalcon\Mvc\View\Engine\Volt\Compiler as Compiler,
    Phalcon\Mvc\View\Engine\Volt,
    React\EventLoop\Factory as EventLoopFactory,
    React\ChildProcess\Process,
    Webird\Web\Module as WebModule,
    Webird\Admin\Module as AdminModule,
    Webird\CLI\Task,
    Webird\Locale\Compiler as LocaleCompiler,
    Webird\Locale\CompilerException as LocaleCompilerException;

/**
 * Task for Build
 *
 */
class BuildTask extends Task
{

    /**
     *
     */
    public function mainAction($argv)
    {
        $params = $this->parseArgs($argv, [
            'title' => 'Build dist (distribution/production) system',
            'args' => [
                'required' => [],
                'optional' => []
            ],
            'opts' => []
        ]);

        $this->cleanDirectoryStructure();
        $this->buildDirectoryStructure();

        // Build Volt templates first so that the locale messages can be
        // extracted in case of an error
        $this->compileVoltTemplates();
        $this->buildPhalconDir();
        $this->makeEntryPoints();
        $this->copyFiles();
        $this->buildConf();
        $this->installPackages();
        $this->compileLocales();
        $this->buildWebpack();

        exit(0);
    }

    /**
     *
     */
    private function buildPhalconDir()
    {
        $config = $this->config;
        $phalconDir = $config->path->phalconDir;
        $distDir = $config->dev->path->distDir;

        $distDirEsc = escapeshellarg($distDir);
        $phalconAppDirEsc = escapeshellarg($phalconDir);
        $phalconDistDirEsc = escapeshellarg($distDir . 'phalcon');

        if (! isset($config['dev']['phpEncode'])) {
            throw new \Exception('The PHP Encoder value is not set.', 1);
        }
        $phpEncode = $config->dev->phpEncode;

        if (empty($phpEncode)) {
            `cp -R $phalconAppDirEsc $phalconDistDirEsc`;
        } else {
            if (! isset($config->dev->phpEncoders[$phpEncode])) {
                throw new \Exception("The '$phpEncode' PHP encoder setting does not exist", 1);
            }

            $encoder = $config->dev->phpEncoders[$phpEncode];
            $encCmdEsc = escapeshellcmd($encoder->path);
            switch ($phpEncode) {
                case 'ioncube':
                    $cmd = "$encCmdEsc $phalconAppDirEsc --into $distDirEsc --merge-target";
                    exec($cmd, $out, $ret);
                    break;
            }
        }
    }

    /**
     *
     */
    private function cleanDirectoryStructure()
    {
        $projectDir = $this->config->dev->path->projectDir;
        $distDir = $this->config->dev->path->distDir;
        $distDirEsc = escapeshellarg($distDir);

        // TODO: Add more checks for disasters against the rm -Rf command
        // Check for some disaster cases since the script will try to recursively delete the folder
        if ($distDir != "{$projectDir}dist/" || $distDir == '' || $distDir == '/') {
            throw new \Exception('Critical Error: Attempting to delete dist directory when it is not set correctly.');
        }
        if (file_exists($distDir)) {
            exec("rm -Rf $distDirEsc", $out, $ret);
            if ($ret != 0) {
                throw new \Exception('There was a problem deleting the dist directory.');
            }
        }
    }

    /**
     *
     */
    private function buildDirectoryStructure()
    {
        $appDir = $this->config->path->appDir;
        $projectDir = $this->config->dev->path->projectDir;
        $distDir = $this->config->dev->path->distDir;

        mkdir($distDir);
        mkdir($distDir . 'public/');
        mkdir($distDir . 'etc/');
        mkdir($distDir . 'cache-static/');
        mkdir($distDir . 'cache-static/volt/');
    }

    /**
     *
     */
    private function compileVoltTemplates()
    {
        $path = $this->config->path;
        $devPath = $this->config->dev->path;

        $voltCacheDirBak = $path->voltCacheDir;
        $voltCacheDirDist = $devPath->distDir . "cache-static/volt/";
        $path->voltCacheDir = $voltCacheDirDist;
        echo "Temporarily changing voltCacheDir to {$voltCacheDirDist}\n";

        $di = $this->getDI();

        try {
            $this->compileVoltTemplateForModule('admin');
            $this->compileVoltTemplateForModule('web');
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        // Simple views
        $this->compileVoltDir($path->viewsSimpleDir, function() use ($di) {
            return $di->get('viewSimple');
        });

        // Common partial views
        // This is a bit hacky but it works.  We are treating the common partials as Simple to compile them.
        // We are simply changing the directory to look into and on the view itself
        $this->compileVoltDir($path->viewsCommonDir . 'partials/', function() use ($di) {
            $simpleView = $di->get('viewSimple');
            $simpleView->setViewsDir($di->getConfig()->path->viewsCommonDir . 'partials/');
            return $simpleView;
        });

        $path->voltCacheDir = $voltCacheDirBak;
        echo "Reverting voltCacheDir to original path\n";
    }

    /**
     *
     */
    private function compileVoltTemplateForModule($moduleName)
    {
        $di = $this->getDI();

        $moduleClass = '\\Webird\\' . ucfirst($moduleName) . '\\Module';
        $viewFunc = $moduleClass::getViewFunc($di);

        $view = $viewFunc();
        $viewsDir = $view->getViewsDir();
        $viewsLayoutsDir = $viewsDir . $view->getLayoutsDir();
        $viewsPartialsDir = $viewsDir . $view->getPartialsDir();

        $this->compileVoltDir($viewsDir, $viewFunc);
    }

    /**
     *
     */
    private function compileVoltDir($path, $viewFunc)
    {
        $dh = opendir($path);
        while (($fileName = readdir($dh)) !== false) {
            if ($fileName == '.' || $fileName == '..') {
                continue;
            }

            $pathNext = "{$path}{$fileName}";
            if (is_dir($pathNext)) {
                $this->compileVoltDir("$pathNext/", $viewFunc);
            } else {
                $di = $this->getDI();
                $volt = $di->get('voltService', [$viewFunc(), $di]);
                $compiler = $volt->getCompiler();
                $compiler->compile($pathNext);
            }
        }

        // close the directory handle
        closedir($dh);
    }

    /**
     *
     */
    private function makeEntryPoints()
    {
        $distDir = $this->config->dev->path->distDir;

        $cliEntry = <<<'WEBIRD_ENTRY'
#!/usr/bin/env php
<?php
define('ENV', 'dist');
require(__DIR__ . '/phalcon/bootstrap_cli.php');
WEBIRD_ENTRY;
        file_put_contents("$distDir/webird.php", $cliEntry);
        chmod("$distDir/webird.php", 0775);

        $webEntry = <<<'WEBIRD_ENTRY'
<?php
define('ENV', 'dist');
require(__DIR__ . '/../phalcon/bootstrap_web.php');
WEBIRD_ENTRY;
        file_put_contents("$distDir/public/index.php", $webEntry);
    }

    /**
     *
     */
    private function copyFiles()
    {
        $projectDir = $this->config->dev->path->projectDir;
        $appDir = $this->config->path->appDir;
        $etcDir = $this->config->dev->path->etcDir;
        $devDir = $this->config->dev->path->devDir;
        $distDir = $this->config->dev->path->distDir;
        // shell escaped configuration directories
        $appDirEsc = escapeshellarg($appDir);
        $projectDirEsc = escapeshellarg($projectDir);
        $devDirEsc = escapeshellarg($devDir);
        $distDirEsc = escapeshellarg($distDir);

        // Copy Composer configuration
        copy("$devDir/composer.json", "$distDir/composer.json");
        copy("$devDir/composer.lock", "$distDir/composer.lock");
        // Copy Npm/Nodejs configuration
        copy("$devDir/package.json", "$distDir/package.json");
        // Copy Bower configuration
        copy("$devDir/bower.json", "$distDir/bower.json");

        `cp -R $appDir/theme/assets $distDir/public/assets`;

        copy("$etcDir/schema.sql", "$distDir/etc/schema.sql");
        // Move the CLI startup program to the root dist directory
        chmod("$distDir/webird.php", 0775);
    }

    /**
     *
     */
    private function buildConf()
    {
        $etcDir = $this->config->dev->path->etcDir;
        $localeDir = $this->config->path->localeDir;
        $devDir = $this->config->dev->path->devDir;
        $distDir = $this->config->dev->path->distDir;

        $config1 = yaml_parse_file("$etcDir/dist_defaults.yml");
        $config2 = yaml_parse_file("$etcDir/dist.yml");

        $localeConfig = yaml_parse_file("$localeDir/config.yml");
        $localeConfig['supported'] = $this->getDI()->getLocale()->getSupportedLocales();
        $config3 = [
            'locale' => $localeConfig
        ];

        // Merge the custom settings over the defaults
        $configMerged = array_replace_recursive($config1, $config2, $config3);

        // Write the merged settings to the dist directory
        yaml_emit_file("$distDir/etc/config.yml", $configMerged);
    }

    /**
     *
     */
    private function installPackages()
    {
        $distDir = $this->config->dev->path->distDir;

        $cwd = getcwd();
        chdir($distDir);

        exec("composer --no-dev install", $out, $ret);
        exec("skipclean=1 && npm install --production", $out, $ret);
        exec("bower install --production --allow-root --config.interactive=false", $out, $ret);

        chdir($cwd);
    }

    /**
     *
     */
    private function compileLocales()
    {
        $supported = $this->getDI()->getLocale()->getSupportedLocales();

        $distDir = $this->config->dev->path->distDir;

        $localeCacheDir = $distDir . 'cache-static/locale/';

        foreach ($supported as $locale => $true) {
            try {
                $compiler = new LocaleCompiler();
                $success = $compiler->compileLocale([
                    'locale'         => $locale,
                    'domains'        => $this->config->locale->domains,
                    'localeDir'      => $this->config->path->localeDir,
                    'localeCacheDir' => $localeCacheDir
                ]);
            } catch (LocaleCompilerException $e) {
                error_log($e->getMessage());
                exit(1);
            }
        }
    }

    /**
     *
     */
    private function buildWebpack()
    {
        $devDirEsc = escapeshellarg($this->config->dev->path->devDir);

        echo "Building webpack bundle.  This usually takes 5-30 seconds and up to 60 seconds on a thin VPS slice.\n";
        echo "Remove unnecessary entry points and dependencies in your webpack config to improve the build performance.\n";
        exec("cd $devDirEsc && npm run build", $out, $ret);
        if ($ret != 0) {
            throw new \Exception('Webpack build error.');
        }
    }

}
