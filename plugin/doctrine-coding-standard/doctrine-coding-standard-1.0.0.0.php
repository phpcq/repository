<?php

declare(strict_types=1);

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\EnricherPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Exception\InvalidConfigurationException;

return new class implements EnricherPluginInterface {
    #[Override]
    public function getName(): string
    {
        return 'doctrine-coding-standard';
    }

    #[Override]
    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder
            ->describeEnumOption(
                'phpcs_standard',
                'Activates the doctrine coding standard. Otherwise only the coding standard is registered.'
            )
            ->ofStringValues('override', 'extend', 'ignore')
            ->withDefaultValue('override')
            ->isRequired();
        ;
    }

    #[Override]
    public function enrich(
        string $pluginName,
        string $pluginVersion,
        array $pluginConfig,
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): array {
        $vendorDir = $environment->getInstalledDir() . '/vendor';

        switch ($pluginName) {
            case 'phpcs':
                $path = substr(
                    $vendorDir,
                    strlen($environment->getProjectConfiguration()->getProjectRootPath()) + 1
                );

                /** @psalm-var array{standard_paths?: list<string>, standard?: string} $pluginConfig */
                $pluginConfig['standard_paths'][] = $path . '/slevomat/coding-standard';
                $pluginConfig['standard_paths'][] = $path . '/doctrine/coding-standard/lib';
                $pluginConfig['autoload_paths'][] = $path . '/autoload.php';

                $standard = $config->getString('phpcs_standard');
                if ($standard === 'override') {
                    $pluginConfig['standard'] = 'Doctrine';
                } elseif ($standard === 'extend') {
                    $pluginConfig['standard'] = implode(
                        ',',
                        array_filter([$pluginConfig['standard'] ?? null, 'Doctrine'])
                    );
                }

                return $pluginConfig;

            default:
                throw new InvalidConfigurationException(
                    'Unable to enrich unsupported plugin ' . $pluginName . '.'
                );
        }
    }
};
