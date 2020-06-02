<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest\PhpCs;

use Phpcq\BootstrapTest\BootstrapTestCase;
use Phpcq\BootstrapTest\Test\BuildConfigBuilder;
use Phpcq\BootstrapTest\ConfigurationPluginTestCaseTrait;

class PhpCsAllTest extends BootstrapTestCase
{
    use ConfigurationPluginTestCaseTrait;

    protected static function getBootstrapFile(): string
    {
        return __DIR__ . '/../../plugins/phpcs/phpcs-all.php';
    }

    /** @@SuppressWarnings(PHPMD.ExcessiveMethodLength) */
    public function configProvider(): array
    {
        return [
            'runs all directories when unconfigured' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcs', ['--report=checkstyle', '--report-file=checkstyle.xml', 'src'])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                    $this
                        ->runPhar('phpcs', ['--report=checkstyle', '--report-file=checkstyle.xml', 'test'])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => null, 'test' => null],
                ],
            ],
            'passes standard' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcs', [
                            '--standard=PSR2',
                            '--report=checkstyle',
                            '--report-file=checkstyle.xml',
                            'src',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                    $this
                        ->runPhar('phpcs', [
                            '--standard=PSR2',
                            '--report=checkstyle',
                            '--report-file=checkstyle.xml',
                            'test',
                            ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => null, 'test' => null],
                    'standard' => 'PSR2',
                ],
            ],
            'passes excluded' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcs', [
                            '--exclude=excluded1,excluded2',
                            '--report=checkstyle',
                            '--report-file=checkstyle.xml',
                            'src',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                    $this
                        ->runPhar('phpcs', [
                            '--exclude=excluded1,excluded2',
                            '--report=checkstyle',
                            '--report-file=checkstyle.xml',
                            'test',
                            ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => null, 'test' => null],
                    'excluded' => ['excluded1', 'excluded2'],
                ],
            ],
            'passes custom flags' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcs', [
                            '--custom-flag',
                            '--report=checkstyle',
                            '--report-file=checkstyle.xml',
                            'src',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                    $this
                        ->runPhar('phpcs', [
                            '--custom-flag',
                            '--report=checkstyle',
                            '--report-file=checkstyle.xml',
                            'test',
                            ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => null, 'test' => null],
                    'custom_flags' => '--custom-flag',
                ],
            ],
            'absorbs all options' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcs', [
                            '--standard=PSR2',
                            '--exclude=excluded1,excluded2',
                            '--report=checkstyle',
                            '--report-file=checkstyle.xml',
                            'src',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                    $this
                        ->runPhar('phpcs', [
                            '--standard=PSR2',
                            '--exclude=excluded1,excluded2',
                            '--report=checkstyle',
                            '--report-file=checkstyle.xml',
                            'test',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => null, 'test' => null],
                    'standard'    => 'PSR2',
                    'excluded'    => ['excluded1', 'excluded2'],
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
            ->requestsTempFiles('checkstyle.xml', 'checkstyle.xml')
            ->hasProjectRootPath();

        $this->assertPluginCreatesMatchingTasksForConfiguration($expected, $configuration);
    }
}
