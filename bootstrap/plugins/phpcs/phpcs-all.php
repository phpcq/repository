<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;

return new class implements ConfigurationPluginInterface {
    public function getName() : string
    {
        return 'phpcs';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder) : void
    {
        $configOptionsBuilder
            ->describeArrayOption('directories', 'The source directories to be analyzed with phpcs.')
            ->describeStringOption('standard', 'The default coding standard style')
            ->describeArrayOption('excluded', 'The excluded files and folders.', [])
        ;

        $configOptionsBuilder->describeStringOption(
            'custom_flags',
            'Any custom flags to pass to phpcs. For valid flags refer to the cphpcs documentation.',
            );
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig) : iterable
    {
        foreach ($config['directories'] as $directory => $directoryConfig) {
            yield $buildConfig
                ->getTaskFactory()
                ->buildRunPhar('phpcs', $this->buildArguments($directory, $directoryConfig ?: $config, $buildConfig))
                ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
                ->build();
        }
    }

    private function buildArguments(string $directory, array $config, BuildConfigInterface $buildConfig) : array
    {
        $arguments = [];

        if (isset($config['standard'])) {
            $arguments[] = '--standard=' . $config['standard'];
        }

        if (isset($config['excluded'])) {
            $arguments[] = '--exclude=' . implode(',', $config['excluded']);
        }

        if (isset($config['custom_flags'])) {
            $arguments[] = $config['custom_flags'];
        }

        $arguments[] = $directory;

        return $arguments;
    }
};
