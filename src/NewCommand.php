<?php

namespace LaraGram\Installer\Console;

use LaraGram\Console\Command\Command;
use LaraGram\Console\Input\InputArgument;
use LaraGram\Console\Input\InputInterface;
use LaraGram\Console\Input\InputOption;
use LaraGram\Console\Output\OutputInterface;
use LaraGram\Console\Process\ExecutableFinder;
use LaraGram\Console\Process\Process;
use LaraGram\Console\Prompts\Elements\Element;
use LaraGram\Console\Prompts\Prompt;
use LaraGram\Console\Prompts\Support\Logger;
use LaraGram\Filesystem\Filesystem;
use LaraGram\Installer\Console\Enums\NodePackageManager;
use LaraGram\Support\Composer;
use LaraGram\Support\Process\PhpExecutableFinder;
use LaraGram\Support\ProcessUtils;
use LaraGram\Support\Str;
use RuntimeException;
use Throwable;

use function LaraGram\Console\Prompts\callout;
use function LaraGram\Console\Prompts\confirm;
use function LaraGram\Console\Prompts\form;
use function LaraGram\Console\Prompts\password;
use function LaraGram\Console\Prompts\task;
use function LaraGram\Console\Prompts\text;
use function LaraGram\Filesystem\join_paths;

class NewCommand extends Command
{
    use Concerns\ConfiguresPrompts;
    use Concerns\InteractsWithTelegram;

    const DATABASE_DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'];

    const SURGE_SERVERS = ['swoole', 'roadrunner', 'frankenphp'];

    const MTPROTO_EXTENSIONS = ['gmp', 'json', 'mbstring', 'openssl', 'sockets', 'zlib'];

    /**
     * The Composer instance.
     *
     * @var \LaraGram\Support\Composer
     */
    protected $composer;

    /**
     * The agent context, encapsulating detection and JSON output behavior.
     */
    protected Agent $agent;

    /**
     * Whether the default database migrations should be run.
     */
    protected bool $migrate = true;

    /**
     * Detect agents, suppress interactive output, and emit a JSON result.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->agent = new Agent;

        if (! $this->agent->isActive()) {
            return parent::run($input, $output);
        }

        $input->setInteractive(false);

        $logOutput = $this->agent->openLog();

        Prompt::setOutput($logOutput);

        try {
            $exitCode = parent::run($input, $logOutput);
        } catch (Throwable $e) {
            $this->agent->emitFailure(['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        if ($exitCode === self::SUCCESS) {
            $this->agent->emitSuccess();
        } else {
            $this->agent->emitFailure();
        }

        return $exitCode;
    }

    /**
     * Configure the command options.
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
            ->addOption('github', null, InputOption::VALUE_OPTIONAL, 'Create a new repository on GitHub, optionally for the given owner or with the given "gh repo create" flags', false)
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'The GitHub organization to create the new repository for')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'The Telegram bot token from @BotFather')
            ->addOption('bot-url', null, InputOption::VALUE_REQUIRED, 'The public HTTPS URL Telegram should send updates to')
            ->addOption('webhook', null, InputOption::VALUE_NONE, 'Set the bot webhook after installation')
            ->addOption('mtproto', null, InputOption::VALUE_NONE, 'Install MTProto for userbot / client development')
            ->addOption('api-id', null, InputOption::VALUE_REQUIRED, 'The Telegram API ID from my.telegram.org')
            ->addOption('api-hash', null, InputOption::VALUE_REQUIRED, 'The Telegram API hash from my.telegram.org')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'The database driver your application will use. Possible values are: '.implode(', ', self::DATABASE_DRIVERS))
            ->addOption('surge', null, InputOption::VALUE_OPTIONAL, 'Install LaraGram Surge with the given server. Possible values are: '.implode(', ', self::SURGE_SERVERS), false)
            ->addOption('react', null, InputOption::VALUE_NONE, 'Install the React Mini App starter kit')
            ->addOption('svelte', null, InputOption::VALUE_NONE, 'Install the Svelte Mini App starter kit')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Install the Vue Mini App starter kit')
            ->addOption('using', null, InputOption::VALUE_OPTIONAL, 'Install a custom starter kit from a community maintained package')
            ->addOption('npm', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies')
            ->addOption('pnpm', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies via PNPM')
            ->addOption('bun', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies via Bun')
            ->addOption('yarn', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies via Yarn')
            ->addOption('no-node', null, InputOption::VALUE_NONE, 'Skip installing and building NPM dependencies')
            ->addOption('brain', null, InputOption::VALUE_NONE, 'Install LaraGram Brain to improve AI assisted coding')
            ->addOption('no-brain', null, InputOption::VALUE_NONE, 'Skip LaraGram Brain installation')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    /**
     * Interact with the user before validating the input.
     *
     * Every question is asked up front, so the installation itself runs unattended.
     *
     * @return void
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);
        $this->displayHeader($output);
        $this->ensureExtensionsAreAvailable($input, $output);
        $this->checkForUpdate($input, $output);

        if ($input->getArgument('name') && $input->getOption('force') !== true) {
            $this->verifyApplicationDoesntExist(
                $this->getInstallationDirectory($input->getArgument('name'))
            );
        }

        if (! $input->getOption('no-brain')) {
            $input->setOption('brain', true);
        }

        $form = form();

        if (! $input->getArgument('name')) {
            $form->text(
                label: 'What is the name of your project?',
                placeholder: 'E.g. example-bot',
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
                name: 'name',
            );
        }

        // Mini App starter kit...
        if (! $this->usingStarterKit($input)) {
            $form->select(
                label: 'Would you like to build a Telegram Mini App?',
                options: [
                    'none' => 'No',
                    'react' => 'Yes, with React',
                    'vue' => 'Yes, with Vue',
                    'svelte' => 'Yes, with Svelte',
                ],
                default: 'none',
                name: 'starterKit',
            );
        }

        // Bot API...
        if (! $input->getOption('token')) {
            $form->password(
                label: 'What is your bot token?',
                validate: fn ($value) => $value === '' ? null : $this->verifyBotToken($value),
                hint: 'Get one from @BotFather, or press Enter to skip.',
                name: 'token',
            );
        }

        if (! $input->getOption('bot-url')) {
            $form->addIf(
                fn ($responses) => ($responses['token'] ?? $input->getOption('token')) !== '',
                fn ($responses, $previousResponse) => text(
                    label: 'What is the public URL of your bot?',
                    placeholder: 'E.g. https://example.com',
                    default: $previousResponse ?? '',
                    validate: fn ($value) => $value === '' || $this->isValidWebhookUrl($value)
                        ? null
                        : 'The URL must be a valid HTTPS URL.',
                    hint: 'Telegram sends updates to this URL. Press Enter to skip.',
                ),
                name: 'botUrl',
            );
        }

        if (! $input->getOption('webhook')) {
            $form->addIf(
                fn ($responses) => ($responses['token'] ?? $input->getOption('token')) !== ''
                    && ($responses['botUrl'] ?? $input->getOption('bot-url')) !== '',
                fn ($responses, $previousResponse) => confirm(
                    label: 'Would you like to set the webhook after installation?',
                    default: $previousResponse ?? true,
                ),
                name: 'webhook',
            );
        }

        // MTProto...
        if (! $input->getOption('mtproto')) {
            $form->confirm(
                label: 'Would you like to use MTProto for userbot / client development?',
                default: false,
                validate: fn ($value) => $value ? $this->missingExtensionsMessage('MTProto', self::MTPROTO_EXTENSIONS) : null,
                name: 'mtproto',
            );
        }

        if (! $input->getOption('api-id')) {
            $form->addIf(
                fn ($responses) => $responses['mtproto'] ?? $input->getOption('mtproto'),
                fn ($responses, $previousResponse) => text(
                    label: 'What is your Telegram API ID?',
                    default: $previousResponse ?? '',
                    validate: fn ($value) => $value === '' || ctype_digit($value) ? null : 'The API ID must be numeric.',
                    hint: 'Get it from my.telegram.org, or press Enter to skip.',
                ),
                name: 'apiId',
            );
        }

        if (! $input->getOption('api-hash')) {
            $form->addIf(
                fn ($responses) => $responses['mtproto'] ?? $input->getOption('mtproto'),
                fn () => password(
                    label: 'What is your Telegram API hash?',
                    validate: fn ($value) => $value === '' || preg_match('/^[a-f0-9]{32}$/i', $value) === 1
                        ? null
                        : 'The API hash must be a 32 character hexadecimal string.',
                    hint: 'Get it from my.telegram.org, or press Enter to skip.',
                ),
                name: 'apiHash',
            );
        }

        // Database...
        if (! $input->getOption('database')) {
            $form->select(
                label: 'Which database will your application use?',
                options: $this->databaseOptions(),
                default: 'sqlite',
                validate: fn ($value) => extension_loaded($this->pdoExtension($value))
                    ? null
                    : "The [{$this->pdoExtension($value)}] extension is required for this database.",
                name: 'database',
            );

            $form->addIf(
                fn ($responses) => $responses['database'] !== 'sqlite',
                fn ($responses, $previousResponse) => confirm(
                    label: 'Would you like to run the default database migrations?',
                    default: $previousResponse ?? true,
                    hint: 'Make sure the database server is running and the database exists.',
                ),
                name: 'migrate',
            );
        }

        // Surge...
        if ($input->getOption('surge') === false) {
            $form->select(
                label: 'Would you like to use LaraGram Surge?',
                options: [
                    'none' => 'No',
                    'swoole' => 'Yes, with Swoole / OpenSwoole',
                    'roadrunner' => 'Yes, with RoadRunner',
                    'frankenphp' => 'Yes, with FrankenPHP',
                ],
                default: 'none',
                validate: fn ($value) => $value === 'swoole' && ! $this->swooleIsAvailable()
                    ? 'The Swoole or OpenSwoole extension is required for this server.'
                    : null,
                name: 'surge',
            );
        }

        $responses = $form->submit();

        if (isset($responses['name'])) {
            $input->setArgument('name', $responses['name']);
        }

        if ($input->getOption('force') !== true) {
            $this->verifyApplicationDoesntExist(
                $this->getInstallationDirectory($input->getArgument('name'))
            );
        }

        match ($responses['starterKit'] ?? 'none') {
            'react' => $input->setOption('react', true),
            'vue' => $input->setOption('vue', true),
            'svelte' => $input->setOption('svelte', true),
            default => null,
        };

        foreach (['token' => 'token', 'botUrl' => 'bot-url', 'apiId' => 'api-id', 'apiHash' => 'api-hash', 'database' => 'database'] as $response => $option) {
            if (($responses[$response] ?? '') !== '') {
                $input->setOption($option, $responses[$response]);
            }
        }

        if ($responses['webhook'] ?? false) {
            $input->setOption('webhook', true);
        }

        if ($responses['mtproto'] ?? false) {
            $input->setOption('mtproto', true);
        }

        if (isset($responses['migrate'])) {
            $this->migrate = $responses['migrate'];
        }

        if (isset($responses['surge'])) {
            $input->setOption('surge', $responses['surge'] === 'none' ? false : $responses['surge']);
        }
    }

    /**
     * Display the LaraGram header with gradient colors.
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

        $gradient = $gradients[array_rand($gradients)];

        foreach ($lines as $index => $line) {
            $output->writeln("\e[38;5;{$gradient[$index]}m{$line}\e[0m");
        }
    }

    /**
     * Ensure that the required PHP extensions are installed.
     *
     * @throws \RuntimeException
     */
    protected function ensureExtensionsAreAvailable(InputInterface $input, OutputInterface $output): void
    {
        $message = $this->missingExtensionsMessage('LaraGram', [
            'ctype',
            'curl',
            'filter',
            'hash',
            'intl',
            'mbstring',
            'openssl',
            'session',
            'tokenizer',
        ]);

        if ($message !== null) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Get the error message for the given missing PHP extensions, if any.
     */
    protected function missingExtensionsMessage(string $feature, array $extensions): ?string
    {
        $missingExtensions = collect($extensions)->reject(fn ($extension) => extension_loaded($extension));

        if ($missingExtensions->isEmpty()) {
            return null;
        }

        return sprintf(
            '%s requires the following PHP extensions, which are not installed: %s.',
            $feature,
            $missingExtensions->join(', ', ', and ')
        );
    }

    /**
     * Check for newer version of the installer package.
     *
     * @return void
     */
    protected function checkForUpdate(InputInterface $input, OutputInterface $output)
    {
        if ($this->agent->isActive()) {
            return;
        }

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

        if (getenv('LARAGRAM_INSTALLER_UPDATE_ATTEMPTED') === '1') {
            putenv('LARAGRAM_INSTALLER_UPDATE_ATTEMPTED');

            $output->writeln('');
            $output->writeln("  <bg=yellow;fg=black> WARN </> The installer could not be updated to version {$latestVersion}. You may need to update manually. Continuing with the current version...");

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
     * Proxy the command to the globally installed LaraGram Installer.
     */
    protected function proxyLaraGramNew(InputInterface $input, OutputInterface $output): void
    {
        $output->writeln('');

        $this->runCommands(
            ['laragram '.$input],
            $input,
            $output,
            workingPath: getcwd(),
            env: ['LARAGRAM_INSTALLER_UPDATE_ATTEMPTED' => '1'],
        );

        exit;
    }

    /**
     * Get the latest version of the installer package from Packagist.
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
            // Cache is less than 24 hours old, use it
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
     * Execute the command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateOptions($input);

        $name = rtrim($input->getArgument('name'), '/\\');

        $directory = $this->getInstallationDirectory($name);

        $this->agent->rememberInstallation($directory);

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

        $createProjectCommand = $composer." create-project laraxgram/laragram \"$directory\" $version --remove-vcs --prefer-dist --no-scripts";

        $starterKit = $this->getStarterKit($input);

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
            $composer." run-script post-root-package-install -d \"$directory\"",
            $phpBinary." \"$directory/laragram\" key:generate --ansi",
        ];

        if (PHP_OS_FAMILY != 'Windows') {
            $commands[$appInitializedLabel][] = "chmod 755 \"$directory/laragram\"";
        }

        if (! ($process = $this->runCommands(
            $commands,
            $input,
            $output,
            // Starter kits need to defer execution of hooks until after create-project completes...
            env: ['LARAGRAM_INSTALLER_DEFER_HOOKS' => '1'],
            taskLabel: 'Creating LaraGram application',
        ))->isSuccessful()) {
            return $process->getExitCode();
        }

        $hooksProcess = $this->runInstallerHooks($directory, $input, $output);

        if ($hooksProcess && ! $hooksProcess->isSuccessful()) {
            return $hooksProcess->getExitCode();
        }

        if (($process = $this->installPackages($directory, $input, $output)) && ! $process->isSuccessful()) {
            return $process->getExitCode();
        }

        $this->configureEnvironment($directory, $name, $input);

        $this->configurePackages($directory, $input, $output);

        if ($this->migrate) {
            $this->migrateDatabase($directory, $input, $output);
        }

        [$packageManager, $runPackageManager] = match (true) {
            $this->shouldInstallNodeDependencies($input) => $this->installNodeDependencies($directory, $input, $output),
            default => [NodePackageManager::NPM, false],
        };

        if ($input->getOption('webhook')) {
            $this->setWebhook($directory, $input, $output);
        }

        if ($input->getOption('brain') && ! $input->getOption('no-brain')) {
            $this->installBrain($directory, $input, $output);
        }

        if ($input->getOption('git') || $input->getOption('github') !== false) {
            $this->createRepository($directory, $input, $output);
        }

        if ($input->getOption('github') !== false) {
            $this->pushToGitHub($name, $directory, $input, $output);
            $output->writeln('');
        }

        $this->displayNextSteps($name, $packageManager, $runPackageManager, $input, $output);

        return self::SUCCESS;
    }

    /**
     * Validate the given options, since they may not have been gathered interactively.
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    protected function validateOptions(InputInterface $input): void
    {
        if ($input->getOption('database') && ! in_array($input->getOption('database'), self::DATABASE_DRIVERS)) {
            throw new \InvalidArgumentException("Invalid database driver [{$input->getOption('database')}]. Possible values are: ".implode(', ', self::DATABASE_DRIVERS).'.');
        }

        if ($input->getOption('surge') !== false) {
            $input->setOption('surge', $input->getOption('surge') ?: 'swoole');

            if (! in_array($input->getOption('surge'), self::SURGE_SERVERS)) {
                throw new \InvalidArgumentException("Invalid Surge server [{$input->getOption('surge')}]. Possible values are: ".implode(', ', self::SURGE_SERVERS).'.');
            }

            if ($input->getOption('surge') === 'swoole' && ! $this->swooleIsAvailable()) {
                throw new RuntimeException('The Swoole server for LaraGram Surge requires the Swoole or OpenSwoole extension.');
            }
        }

        if ($input->getOption('mtproto') && ($message = $this->missingExtensionsMessage('MTProto', self::MTPROTO_EXTENSIONS))) {
            throw new RuntimeException($message);
        }

        if (($url = $input->getOption('bot-url')) && ! $this->isValidWebhookUrl($url)) {
            throw new \InvalidArgumentException("Invalid bot URL [{$url}]. The URL must be a valid HTTPS URL.");
        }

        if ($input->getOption('webhook') && (! $input->getOption('token') || ! $input->getOption('bot-url'))) {
            throw new \InvalidArgumentException('The --webhook option requires both --token and --bot-url.');
        }

        // Interactive installs already verified the token while it was entered...
        if (($token = $input->getOption('token')) && $this->bot === null && ($error = $this->verifyBotToken($token))) {
            throw new \InvalidArgumentException($error);
        }
    }

    /**
     * Install the selected LaraGram packages.
     *
     * The Telegram API types are generated by the application's Composer scripts,
     * so they run through Composer: post-update-cmd when packages are required,
     * otherwise post-install-cmd, which create-project skipped.
     */
    protected function installPackages(string $directory, InputInterface $input, OutputInterface $output): ?Process
    {
        $composer = $this->findComposer();

        $packages = array_keys(array_filter([
            'laraxgram/surge' => $input->getOption('surge') !== false,
            'laraxgram/mtproto' => $input->getOption('mtproto'),
        ]));

        $devPackages = array_keys(array_filter([
            'laraxgram/brain' => $input->getOption('brain') && ! $input->getOption('no-brain'),
        ]));

        if ($packages === [] && $devPackages === []) {
            // Starter kits already ran their Composer scripts during create-project...
            if ($this->usingStarterKit($input) || ! $this->hasComposerScript($directory, 'post-install-cmd')) {
                return null;
            }

            return $this->runCommands(
                ['Telegram API generated' => $composer.' run-script post-install-cmd'],
                $input,
                $output,
                workingPath: $directory,
                taskLabel: 'Generating Telegram API',
            );
        }

        $commands = [];

        if ($packages !== []) {
            $commands[] = $composer.' require '.implode(' ', $packages).' --no-update';
        }

        if ($devPackages !== []) {
            $commands[] = $composer.' require '.implode(' ', $devPackages).' --dev --no-update';
        }

        $commands[] = $composer.' update';

        return $this->runCommands(
            ['Packages installed' => $commands],
            $input,
            $output,
            workingPath: $directory,
            taskLabel: 'Installing LaraGram packages',
        );
    }

    /**
     * Determine if the application defines the given Composer script.
     */
    protected function hasComposerScript(string $directory, string $script): bool
    {
        $composer = json_decode(file_get_contents($directory.'/composer.json'), true) ?: [];

        return ! empty($composer['scripts'][$script]);
    }

    /**
     * Run the installation commands of the selected packages.
     */
    protected function configurePackages(string $directory, InputInterface $input, OutputInterface $output): void
    {
        if ($input->getOption('surge') !== false) {
            $this->runCommands(
                ['Surge installed' => $this->phpBinary().' laragram surge:install --server='.$input->getOption('surge').' --no-interaction'],
                $input,
                $output,
                workingPath: $directory,
                taskLabel: 'Installing LaraGram Surge',
            );
        }

        if ($input->getOption('mtproto')) {
            $this->runCommands(
                ['MTProto installed' => [
                    $this->phpBinary().' laragram install:client',
                    $this->phpBinary().' laragram vendor:publish --tag=mtproto-config',
                ]],
                $input,
                $output,
                workingPath: $directory,
                taskLabel: 'Installing MTProto',
            );
        }
    }

    /**
     * Write the gathered configuration to the application's environment files.
     */
    protected function configureEnvironment(string $directory, string $name, InputInterface $input): void
    {
        $this->pregReplaceInFile(
            '/^APP_URL=http:\/\/localhost$/m',
            'APP_URL=http://localhost:9000',
            $directory.'/.env'
        );

        $this->setEnvironmentValues($directory.'/.env', array_filter([
            'BOT_TOKEN' => $input->getOption('token'),
            'BOT_URL' => $input->getOption('bot-url'),
            'BOT_USERNAME' => $this->bot['username'] ?? null,
            'BOT_USERID' => $this->bot['id'] ?? null,
            'API_ID' => $input->getOption('api-id'),
            'API_HASH' => $input->getOption('api-hash'),
        ], fn ($value) => $value !== null && $value !== ''));

        $this->configureDefaultDatabaseConnection(
            $directory,
            $input->getOption('database') ?: 'sqlite',
            $name === '.' ? basename(getcwd()) : basename($name),
        );
    }

    /**
     * Set the given values in the given environment file, appending missing keys.
     */
    protected function setEnvironmentValues(string $file, array $values): void
    {
        $contents = file_get_contents($file);

        foreach ($values as $key => $value) {
            $line = $key.'='.(preg_match('/[\s#"\'$\\\\]/', (string) $value) ? '"'.addcslashes($value, '"\\$').'"' : $value);

            $contents = preg_match("/^{$key}=.*$/m", $contents)
                ? preg_replace_callback("/^{$key}=.*$/m", fn () => $line, $contents)
                : rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }

        file_put_contents($file, $contents);
    }

    /**
     * Return the local machine's default Git branch if set or default to `main`.
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
     * Configure the default database connection.
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

            // If database options aren't commented, comment them for SQLite...
            if (! str_contains($environment, '# DB_HOST=127.0.0.1')) {
                $this->commentDatabaseConfigurationForSqlite($directory);
            }

            return;
        }

        // Any commented database configuration options should be uncommented when not on SQLite...
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
            'DB_DATABASE='.str_replace(['-', '.'], '_', strtolower($name)),
            $directory.'/.env'
        );

        $this->replaceInFile(
            'DB_DATABASE=laragram',
            'DB_DATABASE='.str_replace(['-', '.'], '_', strtolower($name)),
            $directory.'/.env.example'
        );
    }

    /**
     * Comment the irrelevant database configuration entries for SQLite applications.
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
     * Uncomment the relevant database configuration entries for non SQLite applications.
     *
     * @return void
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
     * Run the default database migrations.
     */
    protected function migrateDatabase(string $directory, InputInterface $input, OutputInterface $output): void
    {
        if (($input->getOption('database') ?: 'sqlite') === 'sqlite') {
            touch($directory.'/database/database.sqlite');
        }

        $this->runCommands(
            ['Database migrated' => $this->phpBinary().' laragram migrate --graceful --no-interaction'],
            $input,
            $output,
            workingPath: $directory,
            taskLabel: 'Running database migrations',
        );
    }

    /**
     * Get the available database options.
     */
    protected function databaseOptions(): array
    {
        return collect([
            'sqlite' => 'SQLite',
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'pgsql' => 'PostgreSQL',
            'sqlsrv' => 'SQL Server',
        ])
            ->sortBy(fn ($label, $driver) => extension_loaded($this->pdoExtension($driver)) ? 0 : 1)
            ->map(fn ($label, $driver) => $label.(extension_loaded($this->pdoExtension($driver)) ? '' : ' (Missing PDO extension)'))
            ->all();
    }

    /**
     * Get the PDO extension of the given database driver.
     */
    protected function pdoExtension(string $driver): string
    {
        return $driver === 'mariadb' ? 'pdo_mysql' : 'pdo_'.$driver;
    }

    /**
     * Determine if the Swoole or OpenSwoole extension is available.
     */
    protected function swooleIsAvailable(): bool
    {
        return extension_loaded('swoole') || extension_loaded('openswoole');
    }

    /**
     * Determine if Node dependencies should be installed.
     *
     * Only Mini App starter kits need a frontend build. Plain bots install them on
     * demand, unless a package manager was explicitly requested.
     */
    protected function shouldInstallNodeDependencies(InputInterface $input): bool
    {
        if ($input->getOption('no-node')) {
            return false;
        }

        return $this->usingStarterKit($input)
            || $input->getOption('npm')
            || $input->getOption('pnpm')
            || $input->getOption('bun')
            || $input->getOption('yarn');
    }

    /**
     * Determine the Node package manager to use.
     *
     * @return array{NodePackageManager, bool}
     */
    protected function determinePackageManager(string $directory, InputInterface $input): array
    {
        // If they passed a specific flag, respect the user's choice...
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

        // Check for an existing lock file to determine the package manager...
        foreach (NodePackageManager::cases() as $packageManager) {
            if ($packageManager === NodePackageManager::NPM) {
                continue;
            }

            foreach ($packageManager->lockFiles() as $lockFile) {
                if (file_exists($directory.'/'.$lockFile)) {
                    return [$packageManager, true];
                }
            }
        }

        return [NodePackageManager::NPM, true];
    }

    /**
     * Install Node dependencies and build assets.
     *
     * @return array{NodePackageManager, bool} The package manager used and whether it was run
     */
    protected function installNodeDependencies(string $directory, InputInterface $input, OutputInterface $output): array
    {
        [$packageManager, $runPackageManager] = $this->determinePackageManager($directory, $input);

        if ((new ExecutableFinder)->find($packageManager->value) === null) {
            $output->writeln("  <bg=yellow;fg=black> WARN </> The [{$packageManager->value}] executable could not be found. Skipping frontend dependencies...".PHP_EOL);

            $runPackageManager = false;
        }

        $this->configureComposerScripts($packageManager);

        foreach (NodePackageManager::allLockFiles() as $lockFile) {
            if (! in_array($lockFile, $packageManager->lockFiles()) && file_exists($directory.'/'.$lockFile)) {
                (new Filesystem)->delete($directory.'/'.$lockFile);
            }
        }

        if ($runPackageManager) {
            $runPackageManager = $this->runCommands(
                [
                    'Packages installed' => $packageManager->installCommand(),
                    'Assets built' => $packageManager->buildCommand(),
                ],
                $input,
                $output,
                workingPath: $directory,
                taskLabel: 'Setting up frontend dependencies with '.$packageManager->value,
            )->isSuccessful();
        }

        return [$packageManager, $runPackageManager];
    }

    /**
     * Set the bot webhook to the configured bot URL.
     */
    protected function setWebhook(string $directory, InputInterface $input, OutputInterface $output): void
    {
        $this->runCommands(
            ['Webhook set' => $this->phpBinary().' laragram webhook:set'],
            $input,
            $output,
            workingPath: $directory,
            taskLabel: 'Setting the bot webhook',
        );
    }

    /**
     * Install LaraGram Brain into the application.
     *
     * The package itself is installed with the other packages. Its installer
     * asks which agents to configure, so it runs attached to the terminal.
     */
    protected function installBrain(string $directory, InputInterface $input, OutputInterface $output): void
    {
        $this->runCommands(
            [trim(sprintf(
                $this->phpBinary().' laragram brain:install %s',
                ! $input->isInteractive() ? '--no-interaction' : '',
            ))],
            $input,
            $output,
            workingPath: $directory,
        );
    }

    /**
     * Create a Git repository and commit the fresh LaraGram application.
     *
     * @return void
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
     * Create a GitHub repository and push the git log to it.
     *
     * @return void
     */
    protected function pushToGitHub(string $name, string $directory, InputInterface $input, OutputInterface $output)
    {
        $process = new Process(['gh', 'auth', 'status']);
        $process->run();

        if (! $process->isSuccessful()) {
            $output->writeln('  <bg=yellow;fg=black> WARN </> Make sure the "gh" CLI tool is installed and that you\'re authenticated to GitHub. Skipping...'.PHP_EOL);

            return;
        }

        $github = trim((string) $input->getOption('github'));
        $githubIsOwner = $github !== '' && ! str_starts_with($github, '-');

        $owner = $input->getOption('organization') ?: ($githubIsOwner ? $github : null);
        $flags = ($githubIsOwner || $github === '') ? '--private' : $github;

        if ($owner) {
            $name = "{$owner}/{$name}";
        }

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
     * Display the steps needed to start the new application.
     */
    protected function displayNextSteps(string $name, NodePackageManager $packageManager, bool $runPackageManager, InputInterface $input, OutputInterface $output): void
    {
        $getStartedSteps = ["cd {$name}"];

        $missingEnvironmentValues = array_keys(array_filter([
            'BOT_TOKEN' => ! $input->getOption('token'),
            'BOT_URL' => ! $input->getOption('bot-url'),
        ]));

        if ($missingEnvironmentValues !== []) {
            $getStartedSteps[] = 'Set '.implode(' and ', $missingEnvironmentValues).' in the .env file';
        }

        if ($this->usingStarterKit($input) && ! $runPackageManager) {
            $getStartedSteps[] = $packageManager->installCommand().' && '.$packageManager->buildCommand();
        }

        if ($input->getOption('mtproto')) {
            $getStartedSteps[] = 'php laragram client:auth';
            $getStartedSteps[] = 'php laragram client:start';
        }

        $getStartedSteps[] = match (true) {
            $input->getOption('surge') !== false => 'php laragram surge:start',
            $runPackageManager => 'composer run dev',
            default => 'php laragram serve',
        };

        if (! $input->getOption('webhook')) {
            $getStartedSteps[] = 'php laragram webhook:set';
        }

        callout(
            label: 'Application ready',
            content: [
                'You can start your local development using:',
                Element::numberedList($getStartedSteps),
                $output->getFormatter()->format('<options=bold>New to LaraGram?</>')
                    .' Check out our '.Element::link(
                        'https://laraxgram.github.io/v4/installation.html#next-steps',
                        'documentation'
                    ).'.',
                Element::heading('Build something amazing!'),
            ],
        );
    }

    /**
     * Configure the Composer scripts for the selected package manager.
     */
    protected function configureComposerScripts(NodePackageManager $packageManager): void
    {
        $this->composer->modify(function (array $content) use ($packageManager) {
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
     * Run any LaraGram installer hooks defined by the installed application.
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
            env: $input->getOption('no-node') ? ['LARAGRAM_INSTALLER_NO_NODE' => '1'] : [],
            taskLabel: 'Running starter kit hooks',
        );
    }

    /**
     * Get the commands for the given LaraGram installer hook.
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
     * Normalize Composer-style script commands for direct execution by the installer.
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
     * Verify that the application does not already exist.
     *
     * @param  string  $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    /**
     * Get the starter kit repository, if any.
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
     * Determine if a LaraGram first-party starter kit has been chosen.
     */
    protected function usingLaraGramStarterKit(InputInterface $input): bool
    {
        return $this->usingStarterKit($input) &&
            str_starts_with($this->getStarterKit($input), 'laraxgram/');
    }

    /**
     * Determine if a starter kit is being used.
     *
     * @return bool
     */
    protected function usingStarterKit(InputInterface $input)
    {
        return $input->getOption('react') || $input->getOption('svelte') || $input->getOption('vue') || $input->getOption('using');
    }

    /**
     * Get the installation directory.
     *
     * @return string
     */
    protected function getInstallationDirectory(string $name)
    {
        if ($name === '.') {
            return '.';
        }

        return str_starts_with($name, DIRECTORY_SEPARATOR) ? $name : getcwd().'/'.$name;
    }

    /**
     * Get the version that should be downloaded.
     *
     * @return string
     */
    protected function getVersion(InputInterface $input)
    {
        if ($input->getOption('dev')) {
            return '4.x-dev';
        }

        return '';
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        return implode(' ', $this->composer->findComposer());
    }

    /**
     * Get the path to the appropriate PHP binary.
     *
     * @return string
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
     * Run the given commands.
     *
     * @param  array  $commands
     * @return \LaraGram\Console\Process\Process
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
                    if (Str::startsWith($value, ['chmod', 'rm', 'git', 'npm', 'pnpm', 'yarn', 'bun'])) {
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
                    if (Str::startsWith($value, ['chmod', 'rm', 'git', 'npm', 'pnpm', 'yarn', 'bun'])) {
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

        if (Process::isTtySupported() && $input->isInteractive() && ! $this->agent->isActive()) {
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
     * Determine if the commands should be run as tasks.
     */
    protected function shouldRunAsTask(OutputInterface $output, array $commands): bool
    {
        return function_exists('LaraGram\Console\Prompts\task')
            && function_exists('pcntl_fork')
            && ! array_is_list($commands)
            && ! $this->agent->isActive()
            && $this->useConciseOutput($output);
    }

    /**
     * Run the given shell commands within a LaraGram Prompts task.
     *
     * @param  non-empty-array<string, non-empty-array<int, string>>  $commands
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
                $process = null;

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
     * Run the given command on Windows with inherited stdio for interactive support.
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

        // Return a completed Process instance that reflects the actual exit code...
        $sentinel = Process::fromShellCommandline('exit '.$exitCode, $workingPath);

        $sentinel->run();

        return $sentinel;
    }

    /**
     * Replace the given string in the given file.
     *
     * @return void
     */
    protected function replaceInFile(string|array $search, string|array $replace, string $file)
    {
        file_put_contents(
            $file,
            str_replace($search, $replace, file_get_contents($file))
        );
    }

    /**
     * Replace the given string in the given file using regular expressions.
     *
     * @return void
     */
    protected function pregReplaceInFile(string $pattern, string $replace, string $file)
    {
        file_put_contents(
            $file,
            preg_replace($pattern, $replace, file_get_contents($file))
        );
    }

    /**
     * Determine if concise output should be used.
     */
    protected function useConciseOutput(OutputInterface $output): bool
    {
        return $output->getVerbosity() === OutputInterface::VERBOSITY_NORMAL || $output->isQuiet();
    }
}
