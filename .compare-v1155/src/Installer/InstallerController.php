<?php

declare(strict_types=1);

namespace SimpleKuma\Installer;

/**
 * Installer Controller
 * Manages the installation wizard flow
 */
class InstallerController
{
    private const STEP_REQUIREMENTS = 'requirements';
    private const STEP_DATABASE = 'database';
    private const STEP_CONFIG = 'config';
    private const STEP_MIGRATIONS = 'migrations';
    private const STEP_ADMIN = 'admin';
    private const STEP_COMPLETE = 'complete';

    private string $currentStep;
    private RequirementsChecker $requirementsChecker;
    private InstallStateDetector $stateDetector;

    public function __construct()
    {
        session_start();
        InstallerCsrf::ensureToken();
        $this->requirementsChecker = new RequirementsChecker();
        $this->stateDetector = new InstallStateDetector();
        $this->currentStep = $_GET['step'] ?? self::STEP_REQUIREMENTS;
        $this->handleResumeRequest();
    }

    /**
     * Run the installer
     */
    public function run(): void
    {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow', true);
        }

        switch ($this->currentStep) {
            case self::STEP_REQUIREMENTS:
                $this->showRequirementsStep();
                break;

            case self::STEP_DATABASE:
                $this->showDatabaseStep();
                break;

            case self::STEP_CONFIG:
                $this->showConfigStep();
                break;

            case self::STEP_MIGRATIONS:
                $this->showMigrationsStep();
                break;

            case self::STEP_ADMIN:
                $this->showAdminStep();
                break;

            case self::STEP_COMPLETE:
                $this->showCompleteStep();
                break;

            default:
                $this->showRequirementsStep();
        }
    }

    private function handleResumeRequest(): void
    {
        if (!isset($_GET['resume'])) {
            return;
        }

        $state = $this->stateDetector->detect();
        $this->stateDetector->syncSessionFlags($state);

        $target = is_string($_GET['resume']) && $_GET['resume'] !== '1'
            ? $_GET['resume']
            : $state['suggestedStep'];

        $allowed = [
            self::STEP_REQUIREMENTS,
            self::STEP_DATABASE,
            self::STEP_CONFIG,
            self::STEP_MIGRATIONS,
            self::STEP_ADMIN,
            self::STEP_COMPLETE,
        ];

        if (!in_array($target, $allowed, true)) {
            $target = $state['suggestedStep'];
        }

        if ($state['isComplete'] && $target !== self::STEP_COMPLETE) {
            header('Location: ' . InstallerLock::getLoginUrl());
            exit;
        }

        header('Location: install.php?step=' . rawurlencode($target));
        exit;
    }

    private function requireValidCsrf(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }

        if (InstallerCsrf::validate()) {
            return true;
        }

        return false;
    }

    /**
     * Show requirements check step
     */
    private function showRequirementsStep(): void
    {
        $results = null;
        $installResults = null;
        $csrfError = null;
        $installState = $this->stateDetector->detect();

        if ($installState['isComplete'] && !InstallerLock::isLocalHost()) {
            header('Location: ' . InstallerLock::getLoginUrl());
            exit;
        }

        if (isset($_POST['install_dependencies'])) {
            if (!$this->requireValidCsrf()) {
                $csrfError = InstallerCsrf::invalidRequestMessage();
            } elseif (!InstallerLock::isLocalHost() && !InstallerLock::isInstallerDevMode()) {
                $installResults = [
                    'composer_success' => false,
                    'errors' => ['Dependency installation from the web installer is only available in local development.'],
                ];
            } else {
                $installer = new DependencyInstaller();

                if ($installer->canExecuteCommands()) {
                    $composerSuccess = $installer->installComposerDependencies();
                    $geoipSuccess = $installer->downloadGeoIPDatabases();

                    $installResults = [
                        'composer_success' => $composerSuccess,
                        'geoip_success' => $geoipSuccess,
                        'messages' => $installer->getMessages(),
                        'errors' => $installer->getErrors(),
                    ];

                    $this->requirementsChecker->check();
                    $results = $this->requirementsChecker->getResults();
                } else {
                    $installResults = [
                        'composer_success' => false,
                        'errors' => ['Command execution is disabled on this server. Please install dependencies manually via SSH or contact your hosting provider.'],
                    ];
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['install_dependencies'])) {
            if (!$this->requireValidCsrf()) {
                $csrfError = InstallerCsrf::invalidRequestMessage();
            } else {
                $this->requirementsChecker->check();
                $results = $this->requirementsChecker->getResults();

                if ($results && $results['canProceed']) {
                    session_regenerate_id(true);
                    $_SESSION['requirements_passed'] = true;
                    header('Location: install.php?step=' . self::STEP_DATABASE);
                    exit;
                }
            }
        } elseif (!$results) {
            $this->requirementsChecker->check();
            $results = $this->requirementsChecker->getResults();
        }

        $httpsWarning = !InstallerLock::isRequestHttps() && !InstallerLock::isLocalHost()
            ? 'You are not using HTTPS. Use https:// in your browser URL before installing — login and sessions require SSL.'
            : null;

        $this->renderView('requirements', [
            'results' => $results,
            'installResults' => $installResults,
            'nextStep' => self::STEP_DATABASE,
            'installState' => $installState,
            'httpsWarning' => $httpsWarning,
            'csrfError' => $csrfError,
        ]);
    }

    /**
     * Show database configuration step
     */
    private function showDatabaseStep(): void
    {
        if (empty($_SESSION['requirements_passed'])) {
            header('Location: install.php?step=' . self::STEP_REQUIREMENTS);
            exit;
        }

        $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!file_exists($autoloadPath)) {
            $this->renderDatabaseView([], [
                'connection' => 'Dependencies not installed. Upload the full package including the vendor/ folder.',
            ], false);
            return;
        }

        if (!class_exists(DatabaseValidator::class)) {
            try {
                require_once $autoloadPath;
            } catch (\Throwable $e) {
                error_log('Installer: Failed to load autoloader in database step: ' . $e->getMessage());
                $this->renderDatabaseView($this->defaultDatabaseFormData(), [
                    'connection' => 'Failed to load dependencies: ' . $e->getMessage(),
                ], false);
                return;
            }
        }

        if (!class_exists(DatabaseValidator::class)) {
            $this->renderDatabaseView($this->defaultDatabaseFormData(), [
                'connection' => 'Database validator class not found. Re-upload vendor/ or run composer install.',
            ], false);
            return;
        }

        $errors = [];
        $data = $this->defaultDatabaseFormData();
        $connectionSuccess = false;
        $csrfError = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->requireValidCsrf()) {
                $csrfError = InstallerCsrf::invalidRequestMessage();
            } else {
                $dbHost = trim($_POST['db_host'] ?? '');
                if ($dbHost === '') {
                    $dbHost = 'localhost';
                }

                $data = [
                    'db_host' => $dbHost,
                    'db_name' => trim($_POST['db_name'] ?? ''),
                    'db_user' => trim($_POST['db_user'] ?? ''),
                    'db_password' => $_POST['db_password'] ?? '',
                    'base_url' => trim($_POST['base_url'] ?? ''),
                    'base_url_same_host_ack' => !empty($_POST['base_url_same_host_ack']) ? '1' : '',
                    'public_path_suffix' => trim($_POST['public_path_suffix'] ?? ''),
                    'admin_username' => trim($_POST['admin_username'] ?? ''),
                    'admin_password' => $_POST['admin_password'] ?? '',
                    'admin_password_confirm' => $_POST['admin_password_confirm'] ?? '',
                    'admin_email' => trim($_POST['admin_email'] ?? ''),
                ];

                try {
                    $validator = new DatabaseValidator();

                    if ($validator->validate($data) && $validator->testConnection(
                        $data['db_host'],
                        $data['db_user'],
                        $data['db_password'],
                        $data['db_name']
                    )) {
                        $_SESSION['db_config'] = [
                            'host' => $data['db_host'],
                            'name' => $data['db_name'],
                            'user' => $data['db_user'],
                            'password' => $data['db_password'],
                        ];
                        $_SESSION['site_config'] = [
                            'base_url' => WebPathResolver::normalizeBaseUrl($data['base_url']),
                            'public_path_suffix' => WebPathResolver::normalizePublicPathSuffix(
                                $data['public_path_suffix'] ?? ''
                            ),
                        ];
                        $_SESSION['admin_config'] = [
                            'username' => $data['admin_username'],
                            'password' => $data['admin_password'],
                            'email' => $data['admin_email'],
                        ];
                        $_SESSION['database_configured'] = true;
                        $connectionSuccess = true;
                        $validator->closeConnection();
                        header('refresh:2;url=install.php?step=' . self::STEP_CONFIG);
                    } else {
                        $validator->closeConnection();
                    }

                    $errors = $validator->getErrors();
                } catch (\Throwable $e) {
                    error_log('Installer: Database step error: ' . $e->getMessage());
                    error_log('Installer: at ' . $e->getFile() . ':' . $e->getLine());
                    $errors = [
                        'connection' => 'Setup error: ' . $e->getMessage(),
                    ];
                }
            }
        }

        $this->renderDatabaseView($data, $errors, $connectionSuccess, $csrfError);
    }

    /**
     * Default values for the installer database step form.
     *
     * @return array<string, string>
     */
    private function defaultDatabaseFormData(): array
    {
        return [
            'db_host' => 'localhost',
            'db_name' => '',
            'db_user' => 'root',
            'db_password' => '',
            'base_url' => $this->guessBaseUrl(),
            'public_path_suffix' => WebPathResolver::getPublicPathSuffix(),
            'admin_username' => '',
            'admin_password' => '',
            'admin_password_confirm' => '',
            'admin_email' => '',
        ];
    }

    /**
     * @param array<string, string> $data
     * @param array<string, string> $errors
     */
    private function renderDatabaseView(
        array $data,
        array $errors,
        bool $connectionSuccess,
        ?string $csrfError = null
    ): void {
        $this->renderView('database', [
            'data' => $data,
            'errors' => $errors,
            'connectionSuccess' => $connectionSuccess,
            'installLayoutNote' => WebPathResolver::getInstallLayoutDescription(),
            'detectedAppBaseUrl' => WebPathResolver::buildAppBaseUrl(
                $data['base_url'] ?? WebPathResolver::guessBaseUrl(),
                array_key_exists('public_path_suffix', $data)
                    ? WebPathResolver::normalizePublicPathSuffix((string) $data['public_path_suffix'])
                    : null
            ),
            'requirementLabel' => \SimpleKuma\Database\DatabaseCompatibility::getRequirementLabel(),
            'csrfError' => $csrfError,
        ]);
    }

    private function guessBaseUrl(): string
    {
        return WebPathResolver::guessBaseUrl();
    }

    /**
     * Show config file generation step
     */
    private function showConfigStep(): void
    {
        if (empty($_SESSION['database_configured']) && !$this->stateDetector->detect()['hasValidConfig']) {
            header('Location: install.php?step=' . self::STEP_DATABASE);
            exit;
        }

        $writer = new ConfigWriter();
        $errors = [];
        $success = false;
        $csrfError = null;

        if ($writer->hasValidConfig()) {
            $_SESSION['config_written'] = true;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->requireValidCsrf()) {
                $csrfError = InstallerCsrf::invalidRequestMessage();
            } elseif ($writer->hasValidConfig()) {
                $errors[] = 'Configuration file already exists. Delete config/config.php to reinstall.';
            } elseif (empty($_SESSION['db_config']) || empty($_SESSION['site_config'])) {
                $errors[] = 'Database configuration not found in session. Please complete the database step again.';
            } elseif ($writer->write($_SESSION['db_config'], $_SESSION['site_config'])) {
                $_SESSION['config_written'] = true;
                unset($_SESSION['db_config']);
                $success = true;
                header('refresh:2;url=install.php?step=' . self::STEP_MIGRATIONS);
            } else {
                $errors = $writer->getErrors();
            }
        }

        $this->renderView('config', [
            'dbConfig' => $_SESSION['db_config'] ?? ['host' => '***', 'name' => '***', 'user' => '***'],
            'siteConfig' => $_SESSION['site_config'] ?? [],
            'errors' => $errors,
            'success' => $success,
            'csrfError' => $csrfError,
        ]);
    }

    /**
     * Show migrations step
     */
    private function showMigrationsStep(): void
    {
        $state = $this->stateDetector->detect();

        if (empty($_SESSION['config_written']) && !$state['hasValidConfig']) {
            header('Location: install.php?step=' . self::STEP_CONFIG);
            exit;
        }

        if ($state['hasValidConfig']) {
            $_SESSION['config_written'] = true;
        }

        require_once dirname(__DIR__, 2) . '/config/config.php';

        $errors = [];
        $success = false;
        $applied = [];
        $dbServerInfo = null;
        $dbVersionError = null;
        $lastAppliedMigration = null;
        $csrfError = null;
        $expectedMigrationCount = $this->stateDetector->countMigrationFiles();

        mysqli_report(MYSQLI_REPORT_OFF);
        $dbProbe = @new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if (!$dbProbe->connect_error) {
            $dbServerInfo = \SimpleKuma\Database\DatabaseCompatibility::formatServerSummary($dbProbe->server_info);
            $dbVersionError = \SimpleKuma\Database\DatabaseCompatibility::validateConnection($dbProbe);
            $lastAppliedMigration = $this->fetchLastAppliedMigration($dbProbe);
            $dbProbe->close();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->requireValidCsrf()) {
                $csrfError = InstallerCsrf::invalidRequestMessage();
            } else {
                mysqli_report(MYSQLI_REPORT_OFF);
                $db = @new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

                if ($db->connect_error) {
                    $errors[] = 'Database connection failed: ' . $db->connect_error;
                } else {
                    $runner = new \SimpleKuma\Database\MigrationRunner($db);

                    if ($runner->run()) {
                        $applied = $runner->getAppliedMigrations();
                        $_SESSION['migrations_run'] = true;
                        $success = true;
                        header('refresh:2;url=install.php?step=' . self::STEP_ADMIN);
                    } else {
                        $errors = $runner->getErrors();
                        $lastAppliedMigration = $this->fetchLastAppliedMigration($db);
                    }

                    $db->close();
                }
            }
        } elseif ($state['pendingMigrationCount'] === 0 && $state['hasMigrationsTable']) {
            $_SESSION['migrations_run'] = true;
        }

        $this->renderView('migrations', [
            'errors' => $errors,
            'success' => $success,
            'applied' => $applied,
            'dbServerInfo' => $dbServerInfo,
            'dbVersionError' => $dbVersionError,
            'expectedMigrationCount' => $expectedMigrationCount,
            'lastAppliedMigration' => $lastAppliedMigration,
            'csrfError' => $csrfError,
        ]);
    }

    /**
     * Show admin user creation step
     */
    private function showAdminStep(): void
    {
        $state = $this->stateDetector->detect();

        if (empty($_SESSION['migrations_run']) && $state['pendingMigrationCount'] > 0) {
            header('Location: install.php?step=' . self::STEP_MIGRATIONS);
            exit;
        }

        if ($state['pendingMigrationCount'] === 0) {
            $_SESSION['migrations_run'] = true;
        }

        require_once dirname(__DIR__, 2) . '/config/config.php';

        $errors = [];
        $success = false;
        $csrfError = null;
        $loginUrl = InstallerLock::getLoginUrl();
        $adminExists = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->requireValidCsrf()) {
                $csrfError = InstallerCsrf::invalidRequestMessage();
            } else {
                $adminConfig = $_SESSION['admin_config'] ?? [];

                if (empty($adminConfig)) {
                    if ($state['userCount'] > 0) {
                        $adminExists = true;
                        $errors[] = 'Admin account already exists. Continue to login.';
                    } else {
                        $errors[] = 'Admin configuration not found in session. Use "Resume installation" on step 1 or re-enter admin details on the database step.';
                    }
                } else {
                    mysqli_report(MYSQLI_REPORT_OFF);
                    $db = @new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

                    if ($db->connect_error) {
                        $errors[] = 'Database connection failed: ' . $db->connect_error;
                    } else {
                        $stmt = $db->prepare('SELECT COUNT(*) as count FROM users WHERE username = ?');
                        $stmt->bind_param('s', $adminConfig['username']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $row = $result->fetch_assoc();

                        if ((int) ($row['count'] ?? 0) > 0) {
                            $adminExists = true;
                            $errors[] = 'Admin user already exists. Installation may have already been completed.';
                        } else {
                            $passwordHash = null;
                            try {
                                $passwordHash = \SimpleKuma\Auth\PasswordHasher::hash(
                                    $adminConfig['password']
                                );
                            } catch (\Throwable $e) {
                                $errors[] = 'Failed to hash admin password: ' . $e->getMessage()
                                    . ' Ensure PHP Argon2 or password hashing is available, and memory_limit is at least 128M.';
                            }

                            if ($passwordHash !== null) {
                                $stmt = $db->prepare(
                                    'INSERT INTO users (username, pass_hash, email, timezone, currency, created_at)
                                     VALUES (?, ?, ?, \'UTC\', \'USD\', NOW())'
                                );
                                $stmt->bind_param('sss', $adminConfig['username'], $passwordHash, $adminConfig['email']);

                                if ($stmt->execute()) {
                                    $userId = (int) $db->insert_id;
                                    $roleError = $this->assignAdminRole($db, $userId);

                                    if ($roleError !== null) {
                                        $del = $db->prepare('DELETE FROM users WHERE id = ?');
                                        if ($del) {
                                            $del->bind_param('i', $userId);
                                            $del->execute();
                                            $del->close();
                                        }
                                        $errors[] = $roleError;
                                    } else {
                                        $_SESSION['admin_created'] = true;
                                        $success = true;
                                        unset($_SESSION['admin_config']);
                                        header('refresh:2;url=install.php?step=' . self::STEP_COMPLETE);
                                    }
                                } else {
                                    $errors[] = 'Failed to create admin user: ' . $db->error;
                                }
                            }
                        }

                        $db->close();
                    }
                }
            }
        } elseif ($state['userCount'] > 0 && $state['adminWithRole']) {
            $_SESSION['admin_created'] = true;
            header('Location: install.php?step=' . self::STEP_COMPLETE);
            exit;
        }

        $this->renderView('admin', [
            'adminConfig' => $_SESSION['admin_config'] ?? [],
            'errors' => $errors,
            'success' => $success,
            'csrfError' => $csrfError,
            'loginUrl' => $loginUrl,
            'adminExists' => $adminExists,
        ]);
    }

    /**
     * Assign admin role; returns error message or null on success.
     */
    private function assignAdminRole(\mysqli $db, int $userId): ?string
    {
        if (\SimpleKuma\Auth\SingleAdminMode::getAdminRoleId($db) === null) {
            return 'Admin role not found in database. Run migrations again — migration 032 seeds the admin role.';
        }

        \SimpleKuma\Auth\SingleAdminMode::ensureAdminRoleForUser($db, $userId);

        return null;
    }

    /**
     * Show completion step
     */
    private function showCompleteStep(): void
    {
        $state = $this->stateDetector->detect();

        if (empty($_SESSION['admin_created']) && !$state['isComplete']) {
            header('Location: install.php?step=' . self::STEP_ADMIN);
            exit;
        }

        require_once dirname(__DIR__, 2) . '/config/config.php';

        $configWriter = new ConfigWriter();
        if (!$configWriter->markInstallationComplete()) {
            error_log('Installer: markInstallationComplete failed: ' . implode('; ', $configWriter->getErrors()));
        }

        $installerDeleted = InstallerLock::tryDeleteInstallerFile();
        $loginUrl = InstallerLock::getLoginUrl();
        $appBaseUrl = defined('APP_BASE_URL') ? APP_BASE_URL : WebPathResolver::buildAppBaseUrl(BASE_URL);

        $this->renderView('complete', [
            'baseUrl' => BASE_URL,
            'appBaseUrl' => $appBaseUrl,
            'loginUrl' => $loginUrl,
            'installerDeleted' => $installerDeleted,
        ]);
    }

    private function fetchLastAppliedMigration(\mysqli $db): ?string
    {
        $tableCheck = $db->query("SHOW TABLES LIKE 'migrations'");
        if ($tableCheck === false || $tableCheck->num_rows === 0) {
            if ($tableCheck) {
                $tableCheck->free();
            }

            return null;
        }
        $tableCheck->free();

        $result = $db->query(
            'SELECT migration FROM migrations ORDER BY applied_at DESC, id DESC LIMIT 1'
        );
        if ($result === false) {
            return null;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return isset($row['migration']) ? (string) $row['migration'] : null;
    }

    /**
     * Render a view
     */
    private function renderView(string $view, array $data = []): void
    {
        $viewPath = __DIR__ . "/../../views/installer/{$view}.php";
        if (!is_file($viewPath)) {
            throw new \RuntimeException("Installer view not found: {$view}.php");
        }

        $data['installerAssetsBase'] = WebPathResolver::getPublicWebPath();
        $data['csrfField'] = InstallerCsrf::field();
        extract($data, EXTR_SKIP);
        require $viewPath;
    }
}
