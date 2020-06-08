<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest;

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Task\TaskBuilderInterface;
use Phpcq\PluginApi\Version10\Task\TaskFactoryInterface;
use Phpcq\PluginApi\Version10\Task\TaskInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;

trait ConfigurationPluginTestCaseTrait
{
    public function testDescribeOptionsCanBeCalledWithoutError(): void
    {
        $instance = static::getPluginInstance();
        if (!$instance instanceof ConfigurationPluginInterface) {
            $this->markTestSkipped('Not a configuration plugin');
        }
        $instance->describeOptions($this->createMock(ConfigurationOptionsBuilderInterface::class));
        $this->addToAssertionCount(1);
    }

    protected function assertPluginCreatesMatchingTasksForConfiguration(array $expected, array $configuration): void
    {
        /** @var ConfigurationPluginInterface $instance */
        $instance = static::getPluginInstance();

        // Ensure config is allowed by plugin
        $this->assertPluginAcceptsConfig($instance, $configuration);

        $this->assertInstanceBuildsTasks($expected, $configuration, $instance);
    }

    protected function assertPluginAcceptsConfig(ConfigurationPluginInterface $instance, array $configuration)
    {
        $options        = [];
        $optionsBuilder = $this->getMockForAbstractClass(ConfigurationOptionsBuilderInterface::class);
        $handleOption   = function (string $name) use (&$options, $optionsBuilder) {
            $options[$name] = func_get_args();

            return $optionsBuilder;
        };
        $optionsBuilder->expects($this->never())->method('getOptions');
        $optionsBuilder->method('describeArrayOption')->willReturnCallback($handleOption);
        $optionsBuilder->method('describeIntOption')->willReturnCallback($handleOption);
        $optionsBuilder->method('describeStringOption')->willReturnCallback($handleOption);
        $optionsBuilder->method('describeBoolOption')->willReturnCallback($handleOption);
        $optionsBuilder->method('describeOption')->willReturnCallback(
            function (ConfigurationOptionInterface $configOption) use (&$options, $optionsBuilder) {
                $options[$configOption->getName()] = [$configOption];

                return $optionsBuilder;
            }
        );

        $instance->describeOptions($optionsBuilder);

        $diff = array_diff(array_keys($configuration), array_keys($options));
        if ($diff !== []) {
            $this->fail(
                'Unsupported config value(s): ' .
                implode(', ', $diff)
            );
        }
    }

    protected function assertInstanceBuildsTasks(
        array $expected,
        array $configuration,
        ConfigurationPluginInterface $instance
    ) {
        $config = $this->projectConfig()->tasks($expected)->build();

        $count = 0;
        // Iterate over all tasks to trigger the iterator and count the returned instances.
        foreach ($instance->processConfig($configuration, $config) as $item) {
            $this->assertInstanceOf(TaskInterface::class, $item);
            $count++;
        }

        $this->assertSame(count($expected), $count, 'Plugin emitted task count mismatch');
    }

    /** @SuppressWarnings(PHPMD.UnusedLocalVariable) */
    public function mockOutputTransformer(
        array $configurationValues,
        ToolReportInterface $report
    ): OutputTransformerInterface {
        $outputTransformer = null;
        $builder = $this->getMockForAbstractClass(TaskBuilderInterface::class);
        $builder->expects($this->once())->method('withWorkingDirectory')->willReturnSelf();
        $builder
            ->expects($this->once())
            ->method('withOutputTransformer')
            ->willReturnCallback(
                function (OutputTransformerFactoryInterface $factory) use (&$outputTransformer, $builder, $report) {
                    $outputTransformer = $factory->createFor($report);

                    return $builder;
                }
            );
        $builder
            ->expects($this->once())
            ->method('build')
            ->willReturn($this->getMockForAbstractClass(TaskInterface::class));

        $taskFactory = $this->getMockForAbstractClass(TaskFactoryInterface::class);
        $taskFactory->expects($this->once())->method('buildRunPhar')->willReturn($builder);

        $buildConfig = $this->getMockForAbstractClass(BuildConfigInterface::class);
        $buildConfig->expects($this->once())->method('getTaskFactory')->willReturn($taskFactory);

        /** @var ConfigurationPluginInterface $instance */
        $instance = static::getPluginInstance();
        $this->assertSame(basename(dirname(static::getBootstrapFile())), $instance->getName());

        // Have to trigger the lazy generator.
        foreach ($instance->processConfig($configurationValues, $buildConfig) as $item) {
            break;
        }

        $this->assertInstanceOf(OutputTransformerInterface::class, $outputTransformer);

        return $outputTransformer;
    }
}
