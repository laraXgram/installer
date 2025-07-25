<?php

namespace LaraGram\Installer\Console;

use LaraGram\Console\Command\Command;
use LaraGram\Console\Input\InputArgument;
use LaraGram\Console\Input\InputInterface;
use LaraGram\Console\Input\InputOption;
use LaraGram\Console\Output\OutputInterface;
use LaraGram\Console\Process\Process;
use LaraGram\Filesystem\Filesystem;
use LaraGram\Support\Composer;
use LaraGram\Support\Process\PhpExecutableFinder;
use LaraGram\Support\ProcessUtils;
use LaraGram\Support\Str;
use function LaraGram\Console\Prompts\confirm;
use function LaraGram\Console\Prompts\password;
use function LaraGram\Console\Prompts\select;
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
            ->addOption('github', null, InputOption::VALUE_OPTIONAL, 'Create a new repository on GitHub', false)
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'The GitHub organization to create the new repository for')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'The database driver your application will use. Possible values are: ' . implode(', ', self::DATABASE_DRIVERS))
            ->addOption('surge', null, InputOption::VALUE_NONE, 'Use LaraGram Surge')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    /**
     * Interact with the user before validating the input.
     *
     * @param \LaraGram\Console\Input\InputInterface $input
     * @param \LaraGram\Console\Output\OutputInterface $output
     * @return void
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);

        $output->write(<<<FLAG

    <fg=red>  _                     </>
    <fg=red> | |                    </>
    <fg=red> | |     __ _ _ __ __ _ </><fg=blue>  _____                     </>
    <fg=red> | |    / _` | '__/ _` |</><fg=blue> / ____|                    </>
    <fg=red> | |___| (_| | | | (_| |</><fg=blue>| |  __ _ __ __ _ _ __ ___  </>
    <fg=red> |______\__,_|_|  \__,_|</><fg=blue>| | |_ | '__/ _` | '_ ` _ \ </>
                            <fg=blue>| |__| | | | (_| | | | | | |</>
                            <fg=blue> \_____|_|  \__,_|_| |_| |_|</>
FLAG
        );

        $this->ensureExtensionsAreAvailable($input, $output);

        if (!$input->getArgument('name')) {
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
                        } catch (\RuntimeException) {
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

        if (!$this->usingSurge($input)) {
            match (true) {
                confirm(
                    label: "Do you want to use LaraGram Surge?",
                    default: false,
                    validate: function ($value) {
                        return $value && !(extension_loaded('swoole') || extension_loaded('openswoole'))
                            ? 'Extension Swoole/OpenSwoole not exist.'
                            : null;
                    },
                    hint: "This option requires the Swoole or Openswoole plugin."
                ) => $input->setOption('surge', true),
                default => ''
            };
        }
    }

    /**
     * Execute the command.
     *
     * @param \LaraGram\Console\Input\InputInterface $input
     * @param \LaraGram\Console\Output\OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateDatabaseOption($input);

        $name = rtrim($input->getArgument('name'), '/\\');

        $directory = $this->getInstallationDirectory($name);

        $this->composer = new Composer(new Filesystem(), $directory);

        $version = $this->getVersion($input);

        if (!$input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        if ($input->getOption('force') && $directory === '.') {
            throw new \RuntimeException('Cannot use --force option when using current directory for installation!');
        }

        $composer = $this->findComposer();
        $phpBinary = $this->phpBinary();

        $createProjectCommand = $composer . " create-project laraxgram/laragram \"$directory\" $version --remove-vcs --prefer-dist --no-scripts";

        $commands = [
            $createProjectCommand,
            $composer . " run post-root-package-install -d \"$directory\"",
            $phpBinary . " \"$directory/laragram\" key:generate --ansi",
        ];

        if ($input->getOption('surge')) {
            $commands[] = "cd \"$directory\"";
            $commands[] = $composer . " require laraxgram/surge";
        }

        if ($directory != '.' && $input->getOption('force')) {
            if (PHP_OS_FAMILY == 'Windows') {
                array_unshift($commands, "(if exist \"$directory\" rd /s /q \"$directory\")");
            } else {
                array_unshift($commands, "rm -rf \"$directory\"");
            }
        }

        if (PHP_OS_FAMILY != 'Windows') {
            $commands[] = "chmod 755 \"$directory/laragram\"";
        }

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            if ($name !== '.') {
                [$database, $migrate] = $this->promptForDatabaseOptions($directory, $input);

                $this->configureDefaultDatabaseConnection($directory, $database, $name);

                if ($migrate) {
                    if ($database === 'sqlite') {
                        touch($directory . '/database/database.sqlite');
                    }

                    $commands = [
                        trim(sprintf(
                            $this->phpBinary() . ' laragram migrate %s',
                            !$input->isInteractive() ? '--no-interaction' : '',
                        )),
                    ];

                    $this->runCommands($commands, $input, $output, workingPath: $directory);
                }
            }

            $this->promptForBotOptions($directory, $input, $output);

            if ($input->getOption('git') || $input->getOption('github') !== false) {
                $this->createRepository($directory, $input, $output);
            }

            if ($input->getOption('github') !== false) {
                $this->pushToGitHub($name, $directory, $input, $output);
                $output->writeln('');
            }

            $this->configureComposerDevScript($directory);

            $output->writeln("  <bg=blue;fg=white> INFO </> Application ready in <options=bold>[{$name}]</>. You can start your local development using:" . PHP_EOL);
            $output->writeln('<fg=gray>➜</> <options=bold>cd ' . $name . '</>');
            $output->writeln('<fg=gray>➜</> <options=bold>php laragram serve</>');

            $output->writeln('');
            $output->writeln('  New to LaraGram? Check out our <href=https://laraxgram.github.ip/installation.html#next-steps>documentation</>. <options=bold>Build something amazing!</>');
            $output->writeln('');
        }

        return $process->getExitCode();
    }

    /**
     * Determine the default database connection.
     *
     * @param string $directory
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function promptForBotOptions(string $directory, InputInterface $input, OutputInterface $output)
    {
        $token = password(
            label: 'Enter your Bot Token: ',
            hint: 'Press <fg=yellow>Enter</> <fg=gray>to Skip...</>'
        );

        $url = text(
            label: 'Enter your Bot URL: ',
            hint: 'Press <fg=yellow>Enter</> <fg=gray>to Skip...</>'
        );

        $this->replaceInFile(
            "'token' => '',",
            "'token' => '$token',",
            $directory . "/config/bot.php"
        );

        $this->replaceInFile(
            "'url' => '',",
            "'url' => '$url',",
            $directory . "/config/bot.php"
        );

        if ($token !== '' && $url !== '') {
            if (confirm(label: "Do you want to set webhook?")) {
                $this->runCommands([$this->phpBinary() . " laragram webhook:set"], $input, $output, workingPath: $directory);
            }
        }
    }

    /**
     * Configure the Composer "dev" script.
     *
     * @param string $directory
     * @return void
     */
    protected function configureComposerDevScript(string $directory): void
    {
        $this->composer->modify(function (array $content) {
            if (windows_os()) {
                $content['scripts']['dev'] = [
                    'Composer\\Config::disableProcessTimeout',
                    "php laragram serve",
                ];
            }

            return $content;
        });
    }

    /**
     * Create a Git repository and commit the base Laravel skeleton.
     *
     * @param string $directory
     * @param \LAraGram\Console\Input\InputInterface $input
     * @param \LAraGram\Console\Output\OutputInterface $output
     * @return void
     */
    protected function createRepository(string $directory, InputInterface $input, OutputInterface $output)
    {
        $branch = $input->getOption('branch') ?: $this->defaultBranch();

        $commands = [
            'git init -q',
            'git add .',
            'git commit -q -m "Set up a fresh LaraGram app"',
            "git branch -M {$branch}",
        ];

        $this->runCommands($commands, $input, $output, workingPath: $directory);
    }

    /**
     * Create a GitHub repository and push the git log to it.
     *
     * @param string $name
     * @param string $directory
     * @param \LaraGram\Console\Input\InputInterface $input
     * @param \LaraGram\Console\Output\OutputInterface $output
     * @return void
     */
    protected function pushToGitHub(string $name, string $directory, InputInterface $input, OutputInterface $output)
    {
        $process = new Process(['gh', 'auth', 'status']);
        $process->run();

        if (!$process->isSuccessful()) {
            $output->writeln('  <bg=yellow;fg=black> WARN </> Make sure the "gh" CLI tool is installed and that you\'re authenticated to GitHub. Skipping...' . PHP_EOL);

            return;
        }

        $name = $input->getOption('organization') ? $input->getOption('organization') . "/$name" : $name;
        $flags = $input->getOption('github') ?: '--private';

        $commands = [
            "gh repo create {$name} --source=. --push {$flags}",
        ];

        $this->runCommands($commands, $input, $output, workingPath: $directory, env: ['GIT_TERMINAL_PROMPT' => 0]);
    }

    /**
     * Configure the default database connection.
     *
     * @param string $directory
     * @param string $database
     * @param string $name
     * @return void
     */
    protected function configureDefaultDatabaseConnection(string $directory, string $database, string $name)
    {
        $this->pregReplaceInFile(
            '/DB_CONNECTION=.*/',
            'DB_CONNECTION=' . $database,
            $directory . '/.env'
        );

        $this->pregReplaceInFile(
            '/DB_CONNECTION=.*/',
            'DB_CONNECTION=' . $database,
            $directory . '/.env.example'
        );

        if ($database === 'sqlite') {
            $environment = file_get_contents($directory . '/.env');

            // If database options aren't commented, comment them for SQLite...
            if (!str_contains($environment, '# DB_HOST=127.0.0.1')) {
                $this->commentDatabaseConfigurationForSqlite($directory);

                return;
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
                'DB_PORT=' . $defaultPorts[$database],
                $directory . '/.env'
            );

            $this->replaceInFile(
                'DB_PORT=3306',
                'DB_PORT=' . $defaultPorts[$database],
                $directory . '/.env.example'
            );
        }

        $this->replaceInFile(
            'DB_DATABASE=laragram',
            'DB_DATABASE=' . str_replace('-', '_', strtolower($name)),
            $directory . '/.env'
        );

        $this->replaceInFile(
            'DB_DATABASE=laragram',
            'DB_DATABASE=' . str_replace('-', '_', strtolower($name)),
            $directory . '/.env.example'
        );
    }

    /**
     * Determine the default database connection.
     *
     * @param string $directory
     * @param \LaraGram\Console\Input\InputInterface $input
     * @return array
     */
    protected function promptForDatabaseOptions(string $directory, InputInterface $input)
    {
        $defaultDatabase = collect(
            $databaseOptions = $this->databaseOptions()
        )->keys()->first();

        if (!$input->getOption('database') && $input->isInteractive()) {
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
     * Get the available database options.
     *
     * @return array
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
            ->sortBy(fn($database) => $database[1] ? 0 : 1)
            ->map(fn($database) => $database[0] . ($database[1] ? '' : ' (Missing PDO extension)'))
            ->all();
    }

    /**
     * Comment the irrelevant database configuration entries for SQLite applications.
     *
     * @param string $directory
     * @return void
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
            collect($defaults)->map(fn($default) => "# {$default}")->all(),
            $directory . '/.env'
        );

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn($default) => "# {$default}")->all(),
            $directory . '/.env.example'
        );
    }

    /**
     * Uncomment the relevant database configuration entries for non SQLite applications.
     *
     * @param string $directory
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
            collect($defaults)->map(fn($default) => substr($default, 2))->all(),
            $directory . '/.env'
        );

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn($default) => substr($default, 2))->all(),
            $directory . '/.env.example'
        );
    }

    /**
     * Replace the given string in the given file.
     *
     * @param string|array $search
     * @param string|array $replace
     * @param string $file
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
     * @param string|array $search
     * @param string|array $replace
     * @param string $file
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
     * Run the given commands.
     *
     * @param array $commands
     * @param \LaraGram\Console\Input\InputInterface $input
     * @param \LaraGram\Console\Output\OutputInterface $output
     * @param string|null $workingPath
     * @param array $env
     * @return \LaraGram\Console\Process\Process
     */
    protected function runCommands($commands, InputInterface $input, OutputInterface $output, ?string $workingPath = null, array $env = [])
    {
        if (!$output->isDecorated()) {
            $commands = array_map(function ($value) {
                if (Str::startsWith($value, ['chmod', 'git'])) {
                    return $value;
                }

                return $value . ' --no-ansi';
            }, $commands);
        }

        if ($input->getOption('quiet')) {
            $commands = array_map(function ($value) {
                if (Str::startsWith($value, ['chmod', 'git'])) {
                    return $value;
                }

                return $value . ' --quiet';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), $workingPath, $env, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (\RuntimeException $e) {
                $output->writeln('  <bg=yellow;fg=black> WARN </> ' . $e->getMessage() . PHP_EOL);
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    ' . $line);
        });

        return $process;
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
     * Get the version that should be downloaded.
     *
     * @param \LaraGram\Console\Input\InputInterface $input
     * @return string
     */
    protected function getVersion(InputInterface $input)
    {
        if ($input->getOption('dev')) {
            return 'dev-master';
        }

        return '';
    }

    /**
     * Validate the database driver input.
     *
     * @param \LaraGram\Console\Input\InputInterface $input
     */
    protected function validateDatabaseOption(InputInterface $input)
    {
        if ($input->getOption('database') && !in_array($input->getOption('database'), self::DATABASE_DRIVERS)) {
            throw new \InvalidArgumentException("Invalid database driver [{$input->getOption('database')}]. Possible values are: " . implode(', ', self::DATABASE_DRIVERS) . '.');
        }
    }


    /**
     * Get the installation directory.
     *
     * @param string $name
     * @return string
     */
    protected function getInstallationDirectory(string $name)
    {
        return $name !== '.' ? getcwd() . '/' . $name : '.';
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param string $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new \RuntimeException('Application already exists!');
        }
    }

    /**
     * Ensure that the required PHP extensions are installed.
     *
     * @param \LaraGram\Console\Input\InputInterface $input
     * @param \LaraGram\Console\Output\OutputInterface $output
     * @return void
     *
     * @throws \RuntimeException
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
            'tokenizer',
        ])->reject(fn($extension) => in_array($extension, $availableExtensions));

        if ($missingExtensions->isEmpty()) {
            return;
        }

        throw new \RuntimeException(
            sprintf('The following PHP extensions are required but are not installed: %s', $missingExtensions->join(', ', ', and '))
        );
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
     * Determine if a surge is being used.
     *
     * @param \LaraGram\Console\Input\InputInterface $input
     * @return bool
     */
    protected function usingSurge(InputInterface $input)
    {
        return $input->getOption('surge');
    }
}