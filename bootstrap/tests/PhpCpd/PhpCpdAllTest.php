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
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--min-lines=5',
                            '--min-tokens=70',
                            'src',
                            'test',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src', 'test'],
                ],
            ],
            'passes names' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--names=foo.php,bar.php',
                            '--min-lines=5',
                            '--min-tokens=70',
                            'src',
                            'test',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src', 'test'],
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
                            '--min-lines=5',
                            '--min-tokens=70',
                            'src',
                            'test',
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src', 'test'],
                    'regexps_exclude' => ['#foo.*#']
                ],
            ],
            'passes min lines' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--min-lines=1',
                            '--min-tokens=70',
                            'src',
                            'test'
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src', 'test'],
                    'min_lines' => 1
                ],
            ],
            'passes min tokens' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--min-lines=5',
                            '--min-tokens=5',
                            'src',
                            'test'
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src', 'test'],
                    'min_tokens' => 5
                ],
            ],
            'passes fuzzy' => [
                'expected-tasks' => [
                    $this
                        ->runPhar('phpcpd', [
                            '--log-pmd',
                            'log-pmd.xml',
                            '--min-lines=5',
                            '--min-tokens=70',
                            '--fuzzy',
                            'src',
                            'test'
                        ])
                        ->withWorkingDirectory(BuildConfigBuilder::PROJECT_ROOT),
                ],
                'plugin-config' => [
                    'directories' => ['src', 'test'],
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
