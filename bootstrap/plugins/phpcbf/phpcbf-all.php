<?php

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;

return new class implements DiagnosticsPluginInterface {
    public function getName(): string
    {
        return 'phpcbf';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->supportDirectories();
        $configOptionsBuilder
            ->describeStringOption('standard', 'The default code style')
            ->isRequired()
            ->withDefaultValue('PSR12');
        $configOptionsBuilder
            ->describeListOption('excluded', 'The excluded files and folders.')
            ->ofStringItems()
            ->withDefaultValue([]);
        $configOptionsBuilder
            ->describeListOption(
                'custom_flags',
                'Any custom flags to pass to phpcbf. For valid flags refer to the cphpcs documentation.',
            )
            ->ofStringItems()
            ->withDefaultValue([]);
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $buildConfig
    ): iterable {
        foreach ($config->getStringList('directories') as $directory) {
            yield $buildConfig
                ->getTaskFactory()
                ->buildRunPhar('phpcbf', $this->buildArguments($directory, $config))
                ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
                ->build();
        }
    }

    private function buildArguments(string $directory, PluginConfigurationInterface $config): array
    {
        $arguments = [];
        $arguments[] = '--standard=' . $config->getString('standard');

        if ($config->has('excluded')) {
            $arguments[] = '--exclude=' . implode(',', $config->getStringList('excluded'));
        }

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $arguments[] = $value;
            }
        }

        $arguments[] = $directory;

        return $arguments;
    }
};
