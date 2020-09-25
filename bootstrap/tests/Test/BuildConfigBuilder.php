<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest\Test;

use LogicException;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\ProjectConfigInterface;
use Phpcq\PluginApi\Version10\Task\TaskFactoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BuildConfigBuilder
{
    public const BUILD_TEMP_DIR = 'BUILD/TEMP/DIR';
    public const PROJECT_ROOT = 'PROJECT/ROOT';
    public const ARTIFACT_OUTPUT_PATH = 'ARTIFACT/OUTPUT/PATH';
    public const SOURCE_DIRECTORIES = ['SRC1', 'SRC2'];

    /**
     * @var MockObject|EnvironmentInterface
     */
    private $environment;

    /**
     * @var TestCase
     */
    private $testCase;

    /**
     * @var string
     */
    private $buildTempDir;

    /**
     * @var string
     */
    private $projectRootPath;

    /**
     * @var string[]
     */
    private $directories;

    /**
     * @var string
     */
    private $artifactOutputPath;

    /** @var string[] */
    private $tempFiles;

    private $taskFactory;

    public function __construct(TestCase $testCase)
    {
        $this->testCase = $testCase;
        $this->environment = $this->testCase
            ->getMockBuilder(EnvironmentInterface::class)
            ->getMockForAbstractClass();
    }

    public function tasks(array $tasks): self
    {
        if (null !== $this->taskFactory) {
            throw new LogicException('Tasks already initialized.');
        }
        $this->buildTaskFactory($tasks);

        return $this;
    }

    public function buildTempDir(string $tempDir = self::BUILD_TEMP_DIR): self
    {
        $this->buildTempDir = $tempDir;

        return $this;
    }

    public function requestsTempFiles(...$fileNames): self
    {
        $this->tempFiles = $fileNames;

        return $this;
    }

    public function hasProjectRootPath(string $projectRootPath = self::PROJECT_ROOT): self
    {
        $this->projectRootPath = $projectRootPath;

        return $this;
    }

    public function directories(array $directories = self::SOURCE_DIRECTORIES): self
    {
        $this->directories = $directories;

        return $this;
    }

    public function artifactOutputPath(string $artifactOutputPath = self::ARTIFACT_OUTPUT_PATH): self
    {
        $this->artifactOutputPath = $artifactOutputPath;

        return $this;
    }

    public function build(): EnvironmentInterface
    {
        $this->environment
            ->method('getProjectConfiguration')
            ->willReturn($this->getProjectConfiguration());

        $this->mockTempFiles();

        if (null !== $this->buildTempDir) {
            $this->environment
                ->expects($this->testCase->atLeastOnce())
                ->method('getBuildTempDir')
                ->willReturn($this->buildTempDir);
            return $this->environment;
        }
        $this->environment->expects($this->testCase->never())->method('getBuildTempDir');

        return $this->environment;
    }

    private function mockTempFiles(): void
    {
        if (null !== $this->tempFiles) {
            $this->environment
                ->expects($this->testCase->atLeastOnce(count($this->tempFiles)))
                ->method('getUniqueTempFile')
                ->willReturnOnConsecutiveCalls(...$this->tempFiles);
            return;
        }

        $this->environment->expects($this->testCase->never())->method('getUniqueTempFile');
    }

    private function getProjectConfiguration(): ProjectConfigInterface
    {
        return ProjectConfigBuilder::create($this->testCase)
            ->projectRootPath($this->projectRootPath)
            ->directories($this->directories)
            ->artifactOutputPath($this->artifactOutputPath)
            ->getMock();
    }

    private function buildTaskFactory(array $tasks): void
    {
        $this->taskFactory = $this->testCase
            ->getMockBuilder(TaskFactoryInterface::class)
            ->getMockForAbstractClass();
        if (empty($tasks)) {
            $this->environment->expects($this->testCase->never())->method('getTaskFactory');
            return;
        }

        $this->environment
            ->expects($this->testCase->atLeastOnce())
            ->method('getTaskFactory')
            ->willReturn($this->taskFactory);

        $type = [
            'process' => [
                'task' => [],
                'command' => [],
            ],
            'phar'    => [
                'task' => [],
                'args' => [],
            ],
        ];
        foreach ($tasks as $task) {
            switch (true) {
                case $task instanceof PharTaskRunnerBuilder:
                    $type['phar']['task'][] = $task->getTask();
                    $type['phar']['args'][] = [$task->getPhar(), $task->getArguments()];
                    break;
                case $task instanceof ProcessTaskRunnerBuilder:
                    $type['process'][] = $task->getTask();
                    $type['phar']['command'][] = [$task->getCommand()];
                    break;
                default:
                    throw new LogicException('Unknown task type: ' . get_class($task));
            }
        }

        $this->taskFactory
            ->expects($this->testCase->exactly(count($type['process']['task'])))
            ->method('buildRunProcess')
            ->withConsecutive(...$type['process']['command'])
            ->willReturnOnConsecutiveCalls(...$type['process']['task']);

        $this->taskFactory
            ->expects($this->testCase->exactly(count($type['phar']['task'])))
            ->method('buildRunPhar')
            ->withConsecutive(...$type['phar']['args'])
            ->willReturnOnConsecutiveCalls(...$type['phar']['task']);
    }
}
