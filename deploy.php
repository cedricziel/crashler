<?php

namespace Deployer;

require 'recipe/symfony.php';

// Project ----------------------------------------------------------------

set('application', 'crashler');
set('repository', 'git@github.com:cedricziel/crashler.git');

// Pin the deployed PHP binary so Deployer doesn't accidentally pick a
// system php older than 8.4 on the host.
set('bin/php', function () {
    return parse_home_dir((string) get('php_path', '/usr/bin/env php8.4'));
});

// Symfony recipe wiring --------------------------------------------------

set('shared_files', [
    '.env.local',
]);

set('shared_dirs', [
    'var/log',
    // Where ingested Parquet files land. Persists across releases so you
    // never re-emit the same files after a deploy.
    'var/share',
]);

set('writable_dirs', [
    'var',
]);

set('migrations_config', '');
set('console_options', '--no-interaction');

// Composer install on deploy: production deps only, optimized autoload,
// no dev. Aligns with what Symfony's recipe expects.
set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader');

// Hosts ------------------------------------------------------------------
//
// Replace the placeholders below with your real host(s). The labels are
// freeform; "production" matches the convention `dep deploy production`.
// Until a real host is configured this file is intentionally non-functional
// (Deployer will refuse to deploy to an empty hostname).

// host('crashler.example.com')
//     ->set('labels', ['stage' => 'production'])
//     ->set('remote_user', 'deployer')
//     ->set('deploy_path', '/var/www/crashler')
//     ->set('http_user', 'www-data')
//     ->set('branch', 'main')
// ;

// Tasks ------------------------------------------------------------------

// After every successful deploy, ensure the Parquet storage root exists
// and has the right permissions. The shared dir is preserved across
// releases, but a fresh server needs the directory created.
task('crashler:ensure_storage', function () {
    run('mkdir -p {{deploy_path}}/shared/var/share/logs');
});
after('deploy:shared', 'crashler:ensure_storage');

// Hooks ------------------------------------------------------------------

after('deploy:failed', 'deploy:unlock');
