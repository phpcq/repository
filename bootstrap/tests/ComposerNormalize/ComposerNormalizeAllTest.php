<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest\ComposerNormalize;

use Phpcq\BootstrapTest\BootstrapTestCase;
use Phpcq\BootstrapTest\Test\BuildConfigBuilder;
use Phpcq\BootstrapTest\ConfigurationPluginTestCaseTrait;
use Phpcq\PluginApi\Version10\OutputInterface;
use Phpcq\PluginApi\Version10\Report\DiagnosticBuilderInterface;
use Phpcq\PluginApi\Version10\Report\FileDiagnosticBuilderInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;

class ComposerNormalizeAllTest extends BootstrapTestCase
{
    use ConfigurationPluginTestCaseTrait;

    protected static function getBootstrapFile(): string
    {
        return __DIR__ . '/../../plugins/composer-normalize/composer-normalize-all.php';
    }

    public function configProvider(): array
    {
        return [
            'runs as dry run when unconfigured' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('composer-normalize', ['--dry-run'])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT)
                ],
                'plugin-config' => [
                ],
            ],
            'passes path to composer.json' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('composer-normalize', ['/composer.json'])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT)
                ],
                'plugin-config' => [
                    'file' => '/composer.json',
                    'dry_run' => false,
                ],
            ],
            'absorbs all options' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('composer-normalize', [
                            '/composer.json',
                            '--dry-run',
                            '--indent-size',
                            '2',
                            '--indent-style',
                            'space',
                            '--no-update-lock',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT)
                ],
                'plugin-config' => [
                    'file' => '/composer.json',
                    'dry_run' => true,
                    'indent_size' => '2',
                    'indent_style' => 'space',
                    'no_update_lock' => true,
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
            'Schema errors' => [
                'status'   => ToolReportInterface::STATUS_FAILED,
                'expected' => [
                    [
                        'severity' => 'error',
                        'message'  => 'authors[0] : The property foo is not defined and the definition does not ' .
                            'allow additional properties',
                        'forFile'  => 'composer.json',
                    ],
                ],
                'input-stdout' => '',
                'input-stderr'    => "\nIn Application.php line 377:\n" .
                    "                                                                               \n" .
                    "  \"./composer.json\" does not match the expected JSON schema:                   \n" .
                    "   - authors[0] : The property foo is not defined and the definition does not  \n" .
                    "   allow additional properties                                                 \n" .
                    "                                                                               \n\n",
            ],
            'File is not writable' => [
                'status'   => ToolReportInterface::STATUS_FAILED,
                'expected' => [
                    [
                        'severity' => 'error',
                        'message'  => 'composer.json is not writable.',
                        'forFile'  => 'composer.json',
                    ],
                ],
                'input-stdout' => '',
                'input-stderr'    => "composer.json is not writable.\n",
            ],
            'Lock file is not up to date' => [
                'status'   => ToolReportInterface::STATUS_FAILED,
                'expected' => [
                    [
                        'severity' => 'error',
                        'message'  => 'The lock file is not up to date with the latest changes in composer.json, ' .
                            'it is recommended that you run `composer update --lock`.',
                        'forFile'  => 'composer.json',
                    ],
                ],
                'input-stdout' => '',
                'input-stderr'    => "The lock file is not up to date with the latest changes in composer.json, it is" .
                    " recommended that you run `composer update --lock`.\n",
            ],
            'Original composer.json violates JSON schema' => [
                'status'   => ToolReportInterface::STATUS_FAILED,
                'expected' => [
                    [
                        'severity' => 'error',
                        'message'  => 'fake message 1.',
                        'forFile'  => 'composer.json',
                    ],
                ],
                'input-stdout' => '',
                'input-stderr'    => "Original composer.json does not match the expected JSON schema:\n" .
                    "- fake message 1.\n\n" .
                    "See https://getcomposer.org/doc/04-schema.md for details on the schema\n",
            ],
        ];
    }

    /** @dataProvider outputTransformProvider */
    public function testTransformsOutput(string $expectedStatus, array $expected, string $stdOut, string $stdErr): void
    {
        $report      = $this->getMockForAbstractClass(ToolReportInterface::class);
        $transformer = $this->mockOutputTransformer([], $report);

        $report->expects($this->once())->method('finish')->with($expectedStatus);

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

        if ('' !== $stdOut) {
            $transformer->write($stdOut, OutputInterface::CHANNEL_STDOUT);
        }
        if ('' !== $stdErr) {
            $transformer->write($stdErr, OutputInterface::CHANNEL_STDERR);
        }
        $transformer->finish($expectedStatus === ToolReportInterface::STATUS_PASSED ? 0 : 1);
    }
}
