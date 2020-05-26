<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest\ComposerNormalize;

use Phpcq\BootstrapTest\BootstrapTestCase;
use Phpcq\BootstrapTest\Test\BuildConfigBuilder;
use Phpcq\BootstrapTest\ConfigurationPluginTestCaseTrait;

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
}
