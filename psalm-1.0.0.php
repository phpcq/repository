<?php

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Util\CheckstyleReportAppender;

return new class implements DiagnosticsPluginInterface {
    public function getName(): string
    {
        return 'psalm';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder
            ->describeBoolOption('debug', 'Show debug information.')
            ->isRequired()
            ->withDefaultValue(false);
        $configOptionsBuilder
            ->describeBoolOption('debug_by_line', 'Debug information on a line-by-line level')
            ->isRequired()
            ->withDefaultValue(false);
        $configOptionsBuilder
            ->describeBoolOption('shepherd', 'Send data to Shepherd, Psalm\'s GitHub integration tool.')
            ->isRequired()
            ->withDefaultValue(false);
        $configOptionsBuilder
            ->describeStringOption('shepherd_host', 'Override shepherd host');

        $configOptionsBuilder
            ->describeStringListOption(
                'custom_flags',
                'Any custom flags to pass to psalm. For valid flags refer to the psalm documentation.'
            )
            ->withDefaultValue([])
            ->isRequired();
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): iterable {
        $projectRoot = $environment->getProjectConfiguration()->getProjectRootPath();
        $tmpfile     = $environment->getUniqueTempFile($this, 'checkstyle.xml');

        yield $environment
            ->getTaskFactory()
            ->buildRunPhar('psalm', $this->buildArguments($config, $environment, $tmpfile))
            ->withWorkingDirectory($projectRoot)
            ->withOutputTransformer(CheckstyleReportAppender::transformFile($tmpfile, $projectRoot))
            ->build();
    }

    private function buildArguments(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment,
        string $tempFile
    ): array {
        $arguments = [];

        foreach (['debug', 'debug_by_line'] as $flag) {
            if ($config->getBool($flag)) {
                $arguments[] = '--' .  str_replace('_', '-', $flag);
            }
        }

        if ($config->getBool($flag)) {
            if ($config->has('shepherd_host')) {
                $arguments[] = '--shepherd=' . $config->getString('shepherd_host');
            } else {
                $arguments[] = '--shepherd';
            }
        }

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $args[] = $value;
            }
        }

        $arguments[] = '--threads=' . $environment->getProjectConfiguration()->getMaxCpuCores();
        $arguments[] = '--report=' . $tempFile;

        return $arguments;
    }
};
