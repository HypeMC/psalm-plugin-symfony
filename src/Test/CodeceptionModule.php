<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Test;

use Codeception\Module as BaseModule;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use InvalidArgumentException;
use PackageVersions\Versions;
use PHPUnit\Framework\SkippedTestError;
use RuntimeException;
use Twig\Cache\FilesystemCache;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;

/**
 * @psalm-suppress UnusedClass
 * This class is to be used in codeception configuration - like in tests/acceptance/acceptance.suite.yml.
 */
class CodeceptionModule extends BaseModule
{
    /** @var array<string,string> */
    protected $config = [
        'default_dir' => 'tests/_run/',
    ];

    public const TWIG_TEMPLATES_DIR = 'templates';

    /**
     * @Given I have the following :templateName template :code
     */
    public function haveTheFollowingTemplate(string $templateName, string $code): void
    {
        $rootDirectory = rtrim($this->config['default_dir'], DIRECTORY_SEPARATOR);
        $templateRootDirectory = $rootDirectory.DIRECTORY_SEPARATOR.self::TWIG_TEMPLATES_DIR;
        if (!file_exists($templateRootDirectory)) {
            mkdir($templateRootDirectory);
        }

        file_put_contents(
            $templateRootDirectory.DIRECTORY_SEPARATOR.$templateName,
            $code
        );
    }

    /**
     * @Given the :templateName template is compiled in the :cacheDirectory directory
     */
    public function haveTheTemplateCompiled(string $templateName, string $cacheDirectory): void
    {
        $rootDirectory = rtrim($this->config['default_dir'], DIRECTORY_SEPARATOR);
        $cacheDirectory = $rootDirectory.DIRECTORY_SEPARATOR.ltrim($cacheDirectory, DIRECTORY_SEPARATOR);
        if (!file_exists($cacheDirectory)) {
            mkdir($cacheDirectory, 0755, true);
        }

        $twigEnvironment = self::getEnvironment($rootDirectory, $cacheDirectory);
        $twigEnvironment->load($templateName);
    }

    /**
     * @Given I have the :package package satisfying the :versionConstraint
     */
    public function haveADependencySatisfied(string $package, string $versionConstraint): void
    {
        $parser = new VersionParser();
        /** @psalm-suppress UndefinedClass Composer\InstalledVersions is undefined when using Composer 1.x */
        /** @psalm-suppress DeprecatedClass PackageVersions\Versions is used for Composer 1.x compatibility */
        if (class_exists(InstalledVersions::class)) {
            /** @var bool $isSatisfied */
            $isSatisfied = InstalledVersions::satisfies($parser, $package, $versionConstraint);
        } elseif (class_exists(Versions::class)) {
            $version = (string) Versions::getVersion($package);

            if (false === strpos($version, '@')) {
                throw new RuntimeException('$version must contain @');
            }

            $isSatisfied = $parser->parseConstraints(explode('@', $version)[0])
                ->matches($parser->parseConstraints($versionConstraint));
        }

        if (!isset($isSatisfied) || !$isSatisfied) {
            throw new SkippedTestError("This scenario requires $package to match $versionConstraint");
        }
    }

    private static function getEnvironment(string $rootDirectory, string $cacheDirectory): Environment
    {
        if (!file_exists($rootDirectory.DIRECTORY_SEPARATOR.self::TWIG_TEMPLATES_DIR)) {
            mkdir($rootDirectory.DIRECTORY_SEPARATOR.self::TWIG_TEMPLATES_DIR);
        }

        $loader = new FilesystemLoader(self::TWIG_TEMPLATES_DIR, $rootDirectory);

        if (!is_dir($cacheDirectory)) {
            throw new InvalidArgumentException(sprintf('The %s twig cache directory does not exist or is not readable.', $cacheDirectory));
        }
        $cache = new FilesystemCache($cacheDirectory);

        $twigEnvironment = new Environment($loader, [
            'cache' => $cache,
            'auto_reload' => true,
            'debug' => true,
            'optimizations' => 0,
            'strict_variables' => false,
        ]);

        // The following is a trick to have a different twig cache hash everytime, preventing collisions from one test to another :
        // the extension construction has to be evaled so the class name will change each time,
        // making the env calculate a different Twig\Environment::$optionHash (which is partly based on the extension names).
        /** @var AbstractExtension $ext */
        $ext = eval('use Twig\Extension\AbstractExtension; return new class() extends AbstractExtension {};');
        $twigEnvironment->addExtension($ext);

        return $twigEnvironment;
    }
}
