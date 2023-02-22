<?php

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;
use Phpcq\PluginApi\Version10\Util\BufferedLineReader;

return new class implements DiagnosticsPluginInterface {
    /** @var array{
     *   add_directories: list<string>,
     *   add_directories_bin: list<string>,
     *   algorithm: string,
     *   annotations: true,
     *   blacklist: list<string>,
     *   check_requirements: true,
     *   compactors: list<string>,
     *   compression: string,
     *   datetime_format: string,
     *   dump_autoload: true,
     *   exclude_composer_files: true,
     *   exclude_dev_files: true,
     *   files: list<string>,
     *   files_bin: list<string>,
     *   finder: list<string>,
     *   finder_bin: list<string>,
     *   force_autodiscovery: false,
     *   intercept: false,
     *   php_scoper: string,
     *   replacement_sigil: string,
     *   replacements: array<string, string>,
     *   stub: string
     * }
     */
    private static $defaults = [
        'algorithm'              => 'SHA1',
        'annotations'            => true,
        'blacklist'              => [],
        'check_requirements'     => true,
        'compactors'             => [],
        'compression'            => 'NONE',
        'datetime_format'        => 'Y-m-d H:i:s T',
        'add_directories'        => [],
        'add_directories_bin'    => [],
        'dump_autoload'          => true,
        'exclude_composer_files' => true,
        'exclude_dev_files'      => true,
        'files'                  => [],
        'files_bin'              => [],
        'finder'                 => [],
        'finder_bin'             => [],
        'force_autodiscovery'    => false,
        'intercept'              => false,
        'php_scoper'             => 'scoper.inc.php',
        'replacement_sigil'      => '@',
        'replacements'           => [],
        'stub'                   => 'generated',
    ];

    public function getName(): string
    {
        return 'box';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder
            ->describeStringListOption(
                'custom_flags',
                'Any custom flags to pass. For valid flags refer to the phpcs documentation.',
            )
            ->isRequired()
            ->withDefaultValue([]);

        // See https://github.com/box-project/box/blob/master/doc/configuration.md

        // https://github.com/box-project/box/blob/master/doc/configuration.md#signing-algorithm-algorithm
        $configOptionsBuilder
            ->describeStringOption(
                'algorithm',
                <<<EOF
                The algorithm setting is the signing algorithm to use when the PHAR is built
                (Phar::setSignatureAlgorithm()).

                The following is a list of the signature algorithms available:
                MD5
                SHA1
                SHA256
                SHA512
                OPENSSL
                By default PHARs are SHA1 signed.

                The OPENSSL algorithm will require to provide a key.

                EOF
            )
            ->withDefaultValue(static::$defaults['algorithm']);
        // https://github.com/box-project/box/blob/master/doc/configuration.md#alias-alias
        $configOptionsBuilder
            ->describeStringOption(
                'alias',
                <<<EOF
                The alias setting is used when generating a new stub to call the Phar::mapPhar().
                This makes it easier to refer to files in the PHAR and ensure the access to internal files will always
                work regardless of the location of the PHAR on the file system.
                If no alias is provided, a generated unique name will be used for it in order to map the main file.
                Note that this may have undesirable effects if you are using the generated stub.

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#annotations-annotations
        // FIXME: this can also be a hash map with another hash map at key "ignore"
        // FIXME: using the names of the annotations as keys of the sub map. How to address?
        $configOptionsBuilder
            ->describeBoolOption(
                'annotations',
                <<<EOF
                The annotations setting is used to enable compacting annotations in PHP source code.
                This setting is only taken into consideration if the KevinGH\Box\Compactor\Php compactor is enabled.
                By default, it removes all non real-like annotations from the PHP code.

                EOF
            )
            ->withDefaultValue(static::$defaults['annotations']);
        // https://github.com/box-project/box/blob/master/doc/configuration.md#banner-banner
        $configOptionsBuilder
            ->describeStringOption(
                'banner',
                <<<EOF
                The banner setting is the banner comment that will be used when a new stub is generated.
                The value of this setting must not already be enclosed within a comment block as it will be
                automatically done for you.

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#banner-file-banner-file
        $configOptionsBuilder
            ->describeStringOption(
                'banner_file',
                <<<EOF
                The banner-file setting is like banner, except it is a path (relative to the base path) to the file
                that will contain the comment.
                Like banner, the comment must not already be enclosed in a comment block.
                If this parameter is set to a different value than null, then the value of banner will be discarded.

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#base-path-base-path
        // NOT SUPPORTED, AS WE CREATE THE CONFIG IN TEMP LOCATION AND THEREFORE NEED TO PASS THE PROJECT ROOT HERE.
        /*
        $configOptionsBuilder
            ->describeStringOption(
                'base_path',
                <<<EOF
                The base-path setting is used to specify where all of the relative file paths should resolve to.
                This does not, however, alter where the built PHAR will be stored (see: output).
                If set to null or not specified, the base path used is the directory containing the configuration
                file when a specific configuration file is given or the current working directory otherwise.

                EOF
            )
        */
        // https://github.com/box-project/box/blob/master/doc/configuration.md#blacklist-blacklist
        $configOptionsBuilder
            ->describeStringListOption(
                'blacklist',
                <<<EOF
                The blacklist setting is a list of files that must not be added.
                The files blacklisted are the ones found using the other available configuration settings:
                files, files-bin, directories, directories-bin, finder, finder-bin.

                Note that all the blacklisted paths are relative to the settings configured above.

                EOF
            )
            ->withDefaultValue(static::$defaults['blacklist'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#check-requirements-check-requirements
        $configOptionsBuilder
            ->describeBoolOption(
                'check_requirements',
                <<<EOF
                The check requirements setting is used to allow the PHAR to check for the application constraint before
                running. See more information about it here. If not set or set to null, then the requirement checker
                will be added. Note that this is true only if either the composer.json or composer.lock could have been
                found.

                Warning: this check is still done within the PHAR. As a result, if the required extension to open the
                PHAR due to the compression algorithm is not loaded, a hard failure will still appear: the requirement
                checker cannot be executed before that.

                EOF
            )
            ->withDefaultValue(static::$defaults['check_requirements'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#permissions-chmod
        $configOptionsBuilder
            ->describeStringOption(
                'chmod',
                <<<EOF
                The chmod setting is used to change the file permissions of the newly built PHAR. The string contains
                an octal value e.g. 0750. By default the permissions of the created PHAR are unchanged so it should be
                0644.
                Check https://secure.php.net/manual/en/function.chmod.php for more on the possible values.

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#compactors-compactors
        $configOptionsBuilder
            ->describeStringListOption(
                'compactors',
                <<<EOF
                The compactors setting is a list of file contents compacting classes that must be registered.
                A file compacting class is used to reduce the size of a specific file type.
                The following compactors are included with Box:
                - KevinGH\Box\Compactor\Json: compress JSON files
                - KevinGH\Box\Compactor\Php: strip down classes from phpdocs & comments
                - KevinGH\Box\Compactor\PhpScoper: isolate the code using PHP-Scoper

                EOF
            )
            ->withDefaultValue(static::$defaults['compactors'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#compression-algorithm-compression
        $configOptionsBuilder
            ->describeStringOption(
                'compression',
                <<<EOF
                The compression (string|null default NONE) setting is the compression algorithm to use when the PHAR is
                built. The compression affects the individual files within the PHAR and not the PHAR as a whole
                (Phar::compressFiles()).
                The following is a list of the signature algorithms available:
                - GZ (the most efficient most of the time)
                - BZ2
                - NONE (default)
                Warning: be aware that if compressed, the PHAR will required the appropriate extension (zlib for GZ and
                bz2 for BZ2) to execute the PHAR. Without it, PHP will not be able to open the PHAR at all.
                EOF
            )
            ->isRequired()
            ->withDefaultValue(static::$defaults['compression']);
        // https://github.com/box-project/box/blob/master/doc/configuration.md#datetime-placeholder-datetime
        $configOptionsBuilder
            ->describeStringOption(
                'datetime',
                <<<EOF
                The datetime setting is the name of a placeholder value that will be replaced in all non-binary files
                by the current datetime. If no value is given (null) then this placeholder will be ignored.
                The format of the date used is defined by the datetime_format setting.

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#datetime-placeholder-format-datetime-format
        $configOptionsBuilder
            ->describeStringOption(
                'datetime_format',
                <<<EOF
                The datetime format placeholder setting accepts a valid PHP date format.
                It can be used to change the format for the datetime setting.

                EOF
            )
            ->withDefaultValue(static::$defaults['datetime_format'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#directories-directories-and-directories-bin
        $configOptionsBuilder
            ->describeStringListOption(
                'add_directories',
                <<<EOF
                The directories setting is a list of directory paths relative to base-path.
                All files will be processed by the compactors, have their placeholder values replaced
                (see: replacements) and added to the PHAR.
                Files listed in the blacklist will not be added to the PHAR.
                EOF
            )
            ->withDefaultValue(static::$defaults['add_directories'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#directories-directories-and-directories-bin
        $configOptionsBuilder
            ->describeStringListOption(
                'add_directories_bin',
                <<<EOF
                The directories_bin setting is analogue to directories except the files are added to the PHAR
                unmodified. This is suitable for the files such as images, those that contain binary data or simply a
                file you do not want to alter at all despite using compactors.

                EOF
            )
            ->withDefaultValue(static::$defaults['add_directories_bin'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#dumping-the-composer-autoloader-dump-autoload
        $configOptionsBuilder
            ->describeBoolOption(
                'dump_autoload',
                <<<EOF
                The dump_autoload setting will result in Box dump the Composer autoload with the classmap authoritative
                mode and the --no-dev option which disables the autoload-dev rules.
                This is however done only if a composer.json file could be found. If a composer.lock file is found as
                well, the file vendor/composer/installed.json will be required too.
                The dumping of the autoloader will be ignored if the composer.json file could not be found.
                The autoloader is dumped at the end of the process to ensure it will take into account the eventual
                modifications done by the compactors process.

                EOF
            )
            ->isRequired()
            ->withDefaultValue(static::$defaults['dump_autoload']);
        // https://github.com/box-project/box/blob/master/doc/configuration.md#excluding-the-composer-files-exclude-composer-files
        $configOptionsBuilder
            ->describeBoolOption(
                'exclude_composer_files',
                <<<EOF
                The exclude-composer-files setting will result in removing the Composer files composer.json,
                composer.lock and vendor/composer/installed.json if they are found regardless of whether or not they
                were found by Box alone or explicitly included.

                EOF
            )
            ->isRequired()
            ->withDefaultValue(static::$defaults['exclude_composer_files']);
        // https://github.com/box-project/box/blob/master/doc/configuration.md#excluding-the-composer-files-exclude-composer-files
        $configOptionsBuilder
            ->describeBoolOption(
                'exclude_dev_files',
                <<<EOF
                The exclude-dev-files (bool default true) setting will, when enabled, cause Box to attempt to exclude
                the files belonging to dev only packages.
                This setting will automatically be disabled when dump-autoload is disabled. Indeed, otherwise some files
                will not be shipped in the PHAR but may still appear in the Composer autoload classmap, resulting in an
                autoloading error.

                EOF
            )
            ->isRequired()
            ->withDefaultValue(static::$defaults['exclude_dev_files']);
        // https://github.com/box-project/box/blob/master/doc/configuration.md#files-files-and-files-bin
        $configOptionsBuilder
            ->describeStringListOption(
                'files',
                <<<EOF
                The files setting is a list of files paths relative to base-path unless absolute. Each file will be
                processed by the compactors, have their placeholder values replaced (see: replacements) and added to the
                PHAR.
                This setting is not affected by the blacklist setting.
                EOF
            )
            ->withDefaultValue(static::$defaults['files'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#files-files-and-files-bin
        $configOptionsBuilder
            ->describeStringListOption(
                'files_bin',
                <<<EOF
                files-bin is analogue to files except the files are added to the PHAR unmodified.
                This is suitable for the files such as images, those that contain binary data or simply a file you do
                not want to alter at all despite using compactors.

                EOF
            )
            ->withDefaultValue(static::$defaults['files_bin'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#finder-finder-and-finder-bin
        $configOptionsBuilder
            ->describeOptionsListOption(
                'finder',
                <<<EOF
                The finder setting is a list of JSON objects. Each object (key, value) tuple is a (method, arguments) of
                the Symfony Finder used by Box. If an array of values is provided for a single key, the method will be
                called once per value in the array.
                Note that the paths specified for the in method are relative to base-path and that the finder will
                account for the files registered in the blacklist.
                EOF
            )
            ->withDefaultValue(static::$defaults['finder'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#finder-finder-and-finder-bin
        $configOptionsBuilder
            ->describeOptionsListOption(
                'finder_bin',
                <<<EOF
                finder-bin is analogue to finder except the files are added to the PHAR unmodified.
                This is suitable for the files such as images, those that contain binary data or simply a file you do
                not want to alter at all despite using compactors.

                EOF
            )
            ->withDefaultValue(static::$defaults['finder_bin'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#force-auto-discovery-force-autodiscovery
        $configOptionsBuilder
            ->describeBoolOption(
                'force_autodiscovery',
                <<<EOF
                The force-autodiscovery setting forces Box to attempt to find which files to include even though you
                are using the directories or finder setting.
                When Box tries to find which files to include, it may remove some files such as readmes or test files.
                If however you are using the directories or finder, Box will append the found files to the ones you
                listed.

                EOF
            )
            ->withDefaultValue(static::$defaults['force_autodiscovery'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#pretty-git-tag-placeholder-git
        $configOptionsBuilder
            ->describeStringOption(
                'git',
                <<<EOF
                The git tag placeholder setting is the name of a placeholder value that will be replaced in all
                non-binary files by the current git tag of the repository.
                Example of value the placeholder will be replaced with:
                - "2.0.0" on an exact tag match
                - "2.0.0@e558e33" on a commit following a tag

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#git-commit-placeholder-git-commit
        $configOptionsBuilder
            ->describeStringOption(
                'git_commit',
                <<<EOF
                The git commit setting is the name of a placeholder value that will be replaced in all non-binary files
                by the current git commit hash of the repository.
                Example of value the placeholder will be replaced with: e558e335f1d165bc24d43fdf903cdadd3c3cbd03

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#short-git-commit-placeholder-git-commit-short
        $configOptionsBuilder
            ->describeStringOption(
                'git_commit_short',
                <<<EOF
                The short git commit setting is the name of a placeholder value that will be replaced in all non-binary
                files by the current git short commit hash of the repository.
                Example of value the placeholder will be replaced with: e558e33

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#git-tag-placeholder-git-tag
        $configOptionsBuilder
            ->describeStringOption(
                'git_tag',
                <<<EOF
                The git tag placeholder (string|null default null) setting is the name of a placeholder value that will
                be replaced in all non-binary files by the current git tag of the repository.
                Example of value the placeholder will be replaced with:
                - "2.0.0" on an exact tag match
                - "2.0.0-2-ge558e33" on a commit following a tag

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#git-version-placeholder-git-version
        $configOptionsBuilder
            ->describeStringOption(
                'git_version',
                <<<EOF
                The git version setting is the name of a placeholder value that will be replaced in all non-binary files
                by the one of the following (in order):
                - The git repository's most recent tag.
                - The git repository's current short commit hash.
                - The short commit hash will only be used if no tag is available.

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#intercept-intercept
        $configOptionsBuilder
            ->describeBoolOption(
                'intercept',
                <<<EOF
                The intercept setting is used when generating a new stub.
                If setting is set to true, the Phar::interceptFileFuncs() method will be called in the stub.

                EOF
            )
            ->withDefaultValue(static::$defaults['intercept'])
            ->isRequired();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#the-private-key-key
        $configOptionsBuilder
            ->describeStringOption(
                'key',
                <<<EOF
                The key setting is used to specify the path to the private key file. The private key file will be used
                to sign the PHAR using the OPENSSL signature algorithm (see Signing algorithm) and the setting will be
                completely ignored otherwise. If an absolute path is not provided, the path will be relative to the
                current working directory.

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#the-private-key-password-key-pass
        $configOptionsBuilder
            ->describeStringOption(
                'key_pass',
                <<<EOF
                The private key password setting is used to specify the pass-phrase for the private key. If a string is
                provided, it will be used as is as the pass-phrase.
                If "ENV::<name>" is provided, the environment variable "name" will get used to prevent leakage of the
                password.
                This setting will be ignored if no key has been provided.

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#main-main
        $configOptionsBuilder
            ->describeStringOption(
                'main',
                <<<EOF
                The main setting is used to specify the file (relative to base-path) that will be run when the PHAR is
                executed from the command line (To not confuse with the stub which is the PHAR
                bootstrapping file).

                When you have a main script file that can be used as a stub, you can disable the main script by setting
                it to empty string.

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#map-map
        // FIXME: I almost bet this is could be described better.
        $configOptionsBuilder
            ->describePrototypeOption(
                'map',
                <<<EOF
                The map setting is used to change where some (or all) files are stored inside the PHAR. The key is a
                beginning of the relative path that will be matched against the file being added to the PHAR. If the key
                is a match, the matched segment will be replaced with the value. If the key is empty, the value will be
                prefixed to all paths (except for those already matched by an earlier key).

                EOF
            )
            ->ofStringValue();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#metadata-metadata
        // FIXME: how shall we support THIS? - "any" value is evil.
        $configOptionsBuilder
            ->describeStringOption(
                'metadata',
                <<<EOF
                The metadata setting can be any value. This value will be stored as metadata that can be retrieved from
                the built PHAR (`Phar::getMetadata()).

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#output-output
        $configOptionsBuilder
            ->describeStringOption(
                'output',
                <<<EOF
                The output setting specifies the file name and path of the newly built PHAR. If the value of the setting
                is not an absolute path, the path will be relative to the base path.
                If not provided or set to null, the default value used will based on the main. For example if the main
                file is bin/acme.php or bin/acme then the output will be bin/acme.phar.
                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#php-scoper-php-scoper
        $configOptionsBuilder
            ->describeStringOption(
                'php_scoper',
                <<<EOF
                The PHP-Scoper setting points to the path to the PHP-Scoper configuration file. For more documentation
                regarding PHP-Scoper, you can head to PHAR code isolation or PHP-Scoper official documentation.
                Note: this setting is used only if the compactor KevinGH\Box\Compactor\PhpScoper is registered.

                EOF
            )
            ->withDefaultValue(static::$defaults['php_scoper']);
        // https://github.com/box-project/box/blob/master/doc/configuration.md#replacement-sigil-replacement-sigil
        $configOptionsBuilder
            ->describeStringOption(
                'replacement_sigil',
                <<<EOF
                The replacement sigil is the character or chain of characters used to delimit the placeholders.
                See the @replacements setting for examples of placeholders.

                EOF
            )
            ->withDefaultValue(static::$defaults['replacement_sigil']);
        // https://github.com/box-project/box/blob/master/doc/configuration.md#replacements-replacements
        $configOptionsBuilder
            ->describePrototypeOption(
                'replacements',
                <<<EOF
                The replacements setting is a map of placeholders (as keys) and their values.
                The placeholders are replaced in all non-binary files with the specified values.

                EOF
            )
            ->withDefaultValue(static::$defaults['replacements'])
            ->ofStringValue();
        // https://github.com/box-project/box/blob/master/doc/configuration.md#shebang-shebang
        $configOptionsBuilder
            ->describeStringOption(
                'shebang',
                <<<EOF
                The shebang setting is used to specify the shebang line used when generating a new stub.
                By default, this line is used: `#!/usr/bin/env php`
                The shebang line can be removed altogether if set to empty string.

                EOF
            );
        // https://github.com/box-project/box/blob/master/doc/configuration.md#stub-stub
        $configOptionsBuilder
            ->describeStringOption(
                'stub',
                <<<EOF
                The stub setting is used to specify the location of a stub file or if one should be generated:
                - Path to the stub file will be used as is inside the PHAR
                - generated: A new stub will be generated (default)
                - empty string: The default stub used by the PHAR class will be used
                If a custom stub file is provided, none of the other options: (shebang, intercept and alias) are used.

                EOF
            )
            ->withDefaultValue(static::$defaults['stub']);
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): iterable {
        $contents = $this->buildConfig($config);
        // AS WE CREATE THE CONFIG IN TEMP LOCATION, WE THEREFORE NEED TO PASS THE PROJECT ROOT HERE.
        $contents['base-path'] = $environment->getProjectConfiguration()->getProjectRootPath();

        $json = json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // dump options to temporary config json.
        $configFile = $environment->getUniqueTempFile($this, 'box.json');
        file_put_contents($configFile, $json);

        $arguments = [
            'compile',
            '--config',
            $configFile,
            '-d',
            $environment->getProjectConfiguration()->getProjectRootPath()
        ];
        if (1 === $environment->getAvailableThreads()) {
            $arguments[] = '--no-parallel';
        }
        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $arguments[] = $value;
            }
        }

        yield $environment
            ->getTaskFactory()
            ->buildRunPhar(
                'box',
                $arguments
            )
            ->withWorkingDirectory($environment->getProjectConfiguration()->getProjectRootPath())
            ->withOutputTransformer($this->createOutputTransformer($contents))
            ->build();
    }

    /** @param mixed $value */
    private function isDefault(string $key, $value): bool
    {
        return array_key_exists($key, static::$defaults) && static::$defaults[$key] === $value;
    }

    private function buildConfig(PluginConfigurationInterface $config): array
    {
        /** @psalm-var array<string,string> $stringMap */
        static $stringMap = [
            'algorithm'         => 'algorithm',
            'alias'             => 'alias',
            'banner'            => 'banner',
            'banner_file'       => 'banner-file',
            // Not supported atm as we need this internally to be able to specify an alternative config.
            // 'base_path'      => 'base-path',
            'chmod'             => 'chmod',
            'compression'       => 'compression',
            'datetime'          => 'datetime',
            'datetime_format'   => 'datetime-format',
            'git'               => 'git',
            'git_commit'        => 'git-commit',
            'git_commit_short'  => 'git-commit-short',
            'git_tag'           => 'git-tag',
            'git_version'       => 'git-version',
            'key'               => 'key',
            'metadata'          => 'metadata',
            'output'            => 'output',
            'php_scoper'        => 'php-scoper',
            'replacement_sigil' => 'replacement-sigil',
        ];

        $contents = [];

        foreach ($stringMap as $configKey => $remappedKey) {
            if ($config->has($configKey) && !$this->isDefault($configKey, $value = $config->getString($configKey))) {
                $contents[$remappedKey] = $value;
            }
        }

        /** @psalm-var array<string,string> $boolMap */
        static $boolMap = [
            'annotations'            => 'annotations',
            'check_requirements'     => 'check-requirements',
            'dump_autoload'          => 'dump-autoload',
            'exclude_composer_files' => 'exclude-composer-files',
            'exclude_dev_files'      => 'exclude-dev-files',
            'force_autodiscovery'    => 'force-autodiscovery',
            'intercept'              => 'intercept',
        ];

        foreach ($boolMap as $configKey => $remappedKey) {
            if ($config->has($configKey) && !$this->isDefault($configKey, $value = $config->getBool($configKey))) {
                $contents[$remappedKey] = $value;
            }
        }

        /** @psalm-var array<string,string> $stringList */
        static $stringList = [
            'blacklist'           => 'blacklist',
            'compactors'          => 'compactors',
            'add_directories'     => 'directories',
            'add_directories_bin' => 'directories-bin',
            'files'               => 'files',
            'files_bin'           => 'files-bin',
        ];

        foreach ($stringList as $configKey => $remappedKey) {
            if (
                $config->has($configKey)
                && !$this->isDefault($configKey, $value = $config->getStringList($configKey))
            ) {
                $contents[$remappedKey] = $value;
            }
        }

        // Finders are special.
        /** @psalm-var array<string,string> $stringList */
        static $optionsList = [
            'finder'              => 'finder',
            'finder_bin'          => 'finder-bin',
        ];

        foreach ($optionsList as $configKey => $remappedKey) {
            if (
                $config->has($configKey)
                && !$this->isDefault($configKey, $value = $config->getOptions($configKey))
            ) {
                $contents[$remappedKey] = $value;
            }
        }

        /** @psalm-var array<string,string> $keyMap */
        static $keyMap = [
            'map' => 'map',
        ];

        $raw = $config->getValue();
        foreach ($keyMap as $configKey => $remappedKey) {
            /** @psalm-suppress MixedAssignment */
            if (null !== $value = $raw[$configKey] ?? null) {
                $contents[$remappedKey] = $value;
            }
        }

        // Specially handled keys here.
        if ($config->has('main')) {
            $value = $config->getString('main');
            // Fixup empty string to false, as we do not allow type invariance in configuration.
            $contents['main'] = ('' === $value) ? false : $value;
        }

        if ($config->has('key_pass')) {
            $value = $config->getString('key_pass');
            // Fixup environment variable usage:
            if ('ENV::' === substr($value, 0, 5)) {
                $value = getenv(substr($value, 5));
            }

            $contents['key-pass'] = $value;
        }

        if ($config->has('shebang')) {
            $value = $config->getString('shebang');
            // Fixup empty string to false, as we do not allow type invariance in configuration.
            $contents['shebang'] = ('' === $value) ? false : $value;
        }

        if ($config->has('stub')) {
            switch ($stubFile = $config->getString('stub')) {
                case 'generated':
                    $contents['stub'] = true;
                    break;
                case '':
                    $contents['stub'] = false;
                    break;
                default:
                    $contents['stub'] = $stubFile;
            }
            unset($stubFile);
        }

        if ($config->has('map')) {
            $contents['map'] = $config->getStringList('map');
        }

        if (
            $config->has('replacements')
            && !$this->isDefault('replacements', $value = $config->getOptions('replacements'))
        ) {
            $contents['replacements'] = (object) $value;
        }

        ksort($contents);

        return $contents;
    }

    private function createOutputTransformer(array $config): OutputTransformerFactoryInterface
    {
        if (isset($config['key-pass'])) {
            // redact gpg-key password from config to prevent it from showing up in the log.
            $config['key-pass'] = 'REDACTED';
        }

        return new class ($config) implements OutputTransformerFactoryInterface {
            /** @var array */
            private $config;

            public function __construct(array $config)
            {
                $this->config = $config;
            }

            public function createFor(TaskReportInterface $report): OutputTransformerInterface
            {
                return new class ($this->config, $report) implements OutputTransformerInterface {
                    /** @var array */
                    private $config;

                    /** @var TaskReportInterface */
                    private $report;

                    /** @var BufferedLineReader */
                    private $buffer;

                    public function __construct(array $config, TaskReportInterface $report)
                    {
                        $this->config = $config;
                        $this->report = $report;
                        $this->buffer = BufferedLineReader::create();
                    }

                    public function write(string $data, int $channel): void
                    {
                        $this->buffer->push($data);
                    }

                    public function finish(int $exitCode): void
                    {
                        $data = [];
                        while (null !== $line = $this->buffer->fetch()) {
                            $data[] = $line;
                        }

                        $config = json_encode(
                            $this->config,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        );
                        $this->report->addAttachment('config.json')->fromString($config)->end();
                        $this->report->addAttachment('execution.log')->fromString(implode("\n", $data))->end();
                        $this->report->close(
                            0 === $exitCode
                                ? TaskReportInterface::STATUS_PASSED
                                : TaskReportInterface::STATUS_FAILED
                        );
                    }
                };
            }
        };
    }
};
