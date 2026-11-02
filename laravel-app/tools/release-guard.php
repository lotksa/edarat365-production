<?php

/** Reviewed cPanel release helper. Never seeds, cleans user data or replaces storage links. */
declare(strict_types=1);

const LIVE_APP = '/home1/edarat/laravel-app';
const LIVE_WEB = '/home1/edarat/public_html';
const LIVE_REPO = '/home1/edarat/repositories/edarat365-v4';
const BACKUP_ROOT = '/home1/edarat/deploy-backups';
const PHP_EXE = '/opt/cpanel/ea-php85/root/usr/bin/php';

function requireRelease(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function runRelease(array $arguments): string
{
    $pipes = [];
    $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, LIVE_APP);
    requireRelease(is_resource($process), 'Cannot start release command');
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    requireRelease(proc_close($process) === 0, 'Release command failed: '.$arguments[0].' '.substr($errors, 0, 1000));

    return $output;
}

function releasePaths(): void
{
    foreach ([LIVE_APP, LIVE_WEB, LIVE_REPO, LIVE_APP.'/storage/app/public'] as $path) {
        requireRelease(realpath($path) === $path, 'Unexpected live path: '.$path);
    }
    requireRelease(is_link(LIVE_WEB.'/storage') && realpath(LIVE_WEB.'/storage') === LIVE_APP.'/storage/app/public', 'Storage must remain the verified symlink');
    requireRelease(is_file(LIVE_APP.'/.env') && filesize(LIVE_APP.'/.env') > 0, 'Production environment missing');
}

function releaseDb(): PDO
{
    static $pdo;
    if (! $pdo) {
        require_once LIVE_APP.'/vendor/autoload.php';
        $application = require LIVE_APP.'/bootstrap/app.php';
        $application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
    }

    return $pdo;
}

function releaseSnapshot(?array $columns = null): array
{
    $pdo = releaseDb();
    if ($columns === null) {
        $names = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $volatile = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'login_otps', 'personal_access_tokens', 'notifications', 'activity_logs', 'security_audit_logs'];
        $columns = [];
        foreach (array_diff($names, $volatile) as $table) {
            requireRelease((bool) preg_match('/^[a-zA-Z0-9_]+$/D', $table), 'Unexpected table name');
            $fields = $pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
            if ($table === 'users') {
                $fields = array_values(array_diff($fields, ['last_login_at', 'last_login_ip', 'failed_login_attempts', 'login_attempts', 'locked_until', 'updated_at']));
            }
            $columns[$table] = $fields;
        }
    }
    $tables = [];
    foreach ($columns as $table => $fields) {
        $rows = [];
        $query = $pdo->query('SELECT `'.implode('`,`', $fields).'` FROM `'.$table.'`');
        while ($row = $query->fetch(PDO::FETCH_NUM)) {
            $digest = hash('sha256', serialize($row));
            $rows[$digest] = ($rows[$digest] ?? 0) + 1;
        }
        ksort($rows);
        $tables[$table] = ['count' => array_sum($rows), 'rows' => $rows];
    }
    $files = [];
    $root = LIVE_APP.'/storage/app/public';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() || $file->isLink()) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $files[$relative] = $file->isLink() ? 'link:'.readlink($file->getPathname()) : hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files);

    return ['columns' => $columns, 'tables' => $tables, 'uploads' => $files, 'env_sha256' => hash_file('sha256', LIVE_APP.'/.env')];
}

function verifySnapshot(array $before): array
{
    $after = releaseSnapshot($before['columns']);
    requireRelease(hash_equals($before['env_sha256'], $after['env_sha256']), 'Production .env changed');
    foreach ($before['tables'] as $table => $value) {
        foreach ($value['rows'] as $digest => $count) {
            requireRelease(($after['tables'][$table]['rows'][$digest] ?? 0) >= $count, 'Existing rows changed or missing: '.$table);
        }
    }
    foreach ($before['uploads'] as $path => $digest) {
        requireRelease(($after['uploads'][$path] ?? null) === $digest, 'Existing upload changed or missing');
    }

    return ['tables_verified' => count($before['tables']), 'existing_rows_verified' => array_sum(array_column($before['tables'], 'count')), 'uploads_verified' => count($before['uploads']), 'environment_unchanged' => true];
}

function writeReleaseJson(string $path, array $data): void
{
    requireRelease(file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== false, 'Cannot write release metadata');
    chmod($path, 0600);
}

function verifyBackup(string $directory): array
{
    requireRelease(dirname($directory) === BACKUP_ROOT && realpath($directory) === $directory && ! is_link($directory), 'Unexpected backup path');
    $meta = json_decode(file_get_contents($directory.'/verified.json'), true, 512, JSON_THROW_ON_ERROR);
    requireRelease(time() - $meta['created_at'] < 21600 && $meta['created_at'] <= time(), 'Fresh backups are required');
    foreach ($meta['archives'] as $name => $digest) {
        requireRelease(is_file($directory.'/'.$name) && filesize($directory.'/'.$name) > 0 && hash_file('sha256', $directory.'/'.$name) === $digest, 'Backup archive verification failed');
    }
    runRelease(['gzip', '-t', $directory.'/database.sql.gz']);
    runRelease(['tar', '-tzf', $directory.'/uploads.tar.gz']);

    return $meta;
}

function backupRelease(string $directory): void
{
    requireRelease(dirname($directory) === BACKUP_ROOT && ! file_exists($directory), 'Use a new backup directory');
    $pdo = releaseDb();
    $size = (int) $pdo->query('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
    requireRelease($size < 1024 * 1024 * 1024 && disk_free_space('/home1/edarat') > $size * 3 + 128 * 1024 * 1024, 'Backup size or free-space limit requires operator review');
    if (! is_dir(BACKUP_ROOT)) mkdir(BACKUP_ROOT, 0700, true);
    requireRelease(realpath(BACKUP_ROOT) === BACKUP_ROOT, 'Unexpected backup parent');
    mkdir($directory, 0700);
    $before = releaseSnapshot();
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $connection = config('database.connections.'.config('database.default'));
    $client = $directory.'/client.cnf';
    $ini = "[client]\n";
    foreach (['user' => $connection['username'], 'password' => $connection['password'], 'host' => $connection['host'] ?? 'localhost', 'port' => $connection['port'] ?? 3306] as $key => $value) {
        $ini .= $key.'="'.str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', ''], (string) $value)."\"\n";
    }
    requireRelease(file_put_contents($client, $ini) !== false, 'Cannot prepare private database client configuration');
    chmod($client, 0600);
    try {
        $dump = 'mysqldump --defaults-extra-file='.escapeshellarg($client).' --single-transaction --routines --triggers --events --hex-blob --no-tablespaces --default-character-set=utf8mb4 '.escapeshellarg($database).' | gzip -c > '.escapeshellarg($directory.'/database.sql.gz');
        runRelease(['/bin/bash', '-o', 'pipefail', '-c', $dump]);
    } finally {
        unlink($client); // Only the task-created private client configuration.
    }
    runRelease(['gzip', '-t', $directory.'/database.sql.gz']);
    $ending = runRelease(['/bin/bash', '-o', 'pipefail', '-c', 'gzip -dc '.escapeshellarg($directory.'/database.sql.gz').' | tail -n 5']);
    requireRelease(str_contains($ending, 'Dump completed'), 'Database dump is incomplete');
    runRelease(['tar', '-czf', $directory.'/uploads.tar.gz', '-C', LIVE_APP.'/storage/app', 'public']);
    runRelease(['tar', '-tzf', $directory.'/uploads.tar.gz']);
    requireRelease(copy(LIVE_APP.'/.env', $directory.'/production.env'), 'Cannot back up production environment');
    chmod($directory.'/production.env', 0600);
    $archives = [];
    foreach (['database.sql.gz', 'uploads.tar.gz', 'production.env'] as $file) {
        requireRelease(filesize($directory.'/'.$file) > 0, 'Empty backup');
        $archives[$file] = hash_file('sha256', $directory.'/'.$file);
    }
    $verified = verifySnapshot($before);
    writeReleaseJson($directory.'/verified.json', ['created_at' => time(), 'repo_commit' => trim(runRelease(['git', '-C', LIVE_REPO, 'rev-parse', 'HEAD'])), 'archives' => $archives, 'snapshot' => $before]);
    echo json_encode(['backup' => $directory, 'archive_bytes' => filesize($directory.'/database.sql.gz') + filesize($directory.'/uploads.tar.gz')] + $verified, JSON_UNESCAPED_SLASHES).PHP_EOL;
}

function deployRelease(string $directory): void
{
    $backup = verifyBackup($directory);
    $manifest = json_decode(file_get_contents(LIVE_REPO.'/release-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    requireRelease($backup['repo_commit'] === $manifest['previous_commit'], 'Backup does not match release parent');
    requireRelease(trim(runRelease(['git', '-C', LIVE_REPO, 'status', '--porcelain'])) === '', 'Server checkout must be clean');
    // Reviewed application release: code and assets only; no schema changes.
    $allowedMigrations = [];
    requireRelease($manifest['migrations'] === $allowedMigrations, 'Unexpected migration list');
    foreach ($manifest['files'] as $relative => $digest) {
        requireRelease((bool) preg_match('#^(laravel-app/(app|routes|resources|tools|database/(?:migrations|seeders))/[a-zA-Z0-9_./-]+|public_html/(assets/[a-zA-Z0-9_.-]+|index\.html|invoice-summary\.html))$#D', $relative) && ! str_contains($relative, '..'), 'Unreviewed release path');
        requireRelease(is_file(LIVE_REPO.'/'.$relative) && hash_file('sha256', LIVE_REPO.'/'.$relative) === $digest, 'Release source checksum mismatch');
    }
    verifySnapshot($backup['snapshot']);
    $webEntries = [];
    foreach ($manifest['files'] as $relative => $digest) {
        if (in_array($relative, ['public_html/index.html', 'public_html/invoice-summary.html'], true)) { $webEntries[$relative] = $digest; continue; }
        installReleaseFile($directory, $relative, $digest);
    }
    foreach ($allowedMigrations as $migration) {
        requireRelease(isset($manifest['files']['laravel-app/database/migrations/'.$migration]), 'Migration missing from release manifest');
        echo runRelease([PHP_EXE, '-d', 'session.upload_progress.enabled=0', LIVE_APP.'/artisan', 'migrate', '--force', '--no-interaction', '--path=database/migrations/'.$migration]);
    }
    foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
        echo runRelease([PHP_EXE, '-d', 'session.upload_progress.enabled=0', LIVE_APP.'/artisan', $command]);
    }
    foreach (['public_html/invoice-summary.html', 'public_html/index.html'] as $entry) {
        if (isset($webEntries[$entry])) installReleaseFile($directory, $entry, $webEntries[$entry]);
    }
    releasePaths();
    $verified = verifySnapshot($backup['snapshot']);
    writeReleaseJson($directory.'/deployed.json', ['commit' => trim(runRelease(['git', '-C', LIVE_REPO, 'rev-parse', 'HEAD'])), 'verified_at' => time()] + $verified);
    echo json_encode($verified).PHP_EOL;
    echo "REVIEWED_RELEASE_DEPLOYED\n";
}

function installReleaseFile(string $backup, string $relative, string $digest): void
{
    $destination = '/home1/edarat/'.$relative;
    $prefix = str_starts_with($relative, 'laravel-app/') ? LIVE_APP.'/' : LIVE_WEB.'/';
    $parent = dirname($destination);
    if (! is_dir($parent)) mkdir($parent, 0755, true);
    requireRelease(str_starts_with(realpath($parent).'/', $prefix) && ! is_link($destination), 'Unexpected release destination');
    if (is_file($destination) && hash_file('sha256', $destination) === $digest) return;
    if (is_file($destination)) {
        $original = $backup.'/code/'.$relative;
        if (! is_dir(dirname($original))) mkdir(dirname($original), 0700, true);
        requireRelease(copy($destination, $original), 'Cannot preserve previous code');
    }
    $temporary = $destination.'.edarat-release-next';
    requireRelease(! file_exists($temporary), 'Release staging file already exists');
    requireRelease(copy(LIVE_REPO.'/'.$relative, $temporary), 'Cannot stage release file');
    chmod($temporary, 0644);
    requireRelease(hash_file('sha256', $temporary) === $digest, 'Staged code checksum mismatch');
    requireRelease(rename($temporary, $destination), 'Cannot install release file');
}

if (PHP_SAPI !== 'cli' || ! in_array($argv[1] ?? '', ['backup', 'deploy', 'verify'], true)) {
    fwrite(STDERR, "Usage: php release-guard.php backup|deploy|verify /home1/edarat/deploy-backups/NEW_RELEASE\n");
    exit(2);
}
try {
    umask(0077);
    releasePaths();
    $backup = $argv[2] ?? '';
    match ($argv[1]) {
        'backup' => backupRelease($backup),
        'deploy' => deployRelease($backup),
        'verify' => print(json_encode(verifySnapshot(verifyBackup($backup)['snapshot'])).PHP_EOL),
    };
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release stopped: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
