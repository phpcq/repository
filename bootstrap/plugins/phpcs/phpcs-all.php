<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\Util\CheckstyleReportAppender;

return new class implements ConfigurationPluginInterface {
    public function getName(): string
    {
        return 'phpcs';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
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

    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        $projectRoot = $buildConfig->getProjectConfiguration()->getProjectRootPath();
        foreach ($config['directories'] as $directory => $directoryConfig) {
            $tmpfile = $buildConfig->getUniqueTempFile($this, 'checkstyle.xml');

            yield $buildConfig
                ->getTaskFactory()
                ->buildRunPhar('phpcs', $this->buildArguments($directory, $directoryConfig ?: $config, $tmpfile))
                ->withWorkingDirectory($projectRoot)
                ->withOutputTransformer(CheckstyleReportAppender::transform($tmpfile, $projectRoot))
                ->build();
        }
    }

    private function buildArguments(
        string $directory,
        array $config,
        string $tempFile
    ): array {
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

        $arguments[] = '--report=checkstyle';
        $arguments[] = '--report-file=' . $tempFile;

        $arguments[] = $directory;

        return $arguments;
    }
};
