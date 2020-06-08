<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\OutputInterface;
use Phpcq\PluginApi\Version10\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\ReportInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;
use Phpcq\PluginApi\Version10\Util\BufferedLineReader;

return new class implements ConfigurationPluginInterface {
    public function getName(): string
    {
        return 'composer-normalize';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder
            ->describeBoolOption('dry_run', 'Show the results of normalizing, but do not modify any files', true)
            ->describeStringOption('file', 'Path to composer.json file relative to project root')
            ->describeIntOption(
                'indent_size',
                'Indent size (an integer greater than 0); should be used with the indent_style option',
                2
            )
            ->describeStringOption(
                'indent_style',
                'Indent style (one of "space", "tab"); should be used with the indent_size option',
                'space'
            )
            ->describeBoolOption('no_update_lock', 'Do not update lock file if it exists');
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        $composerJson = $config['file'] ?? 'composer.json';
        assert(is_string($composerJson));

        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('composer-normalize', $this->buildArguments($config))
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->withOutputTransformer($this->createOutputTransformerFactory($composerJson))
            ->build();
    }

    /** @psalm-return list<string> */
    private function buildArguments(array $config): array
    {
        $arguments = [];

        if (isset($config['file'])) {
            $arguments[] = (string) $config['file'];
        }

        if (!isset($config['dry_run']) || $config['dry_run']) {
            $arguments[] = '--dry-run';
        }

        if (isset($config['indent_size'])) {
            $arguments[] = '--indent-size';
            $arguments[] = (string) $config['indent_size'];
        }

        if (isset($config['indent_style'])) {
            $arguments[] = '--indent-style';
            $arguments[] = (string) $config['indent_style'];
        }

        if (isset($config['no_update_lock'])) {
            $arguments[] = '--no-update-lock';
        }

        return $arguments;
    }

    private function createOutputTransformerFactory(string $composerFile): OutputTransformerFactoryInterface
    {
        return new class ($composerFile) implements OutputTransformerFactoryInterface {
            /** @var string */
            private $composerFile;

            public function __construct(string $composerFile)
            {
                $this->composerFile = $composerFile;
            }

            public function createFor(ToolReportInterface $report): OutputTransformerInterface
            {
                return new class ($this->composerFile, $report) implements OutputTransformerInterface {
                    private const REGEX_IN_APPLICATION = '#^In Application\.php line [0-9]*:$#';
                    private const REGEX_NOT_WRITABLE = '#^.* is not writable\.$#';
                    private const REGEX_NOT_NORMALIZED = '#^.* is not normalized\.$#';
                    private const REGEX_IS_NORMALIZED = '#^.* is already normalized\.$#';
                    private const REGEX_XDEBUG_ENABLED = '#^(?<message>You are running composer with Xdebug enabled\.' .
                    ' This has a major impact on runtime performance\. See https://getcomposer.org/xdebug)$#';
                    private const REGEX_LOCK_OUTDATED = '#^(?<message>The lock file is not up to date with the latest' .
                    ' changes in composer\.json, it is recommended that you run `composer update --lock`\.)$#';
                    private const REGEX_SCHEMA_VIOLATION = '#^.* does not match the expected JSON schema:$#';
                    private const REGEX_SKIPPED_COMMAND = '#^(?<message>Plugin command normalize \(Localheinz\\\\' .
                    'Composer\\\\Normalize\\\\Command\\\\NormalizeCommand\) would override a Composer command and has' .
                    ' been skipped)#';

                    /** @var string */
                    private $composerFile;
                    /** @var BufferedLineReader */
                    private $data;
                    /** @var string */
                    private $diff = '';
                    /** @var ToolReportInterface */
                    private $report;

                    public function __construct(string $composerFile, ToolReportInterface $report)
                    {
                        $this->composerFile = $composerFile;
                        $this->report       = $report;
                        $this->data         = BufferedLineReader::create();
                    }

                    public function write(string $data, int $channel): void
                    {
                        if (OutputInterface::CHANNEL_STDOUT === $channel) {
                            // This is the ONLY line that is on output channel instead of error.
                            if (1 === preg_match(self::REGEX_IS_NORMALIZED, $dummy = trim($data))) {
                                $this->logDiagnostic(
                                    $this->composerFile . ' is normalized.',
                                    ToolReportInterface::SEVERITY_INFO
                                );
                                return;
                            }
                            $this->diff .= $data;
                            return;
                        }

                        $this->data->push($data);
                    }

                    public function finish(int $exitCode): void
                    {
                        $this->process();
                        $this->report->close(0 === $exitCode
                            ? ReportInterface::STATUS_PASSED
                            : ReportInterface::STATUS_FAILED);
                    }

                    private function logDiagnostic(string $message, string $severity): void
                    {
                        $this->report->addDiagnostic($severity, $message)->forFile($this->composerFile)->end()->end();
                    }

                    private function process(): void
                    {
                        $unknown = [];
                        while (null !== $line = $this->data->fetch()) {
                            if (!$this->processLine($line)) {
                                $unknown[] = $line;
                            }
                        }

                        if ([] !== $unknown) {
                            $this->logDiagnostic(
                                'Did not understand the following tool output: ' . "\n" .
                                implode("\n", $unknown),
                                'warning'
                            );
                            $this->report
                                ->addAttachment('composer-normalize-raw.txt')
                                ->fromString($this->data->getData())
                                ->end();
                        }

                        if ('' !== $this->diff) {
                            $this->report
                                ->addDiff('composer.json-normalized.diff')
                                    ->fromString($this->diff)
                                ->end();
                        }
                    }

                    private function processLine(string $line): bool
                    {
                        // Never process empty lines.
                        if (empty($line)) {
                            return true;
                        }

                        foreach (
                            // Regex => callback (...<named match>): void
                            [
                                self::REGEX_IN_APPLICATION => function (): void {
                                    // Ignore header.
                                },
                                self::REGEX_NOT_WRITABLE => function (): void {
                                    $this->logDiagnostic(
                                        $this->composerFile . ' is not writable.',
                                        ToolReportInterface::SEVERITY_ERROR
                                    );
                                },
                                self::REGEX_NOT_NORMALIZED => function (): void {
                                    $this->logDiagnostic(
                                        $this->composerFile . ' is not normalized.',
                                        ToolReportInterface::SEVERITY_ERROR
                                    );
                                },
                                self::REGEX_XDEBUG_ENABLED => function (string $message): void {
                                    $this->logDiagnostic($message, ToolReportInterface::SEVERITY_INFO);
                                },
                                self::REGEX_LOCK_OUTDATED => function (string $message): void {
                                    $this->logDiagnostic($message, ToolReportInterface::SEVERITY_ERROR);
                                },
                                self::REGEX_SCHEMA_VIOLATION => function (): void {
                                    while (null !== $line = $this->data->peek()) {
                                        if (empty($line)) {
                                            $this->data->fetch();
                                            continue;
                                        }
                                        if ('-' === $line[0]) {
                                            $error = substr($line, 2);
                                            $this->data->fetch();
                                            // Collect wrapped lines.
                                            while (null !== $line = $this->data->peek()) {
                                                if (empty($line)) {
                                                    break;
                                                }
                                                if ('-' !== $line[0]) {
                                                    $error .= ' ' . $line;
                                                    $this->data->fetch();
                                                    continue;
                                                }
                                                break;
                                            }
                                            $this->logDiagnostic($error, ToolReportInterface::SEVERITY_ERROR);
                                        }
                                        if (
                                            'See https://getcomposer.org/doc/04-schema.md for details on the schema'
                                            === $line
                                        ) {
                                            $this->data->fetch();
                                            break;
                                        }
                                    }
                                },
                                self::REGEX_SKIPPED_COMMAND => function (string $message): void {
                                    $this->logDiagnostic($message, ToolReportInterface::SEVERITY_NOTICE);
                                },
                            ] as $pattern => $handler
                        ) {
                            if (1 === preg_match($pattern, $line, $matches)) {
                                $variables = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                                call_user_func_array($handler, $variables);
                                return true;
                            }
                        }
                        return false;
                    }
                };
            }
        };
    }
};
