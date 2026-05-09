<?php

declare(strict_types=1);

namespace App\Read\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\Predicates\ColumnEquals;
use App\Read\Compute\Predicates\ColumnGreaterEqual;
use App\Read\Compute\Predicates\JsonStringContains;
use App\Read\Compute\Predicates\Predicate;
use App\Read\Criteria\TimeWindow;
use App\Read\Cursor\Cursor;
use App\Read\Cursor\InvalidCursorException;
use App\Read\Resource\Log;
use App\Security\IngestUser;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * State provider for the Log Resource's GetCollection operation.
 *
 * Reads URL parameters from the request, compiles them into typed
 * predicates, and dispatches to the streaming ParquetScanner. Returns the
 * matched rows as Log DTOs together with pagination metadata.
 *
 * Tenant scope comes from the authenticated IngestUser; the underlying
 * file glob is bounded to that tenant's directory tree exclusively.
 */
final readonly class LogsStateProvider implements ProviderInterface
{
    public function __construct(
        private ParquetScanner $scanner,
        private PartitionPruner $pruner,
        private Security $security,
        private ClockInterface $clock,
        private int $maxTimeWindowDays,
        private int $maxPageSize,
        private string $cursorSecret,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $request = $context['request'] ?? null;
        if (null === $request) {
            return [];
        }

        $user = $this->security->getUser();
        if (!$user instanceof IngestUser) {
            return [];
        }
        $tenantSlug = $user->tenant->slug;

        $query = $request->query->all();
        $cursorValue = $query['cursor'] ?? null;

        if (null !== $cursorValue && '' !== $cursorValue) {
            try {
                $cursor = Cursor::decode($cursorValue, $this->cursorSecret, $tenantSlug, $this->maxTimeWindowDays);
            } catch (InvalidCursorException $e) {
                throw new BadRequestException($e->getMessage(), previous: $e);
            }
            $criteria = $cursor->criteria;
            $resumeFrom = $cursor->position;
        } else {
            $criteria = $query;
            $resumeFrom = null;
        }

        try {
            $window = TimeWindow::parse(
                ['since' => $criteria['since'] ?? null, 'until' => $criteria['until'] ?? null],
                $this->clock,
                $this->maxTimeWindowDays,
            );
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            throw new BadRequestException($e->getMessage(), previous: $e);
        }

        $limit = isset($criteria['limit']) ? (int) $criteria['limit'] : 100;
        if ($limit < 1) {
            throw new BadRequestException('`limit` must be a positive integer.');
        }
        if ($limit > $this->maxPageSize) {
            throw new BadRequestException(\sprintf('`limit` exceeds max_page_size (%d).', $this->maxPageSize));
        }

        $predicates = $this->compilePredicates($criteria, $window);
        $globs = $this->pruner->globsFor($tenantSlug, 'logs', $window);

        $result = $this->scanner->scan($globs, $predicates, $limit, $resumeFrom);

        return array_map(self::rowToResource(...), $result->rows);
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return list<Predicate>
     */
    private function compilePredicates(array $criteria, TimeWindow $window): array
    {
        $predicates = [];

        // The time-window predicate is always present.
        $predicates[] = new \App\Read\Compute\Predicates\ColumnInRange('time_unix_nano', $window->sinceUnixNano, $window->untilUnixNano);

        if (isset($criteria['service']) && \is_string($criteria['service']) && '' !== $criteria['service']) {
            $predicates[] = new ColumnEquals('resource_service_name', $criteria['service']);
        }
        if (isset($criteria['environment']) && \is_string($criteria['environment']) && '' !== $criteria['environment']) {
            $predicates[] = new ColumnEquals('resource_deployment_environment', $criteria['environment']);
        }
        if (isset($criteria['host']) && \is_string($criteria['host']) && '' !== $criteria['host']) {
            $predicates[] = new ColumnEquals('resource_host_name', $criteria['host']);
        }
        if (isset($criteria['severityNumber']) && '' !== $criteria['severityNumber']) {
            $predicates[] = new ColumnEquals('severity_number', (int) $criteria['severityNumber']);
        }
        if (isset($criteria['severityNumberMin']) && '' !== $criteria['severityNumberMin']) {
            $predicates[] = new ColumnGreaterEqual('severity_number', (int) $criteria['severityNumberMin']);
        }
        if (isset($criteria['severityText']) && \is_string($criteria['severityText']) && '' !== $criteria['severityText']) {
            $predicates[] = new ColumnEquals('severity_text', $criteria['severityText']);
        }
        if (isset($criteria['traceId']) && \is_string($criteria['traceId']) && '' !== $criteria['traceId']) {
            $hex = $criteria['traceId'];
            if (32 !== \strlen($hex) || 1 !== preg_match('/^[0-9a-f]{32}$/', $hex)) {
                throw new BadRequestException('`traceId` must be exactly 32 lowercase hex characters.');
            }
            $predicates[] = new ColumnEquals('trace_id_hex', $hex);
        }
        if (isset($criteria['spanId']) && \is_string($criteria['spanId']) && '' !== $criteria['spanId']) {
            $hex = $criteria['spanId'];
            if (16 !== \strlen($hex) || 1 !== preg_match('/^[0-9a-f]{16}$/', $hex)) {
                throw new BadRequestException('`spanId` must be exactly 16 lowercase hex characters.');
            }
            $predicates[] = new ColumnEquals('span_id_hex', $hex);
        }
        if (isset($criteria['eventName']) && \is_string($criteria['eventName']) && '' !== $criteria['eventName']) {
            $predicates[] = new ColumnEquals('event_name', $criteria['eventName']);
        }
        if (isset($criteria['bodyContains']) && \is_string($criteria['bodyContains']) && '' !== $criteria['bodyContains']) {
            $predicates[] = new JsonStringContains('body_json', $criteria['bodyContains']);
        }

        return $predicates;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowToResource(array $row): Log
    {
        return new Log(
            timeUnixNano: isset($row['time_unix_nano']) ? (string) $row['time_unix_nano'] : '',
            severityNumber: isset($row['severity_number']) ? (int) $row['severity_number'] : null,
            severityText: $row['severity_text'] ?? null,
            bodyJson: $row['body_json'] ?? null,
            attributesJson: $row['attributes_json'] ?? '[]',
            resourceServiceName: $row['resource_service_name'] ?? null,
            resourceDeploymentEnvironment: $row['resource_deployment_environment'] ?? null,
            resourceHostName: $row['resource_host_name'] ?? null,
            scopeName: $row['scope_name'] ?? null,
            scopeVersion: $row['scope_version'] ?? null,
            scopeSchemaUrl: $row['scope_schema_url'] ?? null,
            traceIdHex: isset($row['trace_id_hex']) ? self::bytesToHex($row['trace_id_hex']) : null,
            spanIdHex: isset($row['span_id_hex']) ? self::bytesToHex($row['span_id_hex']) : null,
            eventName: $row['event_name'] ?? null,
            resourceAttributesJson: $row['resource_attributes_json'] ?? '[]',
            schemaId: $row['_schema_id'] ?? 'logs/v1',
            schemaVersion: isset($row['_schema_version']) ? (int) $row['_schema_version'] : 1,
        );
    }

    /**
     * On-disk trace_id_hex / span_id_hex columns may be stored as raw
     * bytes (writer convention) or pre-hexed (test fixtures).
     */
    private static function bytesToHex(?string $bytes): ?string
    {
        if (null === $bytes || '' === $bytes) {
            return null;
        }
        if (1 === preg_match('/^[0-9a-f]+$/', $bytes)) {
            return $bytes;
        }

        return bin2hex($bytes);
    }
}

