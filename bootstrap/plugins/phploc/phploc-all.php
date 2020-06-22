<?php

/**
 * Tool home: https://github.com/sebastianbergmann/phploc
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
        return 'phploc';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->supportDirectories();
        $configOptionsBuilder
            ->describeStringListOption(
                'excluded',
                'List of excluded files.'
            )
            ->withDefaultValue([])
            ->isRequired()
            ->withNormalizer(static function ($value) { return trim($value); });
        $configOptionsBuilder
            ->describeStringListOption(
                'custom_flags',
                'Any custom flags to pass to phploc. For valid flags refer to the phploc documentation.'
            )
            ->withDefaultValue([])
            ->isRequired();
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $buildConfig
    ): iterable {
        $directories = $config->getStringList('directories');

        $args = [
            '--log-xml',
            $logFile = $buildConfig->getUniqueTempFile($this, 'log.xml')
        ];
        if ($config->has('excluded')) {
            foreach ($config->getStringList('excluded') as $path) {
                $args[] = '--exclude=' . $path;
            }
        }

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $args[] = $value;
            }
        }

        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('phploc', array_merge($args, $directories))
            ->withOutputTransformer($this->createOutputTransformer($logFile))
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->build();
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
                                $exitCode === 0
                                    ? ToolReportInterface::STATUS_PASSED
                                    : ToolReportInterface::STATUS_FAILED
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
                                ? ToolReportInterface::STATUS_PASSED
                                : ToolReportInterface::STATUS_FAILED
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
