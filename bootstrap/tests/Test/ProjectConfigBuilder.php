<?php

namespace Phpcq\BootstrapTest\Test;

use Phpcq\PluginApi\Version10\ProjectConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProjectConfigBuilder
{
    /**
     * @var TestCase
     */
    private $testCase;

    /**
     * @var MockObject|ProjectConfigInterface
     */
    private $mock;

    public static function create(TestCase $testCase): self
    {
        return new self(
            $testCase,
            $testCase->getMockBuilder(ProjectConfigInterface::class)->getMockForAbstractClass()
        );
    }

    private function __construct(TestCase $testCase, MockObject $mock)
    {
        $this->testCase = $testCase;
        $this->mock = $mock;
    }

    public function projectRootPath(?string $projectRootPath): self
    {
        if (null !== $projectRootPath) {
            $this->mock
                ->expects($this->testCase->atLeastOnce())
                ->method('getProjectRootPath')
                ->willReturn($projectRootPath);

            return $this;
        }
        $this->mock
            ->expects($this->testCase->never())
            ->method('getProjectRootPath');

        return $this;
    }

    public function directories(?array $directories): self
    {
        if (null !== $directories) {
            $this->mock
                ->expects($this->testCase->atLeastOnce())
                ->method('getDirectories')
                ->willReturn($directories);

            return $this;
        }
        $this->mock
            ->expects($this->testCase->never())
            ->method('getDirectories');

        return $this;
    }

    public function artifactOutputPath(?string $artifactOutputPath): self
    {
        if (null !== $artifactOutputPath) {
            $this->mock
                ->expects($this->testCase->atLeastOnce())
                ->method('getArtifactOutputPath')
                ->willReturn($artifactOutputPath);

            return $this;
        }
        $this->mock
            ->expects($this->testCase->never())
            ->method('getArtifactOutputPath');

        return $this;
    }

    /**
     * Obtain the mock.
     *
     * @return MockObject|ProjectConfigInterface
     */
    public function getMock(): MockObject
    {
        return $this->mock;
    }
}
