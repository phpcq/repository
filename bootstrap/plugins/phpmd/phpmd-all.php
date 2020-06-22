<?php

/**
 * Tool home: https://github.com/phpmd/phpmd
 */

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Report\ToolReportInterface;

return new class implements DiagnosticsPluginInterface {
    public function getName(): string
    {
        return 'phpmd';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->supportDirectories();
        $configOptionsBuilder
            ->describeStringListOption(
                'ruleset',
                'List of rulesets (cleancode, codesize, controversial, design, naming, unusedcode).'
            )
            ->isRequired()
            ->withDefaultValue(['naming', 'unusedcode']);

        $configOptionsBuilder
            ->describeStringListOption(
                'custom_flags',
                'Any custom flags to pass to phpmd. For valid flags refer to the phpmd documentation.'
            )
            ->isRequired()
            ->withDefaultValue([]);
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $buildConfig
    ): iterable {
        $directories = $config->getStringList('directories');

        $args = [
            implode(',', $directories),
            'xml',
            implode(', ', $config->getStringList('ruleset')),
        ];

        if ($config->has('excluded')) {
            foreach ($config->getStringList('excluded') as $path) {
                if ('' === ($path = trim($path))) {
                    continue;
                }
                $args[] = '--exclude=' . $path;
            }
        }

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $args[] = $value;
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
                            $this->report->close(
                                $exitCode === 0
                                    ? ToolReportInterface::STATUS_PASSED
                                    : ToolReportInterface::STATUS_FAILED
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

                                /*
                                 * <violation> may have:
                                 * beginline: starting line of the issue.
                                 * endline:   ending line of the issue.
                                 * rule:      name of the rule.
                                 * ruleset:   name of the ruleset the rule is defined within.
                                 * package:   namespace of the class where the issue is within.
                                 * class:     name of the class where the issue is within.
                                 * method:    name of the method where the issue is within.
                                 * externalInfoUrl: external URL describing the violation.
                                 * priority: The priority for the rule.
                                 *           This can be a value in the range 1-5, where 1 is the highest priority and
                                 *           5 the lowest priority.
                                 */

                                $message = sprintf(
                                    '%s%s(Ruleset: %s, %s)',
                                    trim($violationNode->textContent),
                                    "\n",
                                    (string) $this->getXmlAttribute($violationNode, 'ruleset', ''),
                                    (string) $this->getXmlAttribute($violationNode, 'externalInfoUrl', '')
                                );

                                $severity = ToolReportInterface::SEVERITY_FATAL;
                                if (null !== $prio = $this->getIntXmlAttribute($violationNode, 'priority')) {
                                    // FIXME: Is this mapping correct?
                                    switch ($prio) {
                                        case 1:
                                        case 2:
                                        case 3:
                                            $severity = ToolReportInterface::SEVERITY_MAJOR;
                                            break;
                                        case 4:
                                            $severity = ToolReportInterface::SEVERITY_MINOR;
                                            break;
                                        case 5:
                                        default:
                                            $severity = ToolReportInterface::SEVERITY_INFO;
                                    }
                                }

                                $beginLine = $this->getIntXmlAttribute($violationNode, 'beginline');
                                $endLine   = $this->getIntXmlAttribute($violationNode, 'endline');
                                $this->report->addDiagnostic($severity, $message)
                                    ->forFile($fileName)
                                        ->forRange(
                                            (int) $this->getIntXmlAttribute($violationNode, 'beginline'),
                                            null,
                                            $endLine !== $beginLine ? $endLine : null,
                                        )
                                        ->end()
                                    ->fromSource((string) $this->getXmlAttribute($violationNode, 'rule'))
                                    ->end();
                            }
                        }

                        $this->report->addAttachment('pmd.xml')
                                ->fromFile($this->xmlFile)
                                ->setMimeType('application/xml')
                            ->end();

                        $this->report->close(
                            $exitCode === 0
                                ? ToolReportInterface::STATUS_PASSED
                                : ToolReportInterface::STATUS_FAILED
                        );
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
