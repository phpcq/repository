<?php

/**
 * Tool home: https://github.com/phpmd/phpmd
 */

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\OutputTransformerInterface;
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

        $configOptionsBuilder->describeArrayOption(
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
        if ([] !== ($values = $config['custom_flags'] ?? [])) {
            foreach ($values as $value) {
                $args[] = (string) $value;
            }
        }

        $xmlfile = $xmlfile = $buildConfig->getUniqueTempFile($this, 'xml');
        $args[] = '--report-file';
        $args[] = $xmlfile;

        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('phpmd', $args)
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->withOutputTransformer(
                $this->createOutputTransformer($xmlfile, $buildConfig->getProjectConfiguration()->getProjectRootPath())
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

    private function createOutputTransformer(string $xmlFile, string $rootDir): OutputTransformerFactoryInterface
    {
        return new class ($xmlFile, $rootDir) implements OutputTransformerFactoryInterface {
            private $xmlFile;
            private $rootDir;

            public function __construct(string $xmlFile, string $rootDir)
            {
                $this->xmlFile = $xmlFile;
                $this->rootDir = $rootDir;
            }

            public function createFor(ToolReportInterface $report): OutputTransformerInterface
            {
                return new class ($this->xmlFile, $this->rootDir, $report) implements OutputTransformerInterface {
                    /** @var string */
                    private $xmlFile;
                    /** @var string */
                    private $rootDir;
                    /** @var ToolReportInterface */
                    private $report;

                    public function __construct(string $xmlFile, string $rootDir, ToolReportInterface $report)
                    {
                        $this->xmlFile = $xmlFile;
                        $this->rootDir = $rootDir;
                        $this->report  = $report;
                    }


                    public function write(string $data, int $channel): void
                    {
                        // FIXME: do we also want to parse stdout/stderr?
                    }

                    public function finish(int $exitCode): void
                    {
                        $xmlDocument = new DOMDocument('1.0');
                        $xmlDocument->load($this->xmlFile);
                        $rootNode = $xmlDocument->firstChild;

                        if (!$rootNode instanceof DOMNode) {
                            $this->report->finish(
                                $exitCode === 0 ? ReportInterface::STATUS_PASSED : ReportInterface::STATUS_FAILED
                            );
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

                                $this->report
                                    // FIXME: can we use attr "priority" (int) for severity?
                                    ->addDiagnostic('error', $message)
                                        ->forFile($fileName)
                                            ->forRange($this->getIntXmlAttribute($violationNode, 'beginline'))
                                            ->end()
                                        ->fromSource($this->getXmlAttribute($violationNode, 'rule'))
                                    ->end();
                            }
                        }

                        $this->report->finish($exitCode === 0 ? ReportInterface::STATUS_PASSED : ReportInterface::STATUS_FAILED);
                    }

                    /**
                     * @param mixed $defaultValue
                     */
                    private function getXmlAttribute(
                        DOMElement $element,
                        string $attribute,
                        ?string $defaultValue = null
                    ): ?string {
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
    }
};
