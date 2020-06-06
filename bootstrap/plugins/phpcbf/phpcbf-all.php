<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;

return new class implements ConfigurationPluginInterface {
    public function getName(): string
    {
        return 'phpcbf';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder
            ->describeArrayOption('directories', 'The source directories to be fixed with phpcbf.')
            ->describeStringOption('standard', 'The default code style', 'PSR12')
            ->describeArrayOption('excluded', 'The excluded files and folders.', [])
        ;

        $configOptionsBuilder->describeArrayOption(
            'custom_flags',
            'Any custom flags to pass to phpcbf. For valid flags refer to the cphpcs documentation.',
        );
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        foreach ($config['directories'] as $directory => $directoryConfig) {
            yield $buildConfig
                ->getTaskFactory()
                ->buildRunPhar('phpcbf', $this->buildArguments($directory, $directoryConfig ?: $config, $buildConfig))
                ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
                ->build();
        }
    }

    private function buildArguments(string $directory, array $config, BuildConfigInterface $buildConfig): array
    {
        $arguments = [];

        if (isset($config['standard'])) {
            $arguments[] = '--standard=' . $config['standard'];
        }

        if (isset($config['excluded'])) {
            $arguments[] = '--exclude=' . implode(',', $config['excluded']);
        }

        if ([] !== ($values = $config['custom_flags'] ?? [])) {
            foreach ($values as $value) {
                $arguments[] = (string) $value;
            }
        }

        $arguments[] = $directory;

        return $arguments;
    }
};
