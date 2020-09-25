<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest\PhpCbf;

use Phpcq\BootstrapTest\BootstrapTestCase;
use Phpcq\BootstrapTest\Test\BuildConfigBuilder;
use Phpcq\BootstrapTest\ConfigurationPluginTestCaseTrait;

class PhpCbfAllTest extends BootstrapTestCase
{
    use ConfigurationPluginTestCaseTrait;

    protected static function getBootstrapFile(): string
    {
        return __DIR__ . '/../../plugins/phpcbf/phpcbf-all.php';
    }

    /** @@SuppressWarnings(PHPMD.ExcessiveMethodLength) */
    public function configProvider(): array
    {
        return [
            'runs all directories when unconfigured' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcbf', [
                            '--parallel=0',
                            'src',
                            'test',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src', 'test'],
                ],
            ],
            'passes standard' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcbf', [
                            '--standard=PSR2',
                            '--parallel=0',
                            'src',
                            'test',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src', 'test'],
                    'standard' => 'PSR2',
                ],
            ],
            'passes excluded' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcbf', [
                            '--exclude=excluded1,excluded2',
                            '--parallel=0',
                            'src',
                            'test',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src', 'test'],
                    'excluded' => ['excluded1', 'excluded2'],
                ],
            ],
            'passes custom flags' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcbf', [
                            '--custom-flag',
                            '--parallel=0',
                            'src',
                            'test',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src', 'test'],
                    'custom_flags' => ['--custom-flag'],
                ],
            ],
            'absorbs all options' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcbf', [
                            '--standard=PSR2',
                            '--exclude=excluded1,excluded2',
                            '--parallel=0',
                            'src',
                            'test',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src', 'test'],
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
            ->hasProjectRootPath();

        $this->assertPluginCreatesMatchingTasksForConfiguration($expected, $configuration);
    }
}
