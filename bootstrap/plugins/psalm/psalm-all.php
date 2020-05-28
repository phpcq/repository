<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;

return new class implements ConfigurationPluginInterface {
    public function getName(): string
    {
        return 'psalm';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder
            ->describeBoolOption('debug', 'Show debug information.')
            ->describeBoolOption('debug_by_line', 'Debug information on a line-by-line level')
            ->describeBoolOption('shepherd', 'Send data to Shepherd, Psalm\'s GitHub integration tool.')
            ->describeStringOption('shepherd_host', 'Override shepherd host');

        $configOptionsBuilder->describeStringOption(
            'custom_flags',
            'Any custom flags to pass to phpunit. For valid flags refer to the phpunit documentation.',
        );
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        // Fixme: We need a proper way to create temp files
        $tmpfile = $buildConfig->getBuildTempDir() . '/psalm.checkstyle.xml';

        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('psalm', $this->buildArguments($config, $tmpfile))
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->withCheckstyleFilePostProcessor($tmpfile)
            ->build();
    }

    private function buildArguments(array $config, string $tempFile): array
    {
        $arguments = [];

        foreach (['debug', 'debug_by_line'] as $flag) {
            if (isset($config[$flag])) {
                $arguments[] = '--' .  str_replace('_', '-', $flag);
            }
        }

        if (isset($config['shepherd'])) {
            if (isset($config['shepherd_host'])) {
                $arguments[] = '--shepherd=' . $config['shepherd_host'];
            } else {
                $arguments[] = '--shepherd';
            }
        }

        if ('' !== ($values = $config['custom_flags'] ?? '')) {
            $arguments[] = $values;
        }

        $arguments[] = '--report=' . $tempFile;

        return $arguments;
    }
};
