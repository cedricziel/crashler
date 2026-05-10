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
// HTTPS clone works without a deploy key while the repo is public.
// If the repo ever goes private, switch to git@github.com:cedricziel/crashler.git
// and provision a read-only deploy key on the deployment host.
set('repository', 'https://github.com/cedricziel/crashler.git');

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

// On a fresh host, the shared .env.local doesn't exist yet, so when
// Composer's post-install-cmd runs `cache:clear`, the kernel falls back
// to APP_ENV=dev (from committed .env) and dies trying to load DebugBundle
// — which --no-dev excluded. Bootstrap the file once with sane prod
// defaults and a freshly generated APP_SECRET (created on the host so
// it never leaves it). Subsequent deploys are no-ops.
task('crashler:bootstrap_env_local', function () {
    $path = '{{deploy_path}}/shared/.env.local';
    if (test("[ -s $path ]")) {
        return;
    }
    run("set -e; secret=\$(openssl rand -hex 16); printf 'APP_ENV=prod\\nAPP_DEBUG=0\\nAPP_SECRET=%s\\n' \"\$secret\" > $path; chmod 600 $path");
    info('Bootstrapped shared/.env.local — APP_ENV=prod, fresh APP_SECRET');
});
before('deploy:vendors', 'crashler:bootstrap_env_local');

// One-shot, opt-in purge of the existing Parquet files under
// shared/var/share/logs/. Used during the refactor-multi-signal-receiver
// rollout: the v0 schema's column names (service_name, etc.) are
// incompatible with the v1 layout, and the data has zero retention value
// (only smoke-test files). Set CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY=1 in the
// shell that invokes 'dep deploy production' to fire this task; otherwise
// it's a no-op. Subsequent deploys SHOULD NOT keep this flag set.
task('crashler:purge_old_logs', function () {
    if ('1' !== getenv('CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY')) {
        return;
    }

    $logsDir = '{{deploy_path}}/shared/var/share/logs';
    if (!test("[ -d $logsDir ]")) {
        info('No existing shared/var/share/logs/ directory; nothing to purge.');

        return;
    }

    $count = (int) trim((string) run("find $logsDir -type f -name '*.parquet' 2>/dev/null | wc -l"));
    if (0 === $count) {
        info('Existing log directory has no parquet files; nothing to purge.');

        return;
    }

    info("Purging $count Parquet file(s) under $logsDir (CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY=1).");
    run("find $logsDir -type f -name '*.parquet' -delete");
    run("find $logsDir -type d -empty -delete");
    info('Purge complete. The new release will repopulate the directory under the v1 schema.');
});
before('deploy:vendors', 'crashler:purge_old_logs');

// Two-step asset deploy for /docs (Swagger UI) to load cleanly:
//
//   1. `assets:install public` — copies each bundle's Resources/public/
//      into public/bundles/<bundle>/. Flex's auto-scripts try to do
//      this from composer post-install-cmd, but a silent failure under
//      --no-dev leaves the directory empty. Running it explicitly is
//      defensive; the symlink-dereferencer that follows is for managed
//      hosts (All-Inkl) where Apache's FollowSymLinks is restricted
//      and absolute symlinks would break across release cleanup.
//
//   2. `asset-map:compile` — materialises Symfony AssetMapper's
//      hashed-URL output into public/assets/. The api-platform docs
//      template references its CSS/JS via AssetMapper's hashed scheme
//      (e.g. /assets/bundles/apiplatform/style-3GfETb1.css), so without
//      this step the Swagger UI page renders but its assets 404.
//      Idempotent — re-running rewrites the manifest deterministically.
task('crashler:assets_install', function () {
    run('cd {{release_path}} && {{bin/php}} bin/console assets:install public --env=prod --no-debug');
    run(<<<'BASH'
        cd {{release_path}}/public/bundles 2>/dev/null || exit 0
        find . -mindepth 1 -maxdepth 1 -type l | while read -r link; do
            target=$(readlink -f "$link")
            if [ -d "$target" ]; then
                rm "$link"
                cp -rL "$target" "$link"
            fi
        done
        BASH);
    run('cd {{release_path}} && {{bin/php}} bin/console asset-map:compile --env=prod --no-debug');
});
after('deploy:vendors', 'crashler:assets_install');

// Bootstrap DATABASE_URL into the shared/.env.local on first deploy.
// Reads the connection string from PRODUCTION_DATABASE_URL (set in
// .env.deploy on the operator's machine; the file is gitignored so
// credentials never reach the repo). Appends only when the line is not
// already present in shared/.env.local — operators rotating credentials
// edit shared/.env.local directly on the host without re-running this.
task('crashler:bootstrap_database_url', function () {
    $url = (string) (getenv('PRODUCTION_DATABASE_URL') ?: '');
    if ('' === $url) {
        info('PRODUCTION_DATABASE_URL is not set in .env.deploy; skipping DATABASE_URL bootstrap (assuming shared/.env.local already carries it).');

        return;
    }

    $path = '{{deploy_path}}/shared/.env.local';
    if (test("[ -s $path ]") && '0' !== run("grep -q '^DATABASE_URL=' $path && echo 1 || echo 0")) {
        info('shared/.env.local already declares DATABASE_URL; not overwriting.');

        return;
    }

    // Symfony Dotenv parses DATABASE_URL line literally; quote with double
    // quotes to handle '!' and special characters cleanly.
    $escaped = str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $url);
    run(\sprintf("set -e; printf '\\nDATABASE_URL=\"%s\"\\n' >> %s; chmod 600 %s", $escaped, $path, $path));
    info('Appended DATABASE_URL to shared/.env.local.');
});
before('deploy:vendors', 'crashler:bootstrap_database_url');

// Run Doctrine migrations after vendors are installed and shared resources
// are linked, but before deploy:symlink swaps the current/ symlink. The
// Symfony recipe ships `database:migrate` but does not hook it into the
// flow; we wire it explicitly so a fresh release that introduces a new
// migration is applied automatically. `--allow-no-migration` keeps deploys
// idempotent when there is nothing to apply.
task('crashler:migrate', function () {
    run('cd {{release_path}} && {{bin/php}} bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=prod --no-debug');
});
after('deploy:vendors', 'crashler:migrate');

// Hooks ------------------------------------------------------------------

after('deploy:failed', 'deploy:unlock');
