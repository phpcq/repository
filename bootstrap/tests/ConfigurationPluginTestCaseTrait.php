<?php

declare(strict_types=1);

namespace Phpcq\BootstrapTest;

use Phpcq\PluginApi\Version10\Configuration\Builder\BoolOptionBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\Builder\FloatOptionBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\Builder\IntOptionBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\Builder\OptionsBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\Builder\OptionsListOptionBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\Builder\PrototypeBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\Builder\StringListOptionBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\Builder\StringOptionBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Task\TaskBuilderInterface;
use Phpcq\PluginApi\Version10\Task\TaskFactoryInterface;
use Phpcq\PluginApi\Version10\Task\TaskInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;

trait ConfigurationPluginTestCaseTrait
{
    public function testDescribeOptionsCanBeCalledWithoutError(): void
    {
        $instance = static::getPluginInstance();
        if (!$instance instanceof ConfigurationPluginInterface) {
            $this->markTestSkipped('Not a configuration plugin');
        }
        $instance->describeConfiguration($this->createMock(PluginConfigurationBuilderInterface::class));
        $this->addToAssertionCount(1);
    }

    protected function assertPluginCreatesMatchingTasksForConfiguration(array $expected, array $configuration): void
    {
        /** @var DiagnosticsPluginInterface $instance */
        $instance = static::getPluginInstance();

        // Ensure config is allowed by plugin
        $defaults = $this->assertPluginAcceptsConfig($instance, $configuration);

        $this->assertInstanceBuildsTasks($expected, $configuration, $defaults, $instance);
    }

    protected function assertPluginAcceptsConfig(ConfigurationPluginInterface $instance, array $configuration): array
    {
        $options  = [];
        $defaults = [];
        $required = [];
        $optionsBuilder = $this->getMockForAbstractClass(PluginConfigurationBuilderInterface::class);
        $mockBuilder = function (string $name, string $builderClass) use (&$defaults, &$required) {
            $builder = $this->getMockForAbstractClass($builderClass);
            $builder->method('isRequired')->willReturnCallback(function () use ($name, &$required, $builder) {
                $required[$name] = true;

                return $builder;
            });
            $builder->method('withDefaultValue')->willReturnCallback(
                function ($value) use ($name, &$defaults, $builder) {
                    $defaults[$name] = $value;
                    return $builder;
                }
            );

            return $builder;
        };

        $optionsBuilder->method('describeOptions')->willReturnCallback(
            function (string $name) use (&$options, $mockBuilder) {
                $options[$name] = func_get_args();

                return $mockBuilder($name, OptionsBuilderInterface::class);
            }
        );
        $optionsBuilder->method('describePrototypeOption')->willReturnCallback(
            function (string $name) use (&$options, $mockBuilder) {
                $options[$name] = func_get_args();

                return $mockBuilder($name, PrototypeBuilderInterface::class);
            }
        );
        $optionsBuilder->method('describeBoolOption')->willReturnCallback(
            function (string $name) use (&$options, $mockBuilder) {
                $options[$name] = func_get_args();

                return $mockBuilder($name, BoolOptionBuilderInterface::class);
            }
        );
        $optionsBuilder->method('describeFloatOption')->willReturnCallback(
            function (string $name) use (&$options, $mockBuilder) {
                $options[$name] = func_get_args();

                return $mockBuilder($name, FloatOptionBuilderInterface::class);
            }
        );
        $optionsBuilder->method('describeIntOption')->willReturnCallback(
            function (string $name) use (&$options, $mockBuilder) {
                $options[$name] = func_get_args();

                return $mockBuilder($name, IntOptionBuilderInterface::class);
            }
        );
        $optionsBuilder->method('describeStringOption')->willReturnCallback(
            function (string $name) use (&$options, $mockBuilder) {
                $options[$name] = func_get_args();

                return $mockBuilder($name, StringOptionBuilderInterface::class);
            }
        );
        $optionsBuilder->method('describeEnumOption')->willReturnCallback(
            function (string $name) use (&$options, $mockBuilder) {
                $options[$name] = func_get_args();

                return $mockBuilder($name, OptionsBuilderInterface::class);
            }
        );
        $optionsBuilder->method('describeStringListOption')->willReturnCallback(
            function (string $name) use (&$options, $mockBuilder) {
                $options[$name] = func_get_args();

                return $mockBuilder($name, StringListOptionBuilderInterface::class);
            }
        );
        $optionsBuilder->method('describeOptionsListOption')->willReturnCallback(
            function (string $name) use (&$options, $mockBuilder) {
                $options[$name] = func_get_args();

                return $mockBuilder($name, OptionsListOptionBuilderInterface::class);
            }
        );

        $instance->describeConfiguration($optionsBuilder);

        $diff = array_diff(array_keys($configuration), array_keys($options));
        if ($diff !== [] && $diff !== ['directories']) {
            $this->fail(
                'Unsupported config value(s): ' .
                implode(', ', $diff)
            );
        }

        return array_intersect_key($defaults, $required);
    }

    protected function assertInstanceBuildsTasks(
        array $expected,
        array $configuration,
        array $defaults,
        DiagnosticsPluginInterface $instance
    ) {
        $config = $this->projectConfig()->tasks($expected)->build();

        $count = 0;
        // Iterate over all tasks to trigger the iterator and count the returned instances.
        $tasks = $instance->createDiagnosticTasks($this->mockPluginConfiguration($configuration, $defaults), $config);
        foreach ($tasks as $item) {
            $this->assertInstanceOf(TaskInterface::class, $item);
            $count++;
        }

        $this->assertSame(count($expected), $count, 'Plugin emitted task count mismatch');
    }

    /** @SuppressWarnings(PHPMD.UnusedLocalVariable) */
    public function mockOutputTransformer(
        array $configurationValues,
        TaskReportInterface $report
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

        $environment = $this->getMockForAbstractClass(EnvironmentInterface::class);
        $environment->expects($this->once())->method('getTaskFactory')->willReturn($taskFactory);

        /** @var DiagnosticsPluginInterface $instance */
        $instance = static::getPluginInstance();
        $this->assertSame(basename(dirname(static::getBootstrapFile())), $instance->getName());

        $configInstance = $this->mockPluginConfiguration($configurationValues, []);
        // Have to trigger the lazy generator.
        foreach ($instance->createDiagnosticTasks($configInstance, $environment) as $item) {
            break;
        }

        $this->assertInstanceOf(OutputTransformerInterface::class, $outputTransformer);

        return $outputTransformer;
    }

    protected function mockPluginConfiguration(array $values, array $defaults): PluginConfigurationInterface
    {
        $getter = function (string $name) use (&$values, &$defaults) {
            return $values[$name] ?? ($defaults[$name] ?? null);
        };

        $configuration = $this->getMockForAbstractClass(PluginConfigurationInterface::class);
        $configuration->method('getInt')->willReturnCallback($getter);
        $configuration->method('getString')->willReturnCallback($getter);
        $configuration->method('getFloat')->willReturnCallback($getter);
        $configuration->method('getBool')->willReturnCallback($getter);
        $configuration->method('getStringList')->willReturnCallback($getter);
        $configuration->method('getOptionsList')->willReturnCallback($getter);
        $configuration->method('getOptions')->willReturnCallback($getter);
        $configuration->method('getValue')->willReturn($values);
        $configuration->method('has')->willReturnCallback(
            function (string $name) use (&$values): bool {
                return isset($values[$name]);
            }
        );

        return $configuration;
    }
}
