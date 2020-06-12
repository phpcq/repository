<?php

/**
 * Tool home: https://github.com/sebastianbergmann/phploc
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
        return 'phploc';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->describeArrayOption('output', 'List of outputs to use.');

        $configOptionsBuilder->describeArrayOption(
            'custom_flags',
            'Any custom flags to pass to phploc. For valid flags refer to the phploc documentation.'
        );

        $configOptionsBuilder->describeArrayOption('directories', 'Source directories to be analyzed with phploc.');
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        [$should, $excluded] = $this->processDirectories($config['directories']);
        $args = [
            '--log-xml',
            $logFile = $buildConfig->getUniqueTempFile($this, 'log.xml')
        ];
        if ([] !== $excluded) {
            foreach ($excluded as $path) {
                if ('' === ($path = trim($path))) {
                    continue;
                }
                $args[] = '--exclude=' . $path;
            }
        }

        if ([] !== ($values = $config['custom_flags'] ?? [])) {
            foreach ($values as $value) {
                $args[] = (string) $value;
            }
        }

        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('phploc', array_merge($args, $should))
            ->withOutputTransformer($this->createOutputTransformer($logFile))
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
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

    private function createOutputTransformer(string $xmlFile): OutputTransformerFactoryInterface
    {
        return new class ($xmlFile) implements OutputTransformerFactoryInterface {
            private $xmlFile;

            public function __construct(string $xmlFile)
            {
                $this->xmlFile = $xmlFile;
            }

            public function createFor(ToolReportInterface $report): OutputTransformerInterface
            {
                return new class ($this->xmlFile, $report) implements OutputTransformerInterface {
                    private const DICTIONARY = [
                        // LIST copied from https://github.com/sebastianbergmann/phploc/blob/master/src/Log/Csv.php
                        'directories'                 => 'Directories',
                        'files'                       => 'Files',
                        'loc'                         => 'Lines of Code (LOC)',
                        'ccnByLloc'                   => 'Cyclomatic Complexity / Lines of Code',
                        'cloc'                        => 'Comment Lines of Code (CLOC)',
                        'ncloc'                       => 'Non-Comment Lines of Code (NCLOC)',
                        'lloc'                        => 'Logical Lines of Code (LLOC)',
                        'llocGlobal'                  => 'LLOC outside functions or classes',
                        'namespaces'                  => 'Namespaces',
                        'interfaces'                  => 'Interfaces',
                        'traits'                      => 'Traits',
                        'classes'                     => 'Classes',
                        'abstractClasses'             => 'Abstract Classes',
                        'concreteClasses'             => 'Concrete Classes',
                        'finalClasses'                => 'Final Classes',
                        'nonFinalClasses'             => 'Non-Final Classes',
                        'llocClasses'                 => 'Classes Length (LLOC)',
                        'methods'                     => 'Methods',
                        'nonStaticMethods'            => 'Non-Static Methods',
                        'staticMethods'               => 'Static Methods',
                        'publicMethods'               => 'Public Methods',
                        'nonPublicMethods'            => 'Non-Public Methods',
                        'protectedMethods'            => 'Protected Methods',
                        'privateMethods'              => 'Private Methods',
                        'classCcnAvg'                 => 'Cyclomatic Complexity / Number of Classes',
                        'methodCcnAvg'                => 'Cyclomatic Complexity / Number of Methods',
                        'functions'                   => 'Functions',
                        'namedFunctions'              => 'Named Functions',
                        'anonymousFunctions'          => 'Anonymous Functions',
                        'llocFunctions'               => 'Functions Length (LLOC)',
                        'llocByNof'                   => 'Average Function Length (LLOC)',
                        'classLlocAvg'                => 'Average Class Length',
                        'methodLlocAvg'               => 'Average Method Length',
                        'averageMethodsPerClass'      => 'Average Methods per Class',
                        'constants'                   => 'Constants',
                        'globalConstants'             => 'Global Constants',
                        'classConstants'              => 'Class Constants',
                        'publicClassConstants'        => 'Public Class Constants',
                        'nonPublicClassConstants'     => 'Non-Public Class Constants',
                        'attributeAccesses'           => 'Attribute Accesses',
                        'instanceAttributeAccesses'   => 'Non-Static Attribute Accesses',
                        'staticAttributeAccesses'     => 'Static Attribute Accesses',
                        'methodCalls'                 => 'Method Calls',
                        'instanceMethodCalls'         => 'Non-Static Method Calls',
                        'staticMethodCalls'           => 'Static Method Calls',
                        'globalAccesses'              => 'Global Accesses',
                        'globalVariableAccesses'      => 'Global Variable Accesses',
                        'superGlobalVariableAccesses' => 'Super-Global Variable Accesses',
                        'globalConstantAccesses'      => 'Global Constant Accesses',
                        'testClasses'                 => 'Test Classes',
                        'testMethods'                 => 'Test Methods',
                        // Custom added words
                        'ccn'                         => 'Cyclomatic Complexity',
                    ];

                    /** @var string */
                    private $xmlFile;
                    /** @var ToolReportInterface */
                    private $report;

                    public function __construct(string $xmlFile, ToolReportInterface $report)
                    {
                        $this->xmlFile = $xmlFile;
                        $this->report  = $report;
                    }

                    public function write(string $data, int $channel): void
                    {
                    }

                    public function finish(int $exitCode): void
                    {
                        $xmlDocument = new DOMDocument('1.0');
                        $xmlDocument->load($this->xmlFile);
                        $rootNode = $xmlDocument->firstChild;

                        if (!$rootNode instanceof DOMNode) {
                            $this->report->close(
                                $exitCode === 0 ? ReportInterface::STATUS_PASSED : ReportInterface::STATUS_FAILED
                            );
                            return;
                        }

                        foreach ($rootNode->childNodes as $childNode) {
                            if (!$childNode instanceof DOMElement) {
                                continue;
                            }
                            $this->report
                                ->addDiagnostic(
                                    ToolReportInterface::SEVERITY_INFO,
                                    sprintf('%s: %s', $this->createLabel($childNode->nodeName), $childNode->textContent)
                                )
                                ->fromSource($childNode->nodeName)
                                ->withCategory('statistics');
                        }

                        $this->report->addAttachment('log.xml')
                            ->fromFile($this->xmlFile)
                            ->setMimeType('application/xml')
                            ->end();

                        $this->report->close(
                            $exitCode === 0
                                ? ReportInterface::STATUS_PASSED
                                : ReportInterface::STATUS_FAILED
                        );
                    }

                    private function createLabel(string $text): string
                    {
                        if (null !== ($translated = $this->translateWord($text))) {
                            return $translated;
                        }

                        return ucfirst(
                            implode(
                                ' ',
                                array_map(
                                    function (string $word): string {
                                        return $this->translateWord(strtolower($word)) ?: $word;
                                    },
                                    preg_split('/(?=[A-Z])/', $text)
                                )
                            )
                        );
                    }

                    private function translateWord(string $text): ?string
                    {
                        return self::DICTIONARY[$text] ?? null;
                    }
                };
            }
        };
    }
};
