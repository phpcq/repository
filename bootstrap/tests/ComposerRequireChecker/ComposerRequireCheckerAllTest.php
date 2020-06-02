<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest\ComposerRequireChecker;

use Phpcq\BootstrapTest\BootstrapTestCase;
use Phpcq\BootstrapTest\Test\BuildConfigBuilder;
use Phpcq\BootstrapTest\ConfigurationPluginTestCaseTrait;
use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\OutputInterface;
use Phpcq\PluginApi\Version10\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\TaskFactoryInterface;
use Phpcq\PluginApi\Version10\TaskRunnerBuilderInterface;
use Phpcq\PluginApi\Version10\TaskRunnerInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;

class ComposerRequireCheckerAllTest extends BootstrapTestCase
{
    use ConfigurationPluginTestCaseTrait;

    protected static function getBootstrapFile(): string
    {
        return __DIR__ . '/../../plugins/composer-require-checker/composer-require-checker-all.php';
    }

    public function configProvider(): array
    {
        return [
            'runs when unconfigured' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('composer-require-checker', ['check'])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT)
                ],
                'plugin-config' => [
                ],
            ],
            'passes path to config-file' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('composer-require-checker', [
                            'check',
                            '--config-file=' . BuildConfigBuilder::PROJECT_ROOT . '/config-file.json',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT)
                ],
                'plugin-config' => [
                    'config_file' => 'config-file.json',
                ],
            ],
            'passes path to composer.json' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('composer-require-checker', [
                            'check',
                            BuildConfigBuilder::PROJECT_ROOT . '/composer.json',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT)
                ],
                'plugin-config' => [
                    'composer_file' => 'composer.json',
                ],
            ],
            'absorbs all options' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('composer-require-checker', [
                            'check',
                            '--config-file=' . BuildConfigBuilder::PROJECT_ROOT . '/config-file.json',
                            BuildConfigBuilder::PROJECT_ROOT . '/composer.json',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT)
                ],
                'plugin-config' => [
                    'config_file' => 'config-file.json',
                    'composer_file' => 'composer.json',
                ],
            ],
        ];
    }

    /**
     * @dataProvider configProvider
     */
    public function testConfigurationIsProcessedCorrectly(array $expected, array $configuration): void
    {
        // Setup the config environment.
        $this
            ->projectConfig()
            ->hasProjectRootPath();

        $this->assertPluginCreatesMatchingTasksForConfiguration($expected, $configuration);
    }

    public function outputTransformProvider(): array
    {
        return [
            [
                'expected' => [
                    [
                        'error',
                        'The composer dependencies have not been installed, run composer install/update first',
                        'composer.json',
                    ],
                ],
                'input'    => <<<EOF
                    In LocateComposerPackageDirectDependenciesSourceFiles.php line 52:

                      The composer dependencies have not been installed, run composer install/upd
                      ate first


                    check [--config-file CONFIG-FILE] [--ignore-parse-errors] [--] [<composer-json>]

                EOF
            ],
            [
                'expected' => [
                    [
                        'info',
                        'There were no unknown symbols found.',
                        'composer.json',
                    ],
                ],
                'input'    => <<<EOF
                ComposerRequireChecker 2.1.0@0c66698d487fcb5c66cf07108e2180c818fb2e72

                There were no unknown symbols found.

                EOF
            ],
            [
                'expected' => [
                    [
                        'error',
                        'Missing dependency "ext-dom" (used symbols: "DOMDocument", "DOMElement", "DOMNode")',
                        'composer.json',
                    ],
                ],
                'input'    => <<<EOF
                ComposerRequireChecker 2.1.0@0c66698d487fcb5c66cf07108e2180c818fb2e72
                The following unknown symbols were found:
                +----------------+--------------------+
                | unknown symbol | guessed dependency |
                +----------------+--------------------+
                | DOMDocument    | ext-dom            |
                | DOMElement     | ext-dom            |
                | DOMNode        | ext-dom            |
                +----------------+--------------------+

                EOF
            ],
            [
                'expected' => [
                    [
                        'error',
                        'Missing dependency "ext-dom" (used symbols: "DOMDocument", "DOMElement", "DOMNode")',
                        'composer.json',
                    ],
                    [
                        'error',
                        'Unknown symbols found: "Foo\Bar\Baz" - is there a dependency missing?',
                        'composer.json',
                    ],
                ],
                'input'    => <<<EOF
                ComposerRequireChecker 2.1.0@0c66698d487fcb5c66cf07108e2180c818fb2e72
                The following unknown symbols were found:
                +----------------+--------------------+
                | unknown symbol | guessed dependency |
                +----------------+--------------------+
                | DOMDocument    | ext-dom            |
                | DOMElement     | ext-dom            |
                | DOMNode        | ext-dom            |
                | Foo\Bar\Baz    |                    |
                +----------------+--------------------+

                EOF
            ],
        ];
    }

    /** @dataProvider outputTransformProvider */
    public function testTransformsOutput(array $expected, string $input): void
    {
        $transformer = $this->mockOutputTransformer([]);

        $report = $this->getMockForAbstractClass(ToolReportInterface::class);
        $report
            ->expects($this->exactly(count($expected)))
            ->method('addDiagnostic')
            ->withConsecutive(...$expected);

        $transformer->attach($report);
        $transformer->write($input, OutputInterface::CHANNEL_STDOUT);
        $transformer->detach(0);
    }

    public function mockOutputTransformer(array $configurationValues): OutputTransformerInterface
    {
        $outputTransformer = null;
        $builder = $this->getMockForAbstractClass(TaskRunnerBuilderInterface::class);
        $builder->expects($this->once())->method('withWorkingDirectory')->willReturnSelf();
        $builder
            ->expects($this->once())
            ->method('withOutputTransformer')
            ->willReturnCallback(function (OutputTransformerInterface $transformer) use (&$outputTransformer, $builder) {
                $outputTransformer = $transformer;

                return $builder;
        });
        $builder->expects($this->once())->method('build')->willReturn($this->getMockForAbstractClass(TaskRunnerInterface::class));

        $taskFactory = $this->getMockForAbstractClass(TaskFactoryInterface::class);
        $taskFactory->expects($this->once())->method('buildRunPhar')->willReturn($builder);

        $buildConfig = $this->getMockForAbstractClass(BuildConfigInterface::class);
        $buildConfig->expects($this->once())->method('getTaskFactory')->willReturn($taskFactory);

        /** @var ConfigurationPluginInterface $instance */
        $instance = static::getPluginInstance();
        $this->assertSame(basename(dirname(static::getBootstrapFile())), $instance->getName());

        // Have to trigger the lazy generator.
        foreach ($instance->processConfig($configurationValues, $buildConfig) as $item) {
            break;
        }

        $this->assertInstanceOf(OutputTransformerInterface::class, $outputTransformer);

        return $outputTransformer;
    }
}
