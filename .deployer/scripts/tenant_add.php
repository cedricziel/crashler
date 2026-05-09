<?php

declare(strict_types=1);

/**
 * Deployer-side script to add a tenant to the shared production crashler.yaml.
 *
 * Invoked by the crashler:tenant:add task; not part of the application.
 *
 * Args:
 *   1: absolute path to shared crashler.yaml
 *   2: tenant slug (already validated by the Deployer task)
 *   3: tenant display name (already validated)
 */

if ($argc !== 4) {
    fwrite(STDERR, "usage: tenant_add.php <yaml-path> <slug> <name>\n");
    exit(2);
}

[$_, $yamlPath, $slug, $name] = $argv;

// Script lives at {{deploy_path}}/.dep/tenant_add.php; the active release's
// autoload is at {{deploy_path}}/current/vendor/autoload.php.
$autoload = dirname(__DIR__).'/current/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor autoload not found at $autoload (run a deploy first)\n");
    exit(2);
}
require $autoload;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

$fs = new Filesystem();

$existing = is_file($yamlPath) ? file_get_contents($yamlPath) : '';
$data = '' === trim((string) $existing)
    ? ['crashler' => ['tenants' => []]]
    : Yaml::parse((string) $existing);

if (!isset($data['crashler']['tenants']) || !\is_array($data['crashler']['tenants'])) {
    $data['crashler']['tenants'] = [];
}

$plaintext = 'cw_'.bin2hex(random_bytes(16));
$hash = hash('sha256', $plaintext);

if (isset($data['crashler']['tenants'][$slug])) {
    fwrite(STDERR, "tenant \"$slug\" already exists; appending an additional token hash\n");
    $data['crashler']['tenants'][$slug]['token_hashes'][] = $hash;
} else {
    $data['crashler']['tenants'][$slug] = [
        'name' => $name,
        'token_hashes' => [$hash],
    ];
}

// Atomic write: dumpFile writes to a sibling .tmp and renames into place.
$fs->dumpFile($yamlPath, Yaml::dump($data, 6, 4));
$fs->chmod($yamlPath, 0o600);

echo "===CRASHLER_NEW_TENANT_TOKEN===\n";
echo "slug=$slug\n";
echo "name=$name\n";
echo "plaintext=$plaintext\n";
echo "hash=$hash\n";
echo "===END===\n";
