<?php

namespace Phpcq\BootstrapTest\Test;

use PHPUnit\Framework\TestCase;

final class PharTaskRunnerBuilder extends AbstractTaskRunnerBuilder
{
    private $pharName;

    private $arguments;

    public function __construct(TestCase $testCase, string $pharName, array $arguments)
    {
        parent::__construct($testCase);
        $this->pharName  = $pharName;
        $this->arguments = $arguments;
    }

    public function getPhar(): string
    {
        return $this->pharName;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }
}
