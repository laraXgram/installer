<?php

namespace LaraGram\Installer\Console;

use LaraGram\Console\Command\Command;
use LaraGram\Console\Input\InputArgument;
use LaraGram\Console\Input\InputInterface;
use LaraGram\Console\Input\InputOption;
use LaraGram\Console\Output\OutputInterface;
use LaraGram\Console\Process\Process;
use LaraGram\Console\Prompts\Elements\Element;
use LaraGram\Console\Prompts\Support\Logger;
use LaraGram\Filesystem\Filesystem;
use LaraGram\Installer\Console\Enums\NodePackageManager;
use LaraGram\Support\Composer;
use LaraGram\Support\Process\PhpExecutableFinder;
use LaraGram\Support\ProcessUtils;
use LaraGram\Support\Str;
use RuntimeException;
use Throwable;

use function LaraGram\Filesystem\join_paths;
use function LaraGram\Console\Prompts\callout;
use function LaraGram\Console\Prompts\confirm;
use function LaraGram\Console\Prompts\password;
use function LaraGram\Console\Prompts\select;
use function LaraGram\Console\Prompts\task;
use function LaraGram\Console\Prompts\text;

class NewCommand extends Command
{
    use Concerns\ConfiguresPrompts;

    const DATABASE_DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'];

    /**
     * The Composer instance.
     *
     * @var \LaraGram\Support\Composer
     */
    protected $composer;

    /**
     * Configure the command options and arguments.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new LaraGram application')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Install the latest "development" release')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch that should be created for a new repository', $this->defaultBranch())
            ->addOption('github', null, InputOption::VALUE_OPTIONAL, 'Create a new repository on GitHub', false)
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'The GitHub organization to create the new repository for')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'The database driver your application will use. Possible values are: ' . implode(', ', self::DATABASE_DRIVERS))
            ->addOption('react', null, InputOption::VALUE_NONE, 'Install the React Starter Kit')
            ->addOption('svelte', null, InputOption::VALUE_NONE, 'Install the Svelte Starter Kit')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Install the Vue Starter Kit')
            ->addOption('npm', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies')
            ->addOption('pnpm', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies via PNPM')
            ->addOption('bun', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies via Bun')
            ->addOption('yarn', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies via Yarn')
            ->addOption('no-node', null, InputOption::VALUE_NONE, 'Skip installing and building NPM dependencies')
            ->addOption('using', null, InputOption::VALUE_OPTIONAL, 'Install a custom starter kit from a community maintained package')
            ->addOption('surge', null, InputOption::VALUE_NONE, 'Use LaraGram Surge')
            ->addOption('mtproto', null, InputOption::VALUE_NONE, 'Use MTProto for Client/Userbot development')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    /**
     * Interact with the user and gather installation preferences.
     *
     * @param \LaraGram\Console\Input\InputInterface $input
     * @param \LaraGram\Console\Output\OutputInterface $output
     * @return void
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);
        $this->displayHeader($output);
        $this->ensureExtensionsAreAvailable($input, $output);
        $this->checkForUpdate($input, $output);

        if (! $input->getArgument('name')) {
            $input->setArgument('name', text(
                label: 'What is the name of your project?',
                placeholder: 'E.g. example-app',
                required: 'The project name is required.',
                validate: function ($value) use ($input) {
                    if (preg_match('/[^\pL\pN\-_.]/', $value) !== 0) {
                        return 'The name may only contain letters, numbers, dashes, underscores, and periods.';
                    }

                    if ($input->getOption('force') !== true) {
                        try {
                            $this->verifyApplicationDoesntExist($this->getInstallationDirectory($value));
                        } catch (RuntimeException $e) {
                            return 'Application already exists.';
                        }
                    }
                },
            ));
        }

        if ($input->getOption('force') !== true) {
            $this->verifyApplicationDoesntExist(
                $this->getInstallationDirectory($input->getArgument('name'))
            );
        }

        // Set default fast options...
        if (! $input->getOption('database')) {
            $input->setOption('database', 'sqlite');
        }

        if (! $input->getOption('no-node') &&
            ! $input->getOption('pnpm') &&
            ! $input->getOption('bun') &&
            ! $input->getOption('yarn')) {
            $input->setOption('npm', true);
        }

        // Surge interaction flow
        if (!$this->usingSurge($input)) {
            $useSurge = confirm(
                label: "Do you want to use LaraGram Surge?",
                default: false,
                hint: "This option requires either Swoole or OpenSwoole extension."
            );

            if ($useSurge) {
                // Modified select prompt with direct validation to prevent submission of unavailable drivers
                select(
                    label: 'Which driver do you want to use for LaraGram Surge?',
                    options: [
                        'swoole' => 'Swoole',
                        'openswoole' => 'OpenSwoole',
                    ],
                    default: 'swoole',
                    validate: function ($value) {
                        if (!extension_loaded($value)) {
                            return "The required extension [ext-{$value}] is not installed or enabled in your PHP configuration.";
                        }
                        return null;
                    }
                );

                $input->setOption('surge', true);
            }
        }

        // MTProto interaction flow
        if (!$input->getOption('mtproto')) {
            $useMtproto = confirm(
                label: "Do you want to use MTProto?",
                default: false,
                hint: "Choose 'Yes' if you want to develop client-based userbots."
            );

            if ($useMtproto) {
                // Pre-check MTProto specific extensions during interaction
                $requiredMtproto = ['gmp', 'json', 'mbstring', 'openssl', 'sockets', 'zlib'];
                $missingMtproto = array_filter($requiredMtproto, fn ($ext) => !extension_loaded($ext));
                if (!empty($missingMtproto)) {
                    throw new RuntimeException(
                        sprintf('MTProto requires the following missing PHP extensions: %s', implode(', ', $missingMtproto))
                    );
                }

                $input->setOption('mtproto', true);
            }
        }

        // TMA (Telegram Mini App) / Starter Kit interaction flow
        if (! $this->usingStarterKit($input)) {
            $useTma = confirm('Do you want to develop a Telegram Mini App (TMA)?', default: false);

            if ($useTma) {
                $stack = select(
                    label: 'Which frontend stack do you want to build on?',
                    options: [
                        'react' => 'React',
                        'vue' => 'Vue',
                        'svelte' => 'Svelte',
                    ],
                    info: fn ($value) => match ($value) {
                        'react' => 'LaraGram, Luna, React, Tailwind',
                        'vue' => 'LaraGram, Luna, Vue, Tailwind',
                        'svelte' => 'LaraGram, Luna, Svelte, Tailwind',
                        default => '',
                    },
                    default: 'react',
                    scroll: 10,
                );

                match ($stack) {
                    'react' => $input->setOption('react', true),
                    'vue' => $input->setOption('vue', true),
                    'svelte' => $input->setOption('svelte', true),
                    default => null,
                };
            }
        }
    }

    /**
     * Display the LaraGram header banner with a balanced random color gradient.
     */
    protected function displayHeader(OutputInterface $output): void
    {
        $output->writeln('');

        $lines = [
            "  _                                                  ",
            " | |                                                 ",
            " | |     __ _ _ __ __ _   _____                      ",
            " | |    / _` | '__/ _` | / ____|                     ",
            " | |___| (_| | | | (_| || |  __ _ __ __ _ _ __ ___   ",
            " |______\__,_|_|  \__,_|| | |_ | '__/ _` | '_ ` _ \  ",
            "                        | |__| | | | (_| | | | | | |",
            "                         \_____|_|  \__,_|_| |_| |_|",
        ];

        // Fixed gradients so all 8 indexes flow cohesively with balanced styling
        $gradients = [
            'Red' => [196, 160, 124, 88, 52, 88, 124, 160],
            'Gray' => [255, 251, 247, 244, 241, 238, 244, 251],
            'Ocean' => [39, 45, 51, 81, 75, 69, 75, 81],
            'Vaporwave' => [201, 165, 129, 93, 57, 93, 129, 165],
            'Sunset' => [214, 208, 196, 160, 124, 88, 124, 160],
            'Aurora' => [121, 115, 109, 85, 79, 73, 79, 85],
            'Ember' => [226, 220, 214, 172, 166, 130, 166, 172],
            'Cyberpunk' => [220, 190, 160, 112, 53, 57, 93, 112],
        ];

        $themeName = array_rand($gradients);
        $gradient = $gradients[$themeName];

        foreach ($lines as $index => $line) {
            $color = $gradient[$index];
            $output->writeln("\e[38;5;{$color}m{$line}\e[0m");
        }
    }

    /**
     * Ensure default and standard PHP extensions are available.
     *
     * @throws RuntimeException
     */
    protected function ensureExtensionsAreAvailable(InputInterface $input, OutputInterface $output): void
    {
        $availableExtensions = get_loaded_extensions();

        $missingExtensions = collect([
            'ctype',
            'filter',
            'hash',
            'mbstring',
            'openssl',
            'session',
            'tokenizer',
            'intl'
        ])->reject(fn ($extension) => in_array($extension, $availableExtensions));

        if ($missingExtensions->isEmpty()) {
            return;
        }

        throw new RuntimeException(
            sprintf('The following PHP extensions are required but are not installed: %s', $missingExtensions->join(', ', ', and '))
        );
    }

    /**
     * Check for newer versions of the LaraGram installer.
     */
    protected function checkForUpdate(InputInterface $input, OutputInterface $output)
    {
        $package = 'laraxgram/installer';
        $version = $this->getApplication()->getVersion();
        $versionData = $this->getLatestVersionData($package);

        if ($versionData === false) {
            return;
        }

        $data = json_decode($versionData, true);
        $latestVersion = ltrim($data['packages'][$package][0]['version'], 'v');

        if (version_compare($version, $latestVersion) !== -1) {
            return;
        }

        $output->writeln('');
        $output->writeln("  <bg=yellow;fg=black> WARN </> A new version of the LaraGram installer is available. You have version {$version} installed, the latest version is {$latestVersion}.");

        if (confirm(label: 'Would you like to update now?')) {
            $this->runCommands(
                [
                    'Installer updated' => 'composer global update laraxgram/installer --with-all-dependencies',
                ],
                $input,
                $output,
                taskLabel: 'Updating LaraGram installer',
            );
            $this->proxyLaraGramNew($input, $output);
        }
    }

    /**
     * Confirm update actions and proceed.
     */
    protected function confirmUpdateAndContinue(string $message, InputInterface $input, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln("  {$message}");

        $updated = confirm(
            label: 'Would you like to update now?',
            yes: 'I have updated',
            no: 'Not now',
        );

        if (! $updated) {
            return;
        }

        $this->proxyLaraGramNew($input, $output);
    }

    /**
     * Proxy command call directly onto the newly updated global installer.
     */
    protected function proxyLaraGramNew(InputInterface $input, OutputInterface $output): void
    {
        $output->writeln('');
        $this->runCommands(['laragram '.$input], $input, $output, workingPath: getcwd());
        exit;
    }

    /**
     * Fetch package data from Packagist.
     */
    protected function getLatestVersionData(string $package): string|false
    {
        $packagePrefix = str_replace('/', '-', $package);
        $cachedPath = join_paths(sys_get_temp_dir(), $packagePrefix.'-version-check.json');
        $lastModifiedPath = join_paths(sys_get_temp_dir(), $packagePrefix.'-last-modified');

        $cacheExists = file_exists($cachedPath);
        $lastModifiedExists = file_exists($lastModifiedPath);

        $cacheLastWrittenAt = $cacheExists ? filemtime($cachedPath) : 0;
        $lastModifiedResponse = $lastModifiedExists ? file_get_contents($lastModifiedPath) : null;

        if ($cacheLastWrittenAt > time() - 86400) {
            return file_get_contents($cachedPath);
        }

        $curl = curl_init();
        $headers = ['User-Agent: LaraGram Installer'];

        if ($lastModifiedResponse) {
            $headers[] = "If-Modified-Since: {$lastModifiedResponse}";
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => "https://repo.packagist.org/p2/{$package}.json",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        try {
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $error = curl_error($curl);

            unset($curl);
        } catch (Throwable $e) {
            return false;
        }

        if ($error) {
            return false;
        }

        $responseHeaders = substr($response, 0, $headerSize);
        $result = substr($response, $headerSize);

        $lastModifiedFromResponse = null;

        if (preg_match('/^Last-Modified:\s*(.+)$/mi', $responseHeaders, $matches)) {
            $lastModifiedFromResponse = trim($matches[1]);
        }

        file_put_contents($lastModifiedPath, $lastModifiedFromResponse);

        if ($httpCode === 304 && $cacheExists) {
            touch($cachedPath);
            return file_get_contents($cachedPath);
        }

        if ($httpCode === 200 && $result !== false) {
            file_put_contents($cachedPath, $result);
            return $result;
        }

        return ($cacheExists) ? file_get_contents($cachedPath) : false;
    }

    /**
     * Execute the installation of LaraGram application.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateDatabaseOption($input);

        // Perform programmatic checks for Surge and MTProto requirements if passed through command options
        if ($input->getOption('surge')) {
            $hasSwoole = extension_loaded('swoole');
            $hasOpenSwoole = extension_loaded('openswoole');
            if (!$hasSwoole && !$hasOpenSwoole) {
                throw new RuntimeException('LaraGram Surge requires either Swoole or OpenSwoole extension.');
            }
        }

        if ($input->getOption('mtproto')) {
            $requiredMtproto = ['gmp', 'json', 'mbstring', 'openssl', 'sockets', 'zlib'];
            $missingMtproto = array_filter($requiredMtproto, fn ($ext) => !extension_loaded($ext));
            if (!empty($missingMtproto)) {
                throw new RuntimeException(
                    sprintf('The MTProto component requires the following missing PHP extensions: %s', implode(', ', $missingMtproto))
                );
            }
        }

        $name = rtrim($input->getArgument('name'), '/\\');
        $directory = $this->getInstallationDirectory($name);

        $this->composer = new Composer(new Filesystem, $directory);
        $version = $this->getVersion($input);

        if (! $input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        if ($input->getOption('force') && $directory === '.') {
            throw new RuntimeException('Cannot use --force option when using current directory for installation!');
        }

        $composer = $this->findComposer();
        $phpBinary = $this->phpBinary();

        // Match options to correct starter kits or standard installer package
        $starterKit = $this->getStarterKit($input);
        $createProjectCommand = $composer." create-project laraxgram/laragram \"$directory\" $version --remove-vcs --prefer-dist --no-scripts";

        if ($starterKit) {
            $createProjectCommand = $composer." create-project {$starterKit} \"{$directory}\" --stability=dev";

            if (! $this->usingLaraGramStarterKit($input) && str_contains($starterKit, '://')) {
                $createProjectCommand = 'npx tiged@latest '.$starterKit.' "'.$directory.'" && cd "'.$directory.'" && composer install';
            }
        }

        $commands = [];

        if ($directory != '.' && $input->getOption('force')) {
            $forceLabel = "Removed existing directory [{$name}]";
            if (PHP_OS_FAMILY == 'Windows') {
                $commands[$forceLabel] = "(if exist \"$directory\" rd /s /q \"$directory\")";
            } else {
                $commands[$forceLabel] = "rm -rf \"$directory\"";
            }
        }

        $commands['Application installed'] = $createProjectCommand;

        $appInitializedLabel = 'Application initialized';
        $commands[$appInitializedLabel] = [
            $composer." run post-root-package-install -d \"$directory\"",
            $phpBinary." \"$directory/laragram\" key:generate --ansi",
        ];

        if (PHP_OS_FAMILY != 'Windows') {
            $commands[$appInitializedLabel][] = "chmod 755 \"$directory/laragram\"";
        }

        // Install Application
        if (($process = $this->runCommands(
            $commands,
            $input,
            $output,
            env: ['LARAGRAM_INSTALLER_DEFER_HOOKS' => '1'],
            taskLabel: 'Creating LaraGram application',
        ))->isSuccessful()) {

            // Install custom optional components (Surge and/or MTProto)
            $additionalPackages = [];
            if ($input->getOption('surge')) {
                $additionalPackages[] = 'laraxgram/surge';
            }
            if ($input->getOption('mtproto')) {
                $additionalPackages[] = 'laraxgram/mtproto';
            }

            if (!empty($additionalPackages)) {
                $packagesList = implode(' ', $additionalPackages);
                $this->runCommands(
                    ['Installing additional packages' => $composer." require {$packagesList} -d \"{$directory}\" --no-interaction"],
                    $input,
                    $output,
                    taskLabel: 'Installing additional LaraGram components'
                );
            }

            $hooksProcess = $this->runInstallerHooks($directory, $input, $output);
            if ($hooksProcess && ! $hooksProcess->isSuccessful()) {
                return $hooksProcess->getExitCode();
            }

            if ($name !== '.') {
                $this->pregReplaceInFile(
                    '/^APP_URL=http:\/\/localhost$/m',
                    'APP_URL=http://localhost:9000',
                    $directory.'/.env'
                );

                [$database, $migrate] = $this->promptForDatabaseOptions($directory, $input);
                $this->configureDefaultDatabaseConnection($directory, $database, $name);

                if ($migrate) {
                    if ($database === 'sqlite') {
                        touch($directory.'/database/database.sqlite');
                    }

                    $commands = [
                        'Database migrated' => trim(sprintf(
                            $this->phpBinary().' laragram migrate %s',
                            ! $input->isInteractive() ? '--no-interaction' : '',
                        )),
                    ];

                    $this->runCommands(
                        $commands,
                        $input,
                        $output,
                        workingPath: $directory,
                        taskLabel: 'Running database migrations',
                    );
                }
            }

            if ($input->getOption('git') || $input->getOption('github') !== false) {
                $this->createRepository($directory, $input, $output);
            }

            if ($input->getOption('github') !== false) {
                $this->pushToGitHub($name, $directory, $input, $output);
                $output->writeln('');
            }

            // Set up frontend and packages
            [$packageManager, $runPackageManager] = match (true) {
                $input->getOption('no-node') => [NodePackageManager::NPM, false],
                default => $this->installNodeDependencies($directory, $input, $output),
            };

            // Set credentials & run initialization scripts
            $this->configureTelegramCredentials($directory, $input, $output);

            // Print final instructions
            $getStartedSteps = ["cd {$name}"];

            if ($input->getOption('mtproto')) {
                $getStartedSteps[] = 'php laragram client:start';
            } else {
                if (! $runPackageManager && ! $input->getOption('no-node') && $this->usingStarterKit($input)) {
                    $getStartedSteps[] = $packageManager->installCommand().' && '.$packageManager->buildCommand();
                }
                $getStartedSteps[] = 'php laragram serve';
            }

            if (function_exists('LaraGram\Console\Prompts\callout')) {
                callout(
                    label: 'Application ready',
                    content: [
                        'You can start your local development using:',
                        Element::numberedList($getStartedSteps),
                        $output->getFormatter()->format('<options=bold>New to LaraGram?</>')
                        .' Check out our '.Element::link(
                            'https://laraxgram.github.io/installation#next-steps',
                            'documentation'
                        ).'.',
                        Element::heading('Build something amazing!'),
                    ],
                );
            } else {
                $output->writeln('');
                $output->writeln(' <fg=cyan;options=bold>New to LaraGram?</> Check out our <href=https://laraxgram.github.io/installation#next-steps;options=underscore>documentation</>. <options=bold>Build something amazing!</>');
                $output->writeln('');
            }
        }

        return $process->getExitCode();
    }

    /**
     * Gather API, Token, Client, or Userbot parameters and run configuration scripts.
     */
    protected function configureTelegramCredentials(string $directory, InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('mtproto')) {
            // Get credentials for MTProto Setup
            $apiId = password(
                label: 'Enter your Telegram API ID: ',
                hint: 'Press <fg=yellow>Enter</> <fg=gray>to Skip...</>'
            );

            $apiHash = password(
                label: 'Enter your Telegram API HASH: ',
                hint: 'Press <fg=yellow>Enter</> <fg=gray>to Skip...</>'
            );

            if ($apiId !== '') {
                $this->pregReplaceInFile('/^API_ID=.*/m', "API_ID=\"{$apiId}\"", $directory.'/.env');
            }

            if ($apiHash !== '') {
                $this->pregReplaceInFile('/^API_HASH=.*/m', "API_HASH=\"{$apiHash}\"", $directory.'/.env');
            }

            $this->runCommands([
                $this->phpBinary(). ' vendor/bin/compile-tl.php',
                $this->phpBinary(). ' laragram install:client',
                $this->phpBinary() . ' laragram vendor:publish --tag=mtproto-config'
            ], $input, $output, workingPath: $directory, taskLabel: "Initialize MTProto");
        } else {
            // Get credentials for standard Bot token and webhook
            $token = password(
                label: 'Enter your Bot Token: ',
                hint: 'Press <fg=yellow>Enter</> <fg=gray>to Skip...</>'
            );

            $url = text(
                label: 'Enter your Bot URL: ',
                hint: 'Press <fg=yellow>Enter</> <fg=gray>to Skip...</>'
            );

            if ($token !== '') {
                $this->pregReplaceInFile('/^BOT_TOKEN=.*/m', "BOT_TOKEN=\"{$token}\"", $directory.'/.env');
            }

            if ($url !== '') {
                $this->pregReplaceInFile('/^BOT_URL=.*/m', "BOT_URL=\"{$url}\"", $directory.'/.env');
            }

            if ($token !== '' && $url !== '') {
                if (confirm(label: "Do you want to set webhook?")) {
                    // Running webhook set directly to ensure reliable interactive inputs
                    $this->runCommands([
                        $this->phpBinary() . " laragram webhook:set"
                    ], $input, $output, workingPath: $directory);
                }
            }
        }
    }

    /**
     * Determine the correct NPM package manager to run.
     *
     * @return array{NodePackageManager, bool}
     */
    protected function determinePackageManager(string $directory, InputInterface $input): array
    {
        if ($input->getOption('pnpm')) {
            return [NodePackageManager::PNPM, true];
        }

        if ($input->getOption('bun')) {
            return [NodePackageManager::BUN, true];
        }

        if ($input->getOption('yarn')) {
            return [NodePackageManager::YARN, true];
        }

        if ($input->getOption('npm')) {
            return [NodePackageManager::NPM, true];
        }

        foreach (NodePackageManager::cases() as $packageManager) {
            if ($packageManager === NodePackageManager::NPM) {
                continue;
            }

            foreach ($packageManager->lockFiles() as $lockFile) {
                if (file_exists($directory.'/'.$lockFile)) {
                    return [$packageManager, false];
                }
            }
        }

        return [NodePackageManager::NPM, false];
    }

    /**
     * Get the default local branch configuration.
     *
     * @return string
     */
    protected function defaultBranch()
    {
        $process = new Process(['git', 'config', '--global', 'init.defaultBranch']);
        $process->run();
        $output = trim($process->getOutput());

        return $process->isSuccessful() && $output ? $output : 'main';
    }

    /**
     * Configure default database parameters.
     *
     * @return void
     */
    protected function configureDefaultDatabaseConnection(string $directory, string $database, string $name)
    {
        $this->pregReplaceInFile(
            '/DB_CONNECTION=.*/',
            'DB_CONNECTION='.$database,
            $directory.'/.env'
        );

        $this->pregReplaceInFile(
            '/DB_CONNECTION=.*/',
            'DB_CONNECTION='.$database,
            $directory.'/.env.example'
        );

        if ($database === 'sqlite') {
            $environment = file_get_contents($directory.'/.env');

            if (! str_contains($environment, '# DB_HOST=127.0.0.1')) {
                $this->commentDatabaseConfigurationForSqlite($directory);
                return;
            }
            return;
        }

        $this->uncommentDatabaseConfiguration($directory);

        $defaultPorts = [
            'pgsql' => '5432',
            'sqlsrv' => '1433',
        ];

        if (isset($defaultPorts[$database])) {
            $this->replaceInFile(
                'DB_PORT=3306',
                'DB_PORT='.$defaultPorts[$database],
                $directory.'/.env'
            );

            $this->replaceInFile(
                'DB_PORT=3306',
                'DB_PORT='.$defaultPorts[$database],
                $directory.'/.env.example'
            );
        }

        $this->replaceInFile(
            'DB_DATABASE=laragram',
            'DB_DATABASE='.str_replace('-', '_', strtolower($name)),
            $directory.'/.env'
        );

        $this->replaceInFile(
            'DB_DATABASE=laragram',
            'DB_DATABASE='.str_replace('-', '_', strtolower($name)),
            $directory.'/.env.example'
        );
    }

    /**
     * Comment out extra database variables for SQLite builds.
     */
    protected function commentDatabaseConfigurationForSqlite(string $directory): void
    {
        $defaults = [
            'DB_HOST=127.0.0.1',
            'DB_PORT=3306',
            'DB_DATABASE=laragram',
            'DB_USERNAME=root',
            'DB_PASSWORD=',
        ];

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => "# {$default}")->all(),
            $directory.'/.env'
        );

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => "# {$default}")->all(),
            $directory.'/.env.example'
        );
    }

    /**
     * Uncomment database variables for non-SQLite installations.
     */
    protected function uncommentDatabaseConfiguration(string $directory)
    {
        $defaults = [
            '# DB_HOST=127.0.0.1',
            '# DB_PORT=3306',
            '# DB_DATABASE=laragram',
            '# DB_USERNAME=root',
            '# DB_PASSWORD=',
        ];

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => substr($default, 2))->all(),
            $directory.'/.env'
        );

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => substr($default, 2))->all(),
            $directory.'/.env.example'
        );
    }

    /**
     * Choose and configure the fallback or specified database driver.
     */
    protected function promptForDatabaseOptions(string $directory, InputInterface $input)
    {
        $databaseOptions = $this->databaseOptions();
        $defaultDatabase = collect($databaseOptions)->keys()->first();

        if (! $input->getOption('database') && $this->usingStarterKit($input)) {
            $migrate = false;
            $input->setOption('database', 'sqlite');
        }

        if (! $input->getOption('database') && $input->isInteractive()) {
            $input->setOption('database', select(
                label: 'Which database will your application use?',
                options: $databaseOptions,
                default: $defaultDatabase,
            ));

            if ($input->getOption('database') !== 'sqlite') {
                $migrate = confirm(
                    label: 'Default database updated. Would you like to run the default database migrations?'
                );
            } else {
                $migrate = true;
            }
        }

        return [$input->getOption('database') ?? $defaultDatabase, $migrate ?? $input->hasOption('database')];
    }

    /**
     * Get a list of supported database engines.
     */
    protected function databaseOptions(): array
    {
        return collect([
            'sqlite' => ['SQLite', extension_loaded('pdo_sqlite')],
            'mysql' => ['MySQL', extension_loaded('pdo_mysql')],
            'mariadb' => ['MariaDB', extension_loaded('pdo_mysql')],
            'pgsql' => ['PostgreSQL', extension_loaded('pdo_pgsql')],
            'sqlsrv' => ['SQL Server', extension_loaded('pdo_sqlsrv')],
        ])
            ->sortBy(fn ($database) => $database[1] ? 0 : 1)
            ->map(fn ($database) => $database[0].($database[1] ? '' : ' (Missing PDO extension)'))
            ->all();
    }

    /**
     * Validate the chosen database option.
     */
    protected function validateDatabaseOption(InputInterface $input)
    {
        if ($input->getOption('database') && ! in_array($input->getOption('database'), self::DATABASE_DRIVERS)) {
            throw new \InvalidArgumentException("Invalid database driver [{$input->getOption('database')}]. Possible values are: ".implode(', ', self::DATABASE_DRIVERS).'.');
        }
    }

    /**
     * Install required node assets.
     */
    protected function installNodeDependencies(string $directory, InputInterface $input, OutputInterface $output): array
    {
        [$packageManager, $runPackageManager] = $this->determinePackageManager($directory, $input);
        $this->configureComposerScripts($packageManager);

        if (! $runPackageManager && $input->isInteractive()) {
            $runPackageManager = confirm(
                label: 'Would you like to run <options=bold>'.$packageManager->installCommand().'</> and <options=bold>'.$packageManager->buildCommand().'</>?'
            );
        }

        foreach (NodePackageManager::allLockFiles() as $lockFile) {
            if (! in_array($lockFile, $packageManager->lockFiles()) && file_exists($directory.'/'.$lockFile)) {
                (new Filesystem)->delete($directory.'/'.$lockFile);
            }
        }

        if ($runPackageManager) {
            $this->runCommands(
                [
                    'Packages installed' => $packageManager->installCommand(),
                    'Assets built' => $packageManager->buildCommand(),
                ],
                $input,
                $output,
                workingPath: $directory,
                taskLabel: 'Setting up frontend dependencies with '.$packageManager->value,
            );
        }

        return [$packageManager, $runPackageManager];
    }

    /**
     * Initialize Git repository and make initial commit.
     */
    protected function createRepository(string $directory, InputInterface $input, OutputInterface $output)
    {
        $branch = $input->getOption('branch') ?: $this->defaultBranch();

        $commands = [
            'Repository initialized' => [
                'git init -q',
                'git add .',
                'git commit -q -m "Set up a fresh LaraGram app"',
                "git branch -M {$branch}",
            ],
        ];

        $this->runCommands(
            $commands,
            $input,
            $output,
            workingPath: $directory,
            taskLabel: 'Initializing Git repository',
        );
    }

    /**
     * Add post-install commits to git.
     */
    protected function commitChanges(string $message, string $directory, InputInterface $input, OutputInterface $output)
    {
        if (! $input->getOption('git') && $input->getOption('github') === false) {
            return;
        }

        $commands = [
            'Changes committed' => [
                'git add .',
                "git commit -q -m \"$message\"",
            ],
        ];

        $this->runCommands(
            $commands,
            $input,
            $output,
            workingPath: $directory,
            taskLabel: 'Committing changes',
        );
    }

    /**
     * Create and push your code to GitHub repository.
     */
    protected function pushToGitHub(string $name, string $directory, InputInterface $input, OutputInterface $output)
    {
        $process = new Process(['gh', 'auth', 'status']);
        $process->run();

        if (! $process->isSuccessful()) {
            $output->writeln('  <bg=yellow;fg=black> WARN </> Make sure the "gh" CLI tool is installed and that you\'re authenticated to GitHub. Skipping...'.PHP_EOL);
            return;
        }

        $name = $input->getOption('organization') ? $input->getOption('organization')."/$name" : $name;
        $flags = $input->getOption('github') ?: '--private';

        $commands = [
            'Repository pushed' => "gh repo create {$name} --source=. --push {$flags}",
        ];

        $this->runCommands(
            $commands,
            $input,
            $output,
            workingPath: $directory,
            env: ['GIT_TERMINAL_PROMPT' => 0],
            taskLabel: "Pushing to GitHub [{$name}]",
        );
    }

    /**
     * Change standard compose run configs based on your Node package manager choice.
     */
    protected function configureComposerScripts(NodePackageManager $packageManager): void
    {
        $this->composer->modify(function (array $content) use ($packageManager) {
            if (windows_os()) {
                $content['scripts']['dev'] = [
                    'Composer\\Config::disableProcessTimeout',
                    "npx concurrently -c \"#93c5fd,#c4b5fd,#fdba74\" \"php laragram serve\" \"php laragram queue:listen --tries=1\" \"npm run dev\" --names='server,queue,vite'",
                ];
            }

            foreach (['dev', 'dev:ssr', 'setup'] as $scriptKey) {
                if (array_key_exists($scriptKey, $content['scripts'])) {
                    $content['scripts'][$scriptKey] = str_replace(
                        ['npm', 'npx', 'ppnpm'],
                        [$packageManager->value, $packageManager->runLocalOrRemoteCommand(), 'pnpm'],
                        $content['scripts'][$scriptKey],
                    );
                }
            }

            return $content;
        });
    }

    /**
     * Run scripts configured within composer.json scripts blocks.
     */
    protected function runInstallerHooks(string $directory, InputInterface $input, OutputInterface $output): ?Process
    {
        $commands = $this->installerHooks($directory, 'post-create-project');

        if ($commands === []) {
            return null;
        }

        return $this->runCommands(
            $commands,
            $input,
            $output,
            workingPath: $directory,
            taskLabel: 'Running starter kit hooks',
        );
    }

    /**
     * Resolve hooks within custom starter kit dependencies.
     */
    protected function installerHooks(string $directory, string $hook): array
    {
        $composerJson = $directory.'/composer.json';

        if (! file_exists($composerJson)) {
            return [];
        }

        $composer = json_decode(file_get_contents($composerJson), true) ?: [];

        return collect($composer['extra']['laragram']['installer'][$hook] ?? [])
            ->filter(fn ($command) => is_string($command) && $command !== '')
            ->map(fn ($command) => $this->normalizeInstallerHookCommand($command))
            ->values()
            ->all();
    }

    /**
     * Helper mapping aliases (like @php) to explicit values.
     */
    protected function normalizeInstallerHookCommand(string $command): string
    {
        $replace = [
            'php' => fn () => $this->phpBinary(),
            'composer' => fn () => $this->findComposer(),
        ];

        foreach ($replace as $find => $binary) {
            $marker = "@{$find}";

            if (str_starts_with($command, "{$marker} ")) {
                return $binary().' '.substr($command, strlen("{$marker} "));
            }

            if ($command === $marker) {
                return $binary();
            }
        }

        return $command;
    }

    /**
     * Verify that no files are occupying target project path.
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    /**
     * Match user option to starter kit repository path.
     */
    protected function getStarterKit(InputInterface $input): ?string
    {
        return match (true) {
            $input->getOption('react') => 'laraxgram/react-starter-kit',
            $input->getOption('svelte') => 'laraxgram/svelte-starter-kit',
            $input->getOption('vue') => 'laraxgram/vue-starter-kit',
            default => $input->getOption('using'),
        };
    }

    /**
     * Check if chosen starter kit is published under LaraGram organization namespace.
     */
    protected function usingLaraGramStarterKit(InputInterface $input): bool
    {
        return $this->usingStarterKit($input) &&
            str_starts_with($this->getStarterKit($input), 'laraxgram/');
    }

    /**
     * Determine if a starter kit package of any type is requested.
     */
    protected function usingStarterKit(InputInterface $input)
    {
        return $input->getOption('react') || $input->getOption('svelte') || $input->getOption('vue') || $input->getOption('using');
    }

    /**
     * Determine if local resolution is configured for a hostname.
     */
    protected function canResolveHostname($hostname)
    {
        return gethostbyname($hostname.'.') !== $hostname.'.';
    }

    /**
     * Retrieve targeted installation subdirectory structure.
     */
    protected function getInstallationDirectory(string $name)
    {
        if ($name === '.') {
            return '.';
        }

        return str_starts_with($name, DIRECTORY_SEPARATOR) ? $name : getcwd().'/'.$name;
    }

    /**
     * Retrieve target LaraGram core distribution branch.
     */
    protected function getVersion(InputInterface $input)
    {
        if ($input->getOption('dev')) {
            return 'dev-master';
        }

        return '';
    }

    /**
     * Locate composer configuration binary path.
     */
    protected function findComposer()
    {
        return implode(' ', $this->composer->findComposer());
    }

    /**
     * Locate current dynamic PHP interpreter binary.
     */
    protected function phpBinary()
    {
        $phpBinary = function_exists('LaraGram\Support\php_binary')
            ? \LaraGram\Support\php_binary()
            : (new PhpExecutableFinder)->find(false);

        return $phpBinary !== false
            ? ProcessUtils::escapeArgument($phpBinary)
            : 'php';
    }

    /**
     * Execute array of subcommands sequentially in terminal shell.
     */
    protected function runCommands(
        $commands,
        InputInterface $input,
        OutputInterface $output,
        ?string $workingPath = null,
        array $env = [],
        ?string $taskLabel = null,
    ) {
        $commands = array_map(fn ($value) => (is_array($value)) ? $value : [$value], $commands);

        if (! $output->isDecorated()) {
            $commands = array_map(
                fn ($values) => array_map(function ($value) {
                    if (Str::startsWith($value, ['chmod', 'rm', 'git'])) {
                        return $value;
                    }
                    return $value.' --no-ansi';
                }, $values),
                $commands,
            );
        }

        if ($input->getOption('quiet')) {
            $commands = array_map(
                fn ($values) => array_map(function ($value) {
                    if (Str::startsWith($value, ['chmod', 'rm', 'git'])) {
                        return $value;
                    }
                    return $value.' --quiet';
                }, $values),
                $commands,
            );
        }

        if ($this->shouldRunAsTask($output, $commands)) {
            return $this->runCommandsAsTask($commands, $workingPath, $env, $taskLabel);
        }

        $commandline = implode(' && ', array_map(fn ($values) => implode(' && ', $values), $commands));

        if ('\\' === DIRECTORY_SEPARATOR && $input->isInteractive() && ! Process::isTtySupported()) {
            return $this->runCommandsInteractivelyOnWindows($commandline, $workingPath, $env);
        }

        $process = Process::fromShellCommandline($commandline, $workingPath, $env, null, null);

        if (Process::isTtySupported() && $input->isInteractive()) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('  <bg=yellow;fg=black> WARN </> '.$e->getMessage().PHP_EOL);
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    '.$line);
        });

        return $process;
    }

    /**
     * Determine if CLI commands should render as a unified progress block task.
     */
    protected function shouldRunAsTask(OutputInterface $output, array $commands): bool
    {
        return function_exists('LaraGram\Console\Prompts\task')
            && function_exists('pcntl_fork')
            && ! array_is_list($commands)
            && $this->useConciseOutput($output);
    }

    /**
     * Proxy array of commands through the LaraGram console tasks module.
     */
    protected function runCommandsAsTask(
        array $commands,
        ?string $workingPath,
        array $env,
        ?string $taskLabel = null,
    ): Process {
        return task(
            label: $taskLabel ? str($taskLabel)->finish('...') : '',
            keepSummary: true,
            callback: function (Logger $logger) use ($commands, $workingPath, $env, $taskLabel) {
                foreach ($commands as $label => $subCommands) {
                    foreach ($subCommands as $command) {
                        $logger->subLabel($command);

                        $process = Process::fromShellCommandline($command, $workingPath, $env, null, null);
                        $process->run(function ($type, $line) use ($logger) {
                            $logger->line($line);
                        });

                        if (! $process->isSuccessful()) {
                            $logger->error($label);
                            $logger->error('Command failed: '.$command);
                            $logger->error('Error output: '.trim($process->getErrorOutput()));

                            return $process;
                        }
                    }

                    $logger->success($label);
                }

                if ($taskLabel) {
                    $logger->label($taskLabel);
                }

                return $process;
            },
        );
    }

    /**
     * Proxy shell command processes on Windows with local STDIN descriptors.
     */
    protected function runCommandsInteractivelyOnWindows(string $commandline, ?string $workingPath, array $env): Process
    {
        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $envPairs = $env !== [] ? array_filter(array_merge($_SERVER, $_ENV, $env), fn ($v) => ! is_array($v)) : null;
        $proc = proc_open($commandline, $descriptors, $pipes, $workingPath, $envPairs);

        if (is_resource($proc)) {
            $exitCode = proc_close($proc);
        } else {
            $exitCode = 1;
        }

        $sentinel = Process::fromShellCommandline('exit '.$exitCode, $workingPath);
        $sentinel->run();

        return $sentinel;
    }

    /**
     * Replace content block using relative stub resources.
     */
    protected function replaceFile(string $replace, string $file)
    {
        $stubs = dirname(__DIR__).'/stubs';

        file_put_contents(
            $file,
            $stubs . "/" . $replace,
        );
    }

    /**
     * Swap simple search key with replacements inside a text file.
     */
    protected function replaceInFile(string|array $search, string|array $replace, string $file)
    {
        file_put_contents(
            $file,
            str_replace($search, $replace, file_get_contents($file))
        );
    }

    /**
     * Swap regex patterns with customized string formats inside a text file.
     */
    protected function pregReplaceInFile(string $pattern, string $replace, string $file)
    {
        file_put_contents(
            $file,
            preg_replace($pattern, $replace, file_get_contents($file))
        );
    }

    /**
     * Delete filesystem item from workspace.
     */
    protected function deleteFile(string $file)
    {
        unlink($file);
    }

    /**
     * Determine output dynamic verbosity context.
     */
    protected function useConciseOutput(OutputInterface $output): bool
    {
        return $output->getVerbosity() === OutputInterface::VERBOSITY_NORMAL || $output->isQuiet();
    }

    /**
     * Determine if LaraGram Surge integration flag was passed.
     */
    protected function usingSurge(InputInterface $input)
    {
        return $input->getOption('surge');
    }
}