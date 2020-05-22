<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest\ComposerRequireChecker;

use Phpcq\BootstrapTest\BootstrapTestCase;
use Phpcq\BootstrapTest\Test\BuildConfigBuilder;
use Phpcq\BootstrapTest\ConfigurationPluginTestCaseTrait;

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
}
