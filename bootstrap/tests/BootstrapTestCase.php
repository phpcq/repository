<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest;

use Phpcq\BootstrapTest\Test\BuildConfigBuilder;
use Phpcq\BootstrapTest\Test\PharTaskRunnerBuilder;
use Phpcq\BootstrapTest\Test\ProcessTaskRunnerBuilder;
use Phpcq\PluginApi\Version10\PluginInterface;
use PHPUnit\Framework\TestCase;

abstract class BootstrapTestCase extends TestCase
{
    /**
     * @var PluginInterface[]
     */
    private static $instances;

    /**
     * @var BuildConfigBuilder
     */
    private $buildConfigBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildConfigBuilder = new BuildConfigBuilder($this);
    }

    /**
     * @SuppressWarnings(PHPMD.UndefinedVariable) - See: https://github.com/phpmd/phpmd/issues/714
     */
    public static function getPluginInstance(): PluginInterface
    {
        // As plugins MUST be stateless, it is safe to include it only once.
        $includeFile = realpath(static::getBootstrapFile());
        if (isset(self::$instances[$includeFile])) {
            return self::$instances[$includeFile];
        }

        return self::$instances[$includeFile] = require $includeFile;
    }

    public function testGetName(): void
    {
        $this->assertSame(basename(dirname(static::getBootstrapFile())), $this->getPluginInstance()->getName());
    }

    /**
     * Obtain the absolute path to the bootstrap file.
     *
     * @return string
     */
    abstract protected static function getBootstrapFile(): string;

    protected function projectConfig(): BuildConfigBuilder
    {
        return $this->buildConfigBuilder;
    }

    protected function runProcess(array $command): ProcessTaskRunnerBuilder
    {
        return new ProcessTaskRunnerBuilder($this, $command);
    }

    protected function runPhar(string $pharName, array $arguments): PharTaskRunnerBuilder
    {
        return new PharTaskRunnerBuilder($this, $pharName, $arguments);
    }
}
