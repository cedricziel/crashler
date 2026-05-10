<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Org;
use App\Entity\Tenant;
use App\Entity\TenantToken;
use App\Security\IngestTokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

#[CoversClass(IngestTokenAuthenticator::class)]
final class IngestAuthenticationTest extends KernelTestCase
{
    use HasBrowser;

    /** Plaintext token whose SHA-256 hash is seeded in setUp() against `test-tenant`. */
    private const string VALID_TOKEN = 'cw_test_token_aaaaaaaaaaaaaaaaaa';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        // Reset the schema and seed `test-tenant` for the valid-token case.
        // The 401-path tests don't need the row but reuse the same setup
        // so each test starts with the same clean slate.
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $org = new Org();
        $org->setSlug('test-org');
        $org->setName('Test Org');
        $this->em->persist($org);

        $tenant = new Tenant();
        $tenant->setOrg($org);
        $tenant->setSlug('test-tenant');
        $tenant->setName('Test Tenant');
        $this->em->persist($tenant);

        $token = new TenantToken();
        $token->setTenant($tenant);
        $token->setName('test-token');
        $token->setHash(hash('sha256', self::VALID_TOKEN));
        $this->em->persist($token);

        $this->em->flush();
    }

    public function testValidTokenAttachesAuthenticatedTenant(): void
    {
        $this->browser()
            ->get('/v1/_authcheck', ['headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN]])
            ->assertStatus(200)
            ->assertJson()
            ->assertJsonMatches('tenant_slug', 'test-tenant')
            ->assertJsonMatches('tenant_name', 'Test Tenant')
        ;
    }

    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $this->browser()
            ->get('/v1/_authcheck')
            ->assertStatus(401)
            ->assertJsonMatches('message', 'Unauthorized.')
        ;
    }

    public function testUnknownTokenReturns401(): void
    {
        $this->browser()
            ->get('/v1/_authcheck', ['headers' => ['Authorization' => 'Bearer cw_unknown_token_value_xxxxxxx']])
            ->assertStatus(401)
        ;
    }

    public function testNonBearerSchemeReturns401(): void
    {
        $this->browser()
            ->get('/v1/_authcheck', ['headers' => ['Authorization' => 'Basic dXNlcjpwYXNz']])
            ->assertStatus(401)
        ;
    }

    public function testEmptyBearerReturns401(): void
    {
        $this->browser()
            ->get('/v1/_authcheck', ['headers' => ['Authorization' => 'Bearer ']])
            ->assertStatus(401)
        ;
    }

    public function testRawTokenWithoutBearerPrefixReturns401(): void
    {
        $this->browser()
            ->get('/v1/_authcheck', ['headers' => ['Authorization' => self::VALID_TOKEN]])
            ->assertStatus(401)
        ;
    }
}
