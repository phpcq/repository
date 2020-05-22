<?php

namespace Phpcq\BootstrapTest\Test;

use Phpcq\PluginApi\Version10\TaskRunnerBuilderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

abstract class AbstractTaskRunnerBuilder
{
    /**
     * @var TestCase
     */
    private $testCase;

    private $cwd = null;

    /**
     * @var array
     */
    private $env;

    private $input;

    private $timeout;

    /**
     * @var MockObject
     */
    private $mock;

    public function __construct(TestCase $testCase)
    {
        $this->testCase = $testCase;
    }

    public function withWorkingDirectory(string $cwd): self
    {
        $this->cwd = $cwd;

        return $this;
    }

    public function withEnv(array $env): self
    {
        $this->env = $env;

        return $this;
    }

    public function withInput($input): self
    {
        $this->input = $input;

        return $this;
    }

    public function withTimeout($timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function getTask(): TaskRunnerBuilderInterface
    {
        $mock = $this->mock = $this->testCase
            ->getMockBuilder(TaskRunnerBuilderInterface::class)
            ->getMockForAbstractClass();
        $this->expectCwd();
        $this->expectEnv();
        $this->expectInput();
        $this->expectTimeout();

        $this->mock->expects($this->testCase->once())->method('build');
        $this->mock = null;

        return $mock;
    }

    private function expectCwd(): void
    {
        if (null !== $this->cwd) {
            $this->expectOnce('withWorkingDirectory', $this->cwd);
            return;
        }
        $this->expectNever('withWorkingDirectory');
    }

    private function expectEnv()
    {
        if (null !== $this->env) {
            $this->expectOnce('withEnv', $this->env);
            return;
        }
        $this->expectNever('withEnv');
    }

    private function expectInput()
    {
        if (null !== $this->input) {
            $this->expectOnce('withInput', $this->input);
            return;
        }
        $this->expectNever('withInput');
    }

    private function expectTimeout()
    {
        if (null !== $this->timeout) {
            $this->expectOnce('withTimeout', $this->timeout);
            return;
        }
        $this->expectNever('withTimeout');
    }

    private function expectOnce(string $method, $value): void
    {
        $this->mock->expects($this->testCase->once())->method($method)->with($value)->willReturn($this->mock);
    }

    private function expectNever(string $method): void
    {
        $this->mock->expects($this->testCase->never())->method($method);
    }
}
