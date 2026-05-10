<?php

declare(strict_types=1);

namespace App\Console;

use App\Entity\Tenant as TenantEntity;
use App\Entity\TenantToken;
use App\Repository\OrgRepository;
use App\Repository\TenantRepository;
use App\Repository\TenantTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One-shot migration: import tenants from `crashler.tenants` (YAML) into
 * the database, parented under a specified Org.
 *
 * Idempotent on the slug — existing DB Tenants with the same slug are
 * skipped (assumed already imported). Existing TenantToken hashes are
 * also skipped (uniqueness enforced by DB). Tokens carry no audit
 * metadata since YAML doesn't track who issued them; created_by stays
 * NULL and the token name defaults to `--name-prefix=<slug>`.
 *
 * Defaults to dry-run so an operator can preview before committing.
 * Pass `--apply` to actually persist.
 */
#[AsCommand(
    name: 'crashler:tenants:import-yaml',
    description: 'Import tenants and token hashes from crashler.yaml into the database under a target Org',
)]
final class ImportYamlTenantsCommand extends Command
{
    /**
     * @param array<string, array{name: string, token_hashes: list<string>}> $yamlTenants
     */
    public function __construct(
        #[Autowire(param: 'crashler.tenants_validated')]
        private readonly array $yamlTenants,
        private readonly EntityManagerInterface $em,
        private readonly OrgRepository $orgs,
        private readonly TenantRepository $tenants,
        private readonly TenantTokenRepository $tokens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('org', null, InputOption::VALUE_REQUIRED, 'Slug of the parent Org to attach imported tenants to')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist changes; without this flag the command runs as a dry-run')
            ->addOption('name-prefix', null, InputOption::VALUE_REQUIRED, 'Prefix for the imported TenantToken.name (joined to the slug)', 'imported-from-yaml')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $orgSlug = (string) $input->getOption('org');
        if ('' === $orgSlug) {
            $io->error('Missing --org=<slug>. Provide the slug of the Org these tenants should land under.');

            return Command::FAILURE;
        }

        $org = $this->orgs->findOneBySlug($orgSlug);
        if (null === $org) {
            $io->error(\sprintf('Org with slug "%s" does not exist. Create it via /admin first.', $orgSlug));

            return Command::FAILURE;
        }

        if ([] === $this->yamlTenants) {
            $io->success('No tenants in crashler.tenants — nothing to import.');

            return Command::SUCCESS;
        }

        $apply = (bool) $input->getOption('apply');
        $namePrefix = (string) $input->getOption('name-prefix');

        $io->section($apply ? \sprintf('Importing into Org "%s"', $orgSlug) : \sprintf('Dry-run import into Org "%s" (no changes will be persisted)', $orgSlug));

        $imported = 0;
        $skippedTenants = 0;
        $importedTokens = 0;
        $skippedTokens = 0;
        $rows = [];

        // Track hashes claimed during this run so a second YAML tenant that
        // ships the same hash doesn't try to insert it twice (DB-level
        // uniqueness check would miss it because the first persist hasn't
        // flushed yet).
        $claimedThisRun = [];

        foreach ($this->yamlTenants as $slug => $tenantConfig) {
            $existing = $this->tenants->findOneBySlug($slug);
            if (null !== $existing) {
                $rows[] = [$slug, '— skipped —', 'tenant already exists in DB', \count($tenantConfig['token_hashes']).' hash(es) ignored'];
                ++$skippedTenants;
                $skippedTokens += \count($tenantConfig['token_hashes']);
                continue;
            }

            $tenantRow = [$slug, $tenantConfig['name'], $orgSlug];

            $tenantTokenSummary = [];
            $persistedTokens = 0;
            $skippedTokensForThisTenant = 0;
            $hashesToImport = [];
            foreach ($tenantConfig['token_hashes'] as $hash) {
                $hashCollision = $this->tokens->findOneByHash($hash);
                if (null !== $hashCollision) {
                    $tenantTokenSummary[] = \sprintf('SKIP %s… (already in DB under tenant "%s")', substr($hash, 0, 8), (string) $hashCollision->getTenant()?->getSlug());
                    ++$skippedTokensForThisTenant;
                    continue;
                }
                if (isset($claimedThisRun[$hash])) {
                    $tenantTokenSummary[] = \sprintf('SKIP %s… (already claimed earlier in this run under tenant "%s")', substr($hash, 0, 8), $claimedThisRun[$hash]);
                    ++$skippedTokensForThisTenant;
                    continue;
                }
                $tenantTokenSummary[] = \sprintf('IMPORT %s…', substr($hash, 0, 8));
                $hashesToImport[] = $hash;
                $claimedThisRun[$hash] = $slug;
                ++$persistedTokens;
            }
            $tenantRow[] = implode(\PHP_EOL, $tenantTokenSummary) ?: '(no tokens)';
            $rows[] = $tenantRow;

            if ($apply) {
                $tenant = new TenantEntity();
                $tenant->setOrg($org);
                $tenant->setSlug($slug);
                $tenant->setName($tenantConfig['name']);
                $this->em->persist($tenant);

                foreach ($hashesToImport as $hash) {
                    $token = new TenantToken();
                    $token->setTenant($tenant);
                    $token->setName($namePrefix.'-'.$slug);
                    $token->setHash($hash);
                    $this->em->persist($token);
                }
            }

            ++$imported;
            $importedTokens += $persistedTokens;
            $skippedTokens += $skippedTokensForThisTenant;
        }

        $io->table(['YAML slug', 'name', 'parent org', 'tokens'], $rows);

        if ($apply) {
            $this->em->flush();
            $io->success(\sprintf(
                'Imported %d tenant(s) and %d token(s); skipped %d tenant(s) and %d token(s).',
                $imported,
                $importedTokens,
                $skippedTenants,
                $skippedTokens,
            ));
        } else {
            $io->warning(\sprintf(
                'Dry-run only. Would import %d tenant(s) and %d token(s); would skip %d tenant(s) and %d token(s). Re-run with --apply to commit.',
                $imported,
                $importedTokens,
                $skippedTenants,
                $skippedTokens,
            ));
        }

        return Command::SUCCESS;
    }
}
