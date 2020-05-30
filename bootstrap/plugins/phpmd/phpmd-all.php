<?php

/**
 * Tool home: https://github.com/phpmd/phpmd
 */

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\OutputInterface;
use Phpcq\PluginApi\Version10\PostProcessorInterface;
use Phpcq\PluginApi\Version10\ReportInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;

return new class implements ConfigurationPluginInterface {
    public function getName(): string
    {
        return 'phpmd';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->describeArrayOption(
            'ruleset',
            'List of rulesets (cleancode, codesize, controversial, design, naming, unusedcode).',
            [
                'naming',
                'unusedcode'
            ]
        );

        $configOptionsBuilder->describeStringOption(
            'custom_flags',
            'Any custom flags to pass to phpmd.'
        );

        $configOptionsBuilder->describeArrayOption(
            'directories',
            'Source directories to be analyzed with phpmd.'
        );
    }


    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        [$should, $excluded] = $this->processDirectories($config['directories']);

        $flags = ['ruleset' => 'naming,unusedcode'];

        foreach ($flags as $key => $value) {
            if ('' !== ($value = $this->commaValues($config, $key))) {
                $flags[$key] = $value;
            }
        }

        $args = [
            implode(',', $should),
            'xml',
            $flags['ruleset'],
        ];

        if ([] !== $excluded) {
            $exclude = [];
            foreach ($excluded as $path) {
                if ('' === ($path = trim($path))) {
                    continue;
                }
                $exclude[] = $path;
            }
            $args[] = '--exclude=' . implode(',', $exclude);
        }
        if ('' !== ($values = $config['custom_flags'] ?? '')) {
            $args[] = $values;
        }

        $tmpfile = $tmpfile = $buildConfig->getUniqueTempFile($this);
        $args[] = '--report-file';
        $args[] = $tmpfile;

        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('phpmd', $args)
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->withPostProcessor(
                $this->createPostProcessor($tmpfile, $buildConfig->getProjectConfiguration()->getProjectRootPath())
            )
            ->build();
    }

    /**
     * Process the directory list.
     *
     * @param array $directories The directory list.
     *
     * @return array
     */
    private function processDirectories(array $directories): array
    {
        $should  = [];
        $exclude = [];
        foreach ($directories as $directory => $dirConfig) {
            $should[] = $directory;
            if (null !== $dirConfig) {
                if (isset($dirConfig['excluded'])) {
                    foreach ($dirConfig['excluded'] as $excl) {
                        $exclude[] = $directory . '/' . $excl;
                    }
                }
            }
        }
        return [$should, $exclude];
    }

    private function commaValues(array $config, string $key): string
    {
        if (!isset($config[$key])) {
            return '';
        }
        return implode(',', (array) $config[$key]);
    }

    private function createPostProcessor(string $xmlFile, string $rootDir): PostProcessorInterface
    {
        return new class($xmlFile, $rootDir) implements PostProcessorInterface {
            private $xmlFile;
            private $rootDir;

            public function __construct(string $xmlFile, string $rootDir)
            {
                $this->xmlFile = $xmlFile;
                $this->rootDir = $rootDir;
            }

            public function process(ToolReportInterface $report, string $consoleOutput, int $exitCode, OutputInterface $output): void
            {
                $xmlDocument = new DOMDocument('1.0');
                $xmlDocument->load($this->xmlFile);
                $rootNode = $xmlDocument->firstChild;

                if (!$rootNode instanceof DOMNode) {
                    $report->finish($exitCode === 0 ? ReportInterface::STATUS_PASSED : ReportInterface::STATUS_FAILED);
                    return;
                }

                foreach ($rootNode->childNodes as $childNode) {
                    if (!$childNode instanceof DOMElement || $childNode->nodeName !== 'file') {
                        continue;
                    }

                    $fileName = $childNode->getAttribute('name');
                    if (strpos($fileName, $this->rootDir) === 0) {
                        $fileName = substr($fileName, strlen($this->rootDir) + 1);
                    }

                    foreach ($childNode->childNodes as $violationNode) {
                        if (!$violationNode instanceof DOMElement) {
                            continue;
                        }

                        $message = sprintf(
                            '%s%s(Ruleset: %s, %s)',
                            trim($violationNode->textContent),
                            "\n",
                            $this->getXmlAttribute($violationNode, 'ruleset', ''),
                            $this->getXmlAttribute($violationNode, 'externalInfoUrl', '')
                        );

                        $report->addError(
                            'error', // FIXME: can we use attr "priority" (int) for severity?
                            $message,
                            $fileName,
                            $this->getIntXmlAttribute($violationNode, 'beginline'),
                            null,
                            $this->getXmlAttribute($violationNode, 'rule'),
                        );
                    }
                }
                $report->finish($exitCode === 0 ? ReportInterface::STATUS_PASSED : ReportInterface::STATUS_FAILED);
            }

            /**
             * @param mixed $defaultValue
             */
            private function getXmlAttribute(DOMElement $element, string $attribute, ?string $defaultValue = null): ?string
            {
                if ($element->hasAttribute($attribute)) {
                    return $element->getAttribute($attribute);
                }

                return $defaultValue;
            }

            private function getIntXmlAttribute(DOMElement $element, string $attribute): ?int
            {
                $value = $this->getXmlAttribute($element, $attribute);
                if ($value === null) {
                    return null;
                }

                return (int) $value;
            }
        };
    }
};
