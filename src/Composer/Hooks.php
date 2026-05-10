<?php

declare(strict_types=1);

namespace App\Composer;

use Composer\Script\Event;

/**
 * Composer post-install hooks for crashler.
 *
 * Currently surfaces a one-liner activation hint for the project's
 * pre-commit hook when:
 * - the install runs in an interactive terminal,
 * - the repo's `.githooks/` directory exists, and
 * - the contributor has not yet pointed `core.hooksPath` at it.
 *
 * Never mutates `git config` directly — that's a surprise. The hint is
 * informational only.
 */
final class Hooks
{
    public static function printDevHints(Event $event): void
    {
        // Skip in non-interactive environments (CI, scripted installs).
        if (!self::isInteractive()) {
            return;
        }

        $repoRoot = self::repoRoot();
        if ('' === $repoRoot) {
            return;
        }

        $hooksDir = $repoRoot.\DIRECTORY_SEPARATOR.'.githooks';
        if (!is_dir($hooksDir)) {
            return;
        }

        if (self::isHooksPathConfigured($repoRoot)) {
            return;
        }

        $io = $event->getIO();
        $io->write('');
        $io->write('<info>Quality stack tip:</info> activate the project pre-commit hook with');
        $io->write('  <comment>git config core.hooksPath .githooks</comment>');
        $io->write('Then run <comment>composer tools:install</comment> once to populate tool vendors.');
        $io->write('');
    }

    private static function isInteractive(): bool
    {
        if (!\function_exists('posix_isatty')) {
            return false;
        }

        return @posix_isatty(\STDIN) && @posix_isatty(\STDOUT);
    }

    private static function repoRoot(): string
    {
        $candidate = \dirname(__DIR__, 2);

        return is_dir($candidate.\DIRECTORY_SEPARATOR.'.git')
            || is_file($candidate.\DIRECTORY_SEPARATOR.'.git')
            ? $candidate
            : '';
    }

    private static function isHooksPathConfigured(string $repoRoot): bool
    {
        $cmd = \sprintf('git -C %s config --get core.hooksPath 2>/dev/null', escapeshellarg($repoRoot));
        $current = trim((string) shell_exec($cmd));
        if ('' === $current) {
            return false;
        }

        // Either an absolute path matching ours, or the literal `.githooks`.
        return str_ends_with($current, '.githooks');
    }
}
