<?php

namespace Phpcq\BootstrapTest\Test;

use PHPUnit\Framework\TestCase;

final class ProcessTaskRunnerBuilder extends AbstractTaskRunnerBuilder
{
    /**
     * @var array
     */
    private $command;

    public function __construct(TestCase $testCase, array $command)
    {
        parent::__construct($testCase);
        $this->command = $command;
    }

    public function getCommand(): array
    {
        return $this->command;
    }
}
