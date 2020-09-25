<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest\ComposerRequireChecker;

use Phpcq\BootstrapTest\BootstrapTestCase;
use Phpcq\BootstrapTest\Test\BuildConfigBuilder;
use Phpcq\BootstrapTest\ConfigurationPluginTestCaseTrait;
use Phpcq\PluginApi\Version10\Output\OutputInterface;
use Phpcq\PluginApi\Version10\Report\DiagnosticBuilderInterface;
use Phpcq\PluginApi\Version10\Report\FileDiagnosticBuilderInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;

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
                        ->runPhar('composer-require-checker', [
                            'check',
                            BuildConfigBuilder::PROJECT_ROOT . '/composer.json',
                            ])
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
                            BuildConfigBuilder::PROJECT_ROOT . '/composer.json',
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
                'status'   => TaskReportInterface::STATUS_FAILED,
                'expected' => [
                    [
                        'severity' => TaskReportInterface::SEVERITY_FATAL,
                        'message'  => 'The composer dependencies have not been installed, run composer ' .
                            'install/update first',
                        'forFile'  => 'composer.json',
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
                'status'   => TaskReportInterface::STATUS_PASSED,
                'expected' => [
                    [
                        'severity' => TaskReportInterface::SEVERITY_INFO,
                        'message'  => 'There were no unknown symbols found.',
                        'forFile'  => 'composer.json',
                    ],
                ],
                'input'    => <<<EOF
                ComposerRequireChecker 2.1.0@0c66698d487fcb5c66cf07108e2180c818fb2e72
                There were no unknown symbols found.

                EOF
            ],
            [
                'status'   => TaskReportInterface::STATUS_FAILED,
                'expected' => [
                    [
                        'severity' => TaskReportInterface::SEVERITY_MAJOR,
                        'message'  => 'Missing dependency "ext-dom" (used symbols: "DOMDocument", "DOMElement")',
                        'forFile'  => 'composer.json',
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
                +----------------+--------------------+

                EOF
            ],
            [
                'status'   => TaskReportInterface::STATUS_FAILED,
                'expected' => [
                    [
                        'severity' => TaskReportInterface::SEVERITY_MAJOR,
                        'message'  => 'Missing dependency "ext-dom" (used symbols: "DOMDocument", "DOMElement")',
                        'forFile'  => 'composer.json',
                    ],
                    [
                        'severity' => TaskReportInterface::SEVERITY_FATAL,
                        'message'  => 'Unknown symbols found: "Foo\Bar\Baz" - is there a dependency missing?',
                        'forFile'  => 'composer.json',
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
                | Foo\Bar\Baz    |                    |
                +----------------+--------------------+

                EOF
            ],
        ];
    }

    /** @dataProvider outputTransformProvider */
    public function testTransformsOutput(string $expectedStatus, array $expected, string $input): void
    {
        $report      = $this->getMockForAbstractClass(TaskReportInterface::class);
        $transformer = $this->mockOutputTransformer(['composer_file' => 'composer.json'], $report);

        $report->expects($this->once())->method('close')->with($expectedStatus);

        $expectedParameters = [];
        $expectedReturn     = [];
        foreach ($expected as $expectedValue) {
            $expectedParameters[] = [
                'severity' => $expectedValue['severity'],
                'message'  => $expectedValue['message'],
            ];

            $diagnosticBuilder = $this->getMockForAbstractClass(DiagnosticBuilderInterface::class);
            $diagnosticBuilder->expects($this->once())->method('end')->willReturn($report);

            if (isset($expectedValue['forFile'])) {
                $fileBuilder = $this->getMockForAbstractClass(FileDiagnosticBuilderInterface::class);
                $fileBuilder->expects($this->once())->method('end')->willReturn($diagnosticBuilder);

                $diagnosticBuilder
                    ->expects($this->once())
                    ->method('forFile')
                    ->with($expectedValue['forFile'])
                    ->willReturn($fileBuilder);
            }

            $expectedReturn[] = $diagnosticBuilder;
        }

        $report
            ->expects($this->exactly(count($expectedParameters)))
            ->method('addDiagnostic')
            ->withConsecutive(...$expectedParameters)
            ->willReturnOnConsecutiveCalls(...$expectedReturn);

        $transformer->write($input, OutputInterface::CHANNEL_STDOUT);
        $transformer->finish($expectedStatus === TaskReportInterface::STATUS_PASSED ? 0 : 1);
    }
}
