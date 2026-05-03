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
    // Production tenant + token-hash registry. Lives outside the repo so
    // tenant slugs/names don't leak via the public source. Managed by
    // the crashler:tenant:add task (see below).
    'config/packages/prod/crashler.yaml',
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

// Bootstrap the shared tenants YAML so the kernel can boot before any
// tenant has been registered. Symfony's config loader fails on an empty
// file; this idempotently writes a valid 'tenants: {}' if the file is
// missing or zero-bytes. Subsequent deploys are no-ops.
task('crashler:bootstrap_tenants_yaml', function () {
    $dir = '{{deploy_path}}/shared/config/packages/prod';
    $path = "$dir/crashler.yaml";
    run("mkdir -p $dir");
    if (test("[ -s $path ]")) {
        return;
    }
    run("printf 'crashler:\\n    tenants: {}\\n' > $path");
    info('Bootstrapped shared/config/packages/prod/crashler.yaml (empty tenants)');
});
before('deploy:shared', 'crashler:bootstrap_tenants_yaml');

// Add a tenant to the production registry. Generates a fresh plaintext
// token on the host (the secret never leaves it except for the one-time
// stdout print at the end), records its SHA-256 hash in the shared YAML,
// and clears the prod container cache so the new tenant is picked up
// without a redeploy.
//
// Usage: dep crashler:tenant:add --slug=<slug> [--name='<display name>'] stage=production
task('crashler:tenant:add', function () {
    $slug = (string) input()->getOption('slug');
    $name = (string) input()->getOption('name') ?: $slug;

    if ('' === $slug || 1 !== preg_match('/^[a-z][a-z0-9-]{2,31}$/', $slug) || str_ends_with($slug, '-')) {
        throw new \RuntimeException(\sprintf(
            'Invalid slug "%s": must match ^[a-z][a-z0-9-]{2,31}$ and not end with "-".',
            $slug,
        ));
    }
    // Disallow YAML-breaking characters in the display name.
    if (1 === preg_match("/['\"\\n\\r\\t]/", $name)) {
        throw new \RuntimeException('Tenant name must not contain quotes, newlines, or tabs.');
    }

    $shared = '{{deploy_path}}/shared/config/packages/prod/crashler.yaml';
    $tmp = "$shared.tmp";

    $script = <<<'BASH'
set -e
plaintext="cw_$(openssl rand -hex 16)"
hash=$(printf '%s' "$plaintext" | shasum -a 256 | cut -d' ' -f1)

# Append-or-create: read existing tenants block via PHP, add the new
# entry, dump back. Falls back to a clean single-tenant file when the
# YAML is empty or contains only the bootstrap "tenants: {}".
"$BIN_PHP" -r "
  require '$VENDOR_AUTOLOAD';
  use Symfony\\Component\\Yaml\\Yaml;
  \$cfg = file_get_contents('$SHARED');
  \$data = '' === trim(\$cfg) ? ['crashler' => ['tenants' => []]] : Yaml::parse(\$cfg);
  if (!isset(\$data['crashler']['tenants']) || !is_array(\$data['crashler']['tenants'])) {
      \$data['crashler']['tenants'] = [];
  }
  if (isset(\$data['crashler']['tenants']['$SLUG'])) {
      fwrite(STDERR, 'tenant \"$SLUG\" already exists; appending an additional token hash to it' . PHP_EOL);
      \$data['crashler']['tenants']['$SLUG']['token_hashes'][] = '$hash';
  } else {
      \$data['crashler']['tenants']['$SLUG'] = [
          'name' => '$NAME',
          'token_hashes' => ['$hash'],
      ];
  }
  file_put_contents('$TMP', Yaml::dump(\$data, 6, 4));
"
mv "$TMP" "$SHARED"
chmod 600 "$SHARED"

echo "===CRASHLER_NEW_TENANT_TOKEN==="
echo "$plaintext"
echo "===END==="
BASH;

    $vendorAutoload = '{{deploy_path}}/current/vendor/autoload.php';
    $output = run(
        "BIN_PHP={{bin/php}} VENDOR_AUTOLOAD=$vendorAutoload SHARED=$shared TMP=$tmp SLUG='$slug' NAME='$name' bash -c '$script'"
    );

    // Refresh the prod container so the new tenant is live immediately.
    run("{{bin/php}} {{deploy_path}}/current/bin/console cache:clear --env=prod --no-debug");

    info("Tenant '$slug' registered. Plaintext token follows (shown once):");
    writeln('');
    writeln($output);
    writeln('');
    info('Store the token in your client; the server only retains its hash.');
});
option('slug', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Tenant slug');
option('name', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Tenant display name (defaults to slug)');

// Hooks ------------------------------------------------------------------

after('deploy:failed', 'deploy:unlock');
