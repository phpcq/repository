<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest\PhpCpd;

use Phpcq\BootstrapTest\BootstrapTestCase;
use Phpcq\BootstrapTest\ConfigurationPluginTestCaseTrait;
use Phpcq\BootstrapTest\Test\BuildConfigBuilder;

final class PhpCpdAllTest extends BootstrapTestCase
{
    use ConfigurationPluginTestCaseTrait;

    protected static function getBootstrapFile(): string
    {
        return __DIR__ . '/../../plugins/phpcpd/phpcpd-all.php';
    }

    /** @@SuppressWarnings(PHPMD.ExcessiveMethodLength) */
    public function configProvider(): array
    {
        return [
            'runs all directories when unconfigured' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcpd', ['--log-pmd', 'log-pmd.xml', 'src', 'test'])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => null, 'test' => null],
                ],
            ],
            'passes names' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--names=foo.php,bar.php',
                            'src',
                            'test'
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => null, 'test' => null],
                    'names' => ['foo.php', 'bar.php']
                ],
            ],
            'passes regexp exclude' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--regexps-exclude',
                            '#foo.*#',
                            'src',
                            'test'
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => null, 'test' => null],
                    'regexps_exclude' => ['#foo.*#']
                ],
            ],
            'passes min lines' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--min-lines=5',
                            'src',
                            'test'
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => null, 'test' => null],
                    'min_lines' => 5
                ],
            ],
            'passes min tokens' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--min-tokens=5',
                            'src',
                            'test'
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => null, 'test' => null],
                    'min_tokens' => 5
                ],
            ],
            'passes fuzzy' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--fuzzy',
                            'src',
                            'test'
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => null, 'test' => null],
                    'fuzzy' => true
                ],
            ],
            'applies directory config' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--fuzzy',
                            'test'
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                    $this
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--names=bar.php',
                            'src'
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src' => ['names' => 'bar.php'], 'test' => null],
                    'fuzzy' => true
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
            ->requestsTempFiles('log-pmd.xml', 'log-pmd.xml')
            ->hasProjectRootPath();

        $this->assertPluginCreatesMatchingTasksForConfiguration($expected, $configuration);
    }
}
