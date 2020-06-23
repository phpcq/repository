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
            ->describeStringListOption('excluded', 'The excluded files and folders.')
            ->isRequired()
            ->withDefaultValue([]);
        $configOptionsBuilder
            ->describeStringListOption(
                'custom_flags',
                'Any custom flags to pass to phpcbf. For valid flags refer to the cphpcs documentation.',
            )
            ->isRequired()
            ->withDefaultValue([]);
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): iterable {
        yield $environment
            ->getTaskFactory()
            ->buildRunPhar('phpcbf', $this->buildArguments($config, $environment))
            ->withWorkingDirectory($environment->getProjectConfiguration()->getProjectRootPath())
            ->build();
    }

    private function buildArguments(PluginConfigurationInterface $config, EnvironmentInterface $environment): array
    {
        $arguments   = [];
        $arguments[] = '--standard=' . $config->getString('standard');

        if ([] !== ($excluded = $config->getStringList('excluded'))) {
            $arguments[] = '--exclude=' . implode(',', $excluded);
        }

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $arguments[] = $value;
            }
        }

        $arguments[] = '--parallel=' . $environment->getProjectConfiguration()->getMaxCpuCores();

        return array_merge($arguments, $config->getStringList('directories'));
    }
};
