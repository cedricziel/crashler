<?php

namespace Deployer;

use Symfony\Component\Dotenv\Dotenv;

// Pull in the project's vendored autoloader so we can reach Symfony Dotenv
// from a globally installed `dep` binary. Falls back silently if vendor/
// hasn't been installed yet — the .env.deploy loader becomes a no-op then.
if (is_file(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}

require 'recipe/symfony.php';

// Project ----------------------------------------------------------------

set('application', 'crashler');
set('repository', 'git@github.com:cedricziel/crashler.git');

// Pin the deployed PHP binary so Deployer doesn't accidentally pick a
// system php older than 8.5 on the host. Override per host via the
// DEPLOY_PHP_BIN env var if your distro names PHP differently.
set('bin/php', static fn (): string => getenv('DEPLOY_PHP_BIN') ?: '/usr/bin/env php8.5');

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

// On managed hosts (All-Inkl, Mittwald, …) there is no sudo and no
// separate web user — files are owned by the SSH user. Set
// DEPLOY_WRITABLE_MODE=chmod in .env.deploy to skip the chown step
// that the default 'acl' mode performs. Default stays 'acl' so a
// traditional VPS deploy is unaffected.
set('writable_mode', static fn (): string => getenv('DEPLOY_WRITABLE_MODE') ?: 'acl');

// Composer install on deploy: production deps only, optimized autoload,
// no dev. Aligns with what Symfony's recipe expects.
set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader');

// Hosts ------------------------------------------------------------------
//
// Hosts are configured purely from environment variables so this file
// (which is committed to a public repo) carries no hostnames, paths, or
// user names.
//
// Two ways to provide them:
//
//   1. Export them in your shell (or your CI's secret store):
//        export PRODUCTION_DEPLOY_HOST=server.example.com
//        export PRODUCTION_DEPLOY_PATH=/var/www/crashler
//        dep deploy production
//
//   2. Drop them into a gitignored .env.deploy at the repo root.
//      See .env.deploy.example for the full list.
//
// The variable prefix is the uppercased stage name. `dep deploy production`
// reads PRODUCTION_DEPLOY_*; `dep deploy staging` reads STAGING_DEPLOY_*.
// Stages with no HOST set are skipped silently, so an unconfigured stage
// fails fast with Deployer's "no hosts found" error instead of deploying
// somewhere unintended.

if (is_file($_deployEnv = __DIR__.'/.env.deploy') && class_exists(Dotenv::class)) {
    (new Dotenv())->usePutenv()->load($_deployEnv);
}

/**
 * Register a host whose connection details come from `${PREFIX}_DEPLOY_*`
 * environment variables. Returns silently when the HOST var is unset, so
 * deploy.php works on any machine that only configures a subset of stages.
 */
function configure_stage(string $stage): void
{
    $prefix = strtoupper($stage).'_DEPLOY_';
    $hostname = getenv($prefix.'HOST');
    if (false === $hostname || '' === $hostname) {
        return;
    }

    host($hostname)
        ->set('labels', ['stage' => $stage])
        ->set('remote_user', getenv($prefix.'USER') ?: 'deployer')
        ->set('deploy_path', getenv($prefix.'PATH') ?: '/var/www/crashler')
        ->set('http_user', getenv($prefix.'HTTP_USER') ?: 'www-data')
        ->set('branch', getenv($prefix.'BRANCH') ?: 'main')
    ;

    if ($port = getenv($prefix.'PORT')) {
        host($hostname)->set('port', (int) $port);
    }
    if ($identityFile = getenv($prefix.'IDENTITY_FILE')) {
        host($hostname)->set('identity_file', $identityFile);
    }
}

configure_stage('production');
configure_stage('staging');

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
