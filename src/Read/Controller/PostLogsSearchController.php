<?php

declare(strict_types=1);

namespace App\Read\Controller;

use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\PredicateTreeCompiler;
use App\Read\Http\PostSearchRequestParser;
use App\Read\Http\PostSearchResponseShaper;
use App\Read\State\LogsStateProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/logs/search` — predicate-tree search over the logs partition.
 *
 * Reuses the existing scanner, partition pruner, and {@see LogsStateProvider}
 * row-mapping. Adds: body parsing, predicate-tree compilation, content-
 * negotiated response shaping, criteria-digest cursor binding.
 */
final readonly class PostLogsSearchController extends PostSearchController
{
    private PredicateTreeCompiler $compilerInstance;

    public function __construct(
        ParquetScanner $scanner,
        PartitionPruner $pruner,
        Security $security,
        ClockInterface $clock,
        PostSearchRequestParser $parser,
        PostSearchResponseShaper $shaper,
        int $maxTimeWindowDays,
        int $maxPageSize,
        string $cursorSecret,
        int $maxAttributeFilters,
        private LogsStateProvider $rowMapper,
    ) {
        parent::__construct(
            $scanner, $pruner, $security, $clock, $parser, $shaper,
            $maxTimeWindowDays, $maxPageSize, $cursorSecret,
        );

        $this->compilerInstance = new PredicateTreeCompiler(
            allowedColumns: [
                'resource_service_name',
                'resource_deployment_environment',
                'resource_host_name',
                'severity_number',
                'severity_text',
                'trace_id_hex',
                'span_id_hex',
                'event_name',
                'time_unix_nano',
            ],
            allowsBodyLeaf: true,
            maxAttributeFilters: $maxAttributeFilters,
            bodyColumn: 'body_json',
            attributesColumn: 'attributes_json',
            aliases: [
                'service' => 'resource_service_name',
                'environment' => 'resource_deployment_environment',
                'host' => 'resource_host_name',
                'severityNumber' => 'severity_number',
                'severityText' => 'severity_text',
                'traceId' => 'trace_id_hex',
                'spanId' => 'span_id_hex',
                'eventName' => 'event_name',
                'timeUnixNano' => 'time_unix_nano',
            ],
        );
    }

    #[Route(path: '/v1/logs/search', name: 'crashler_post_logs_search', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        return $this->handle($request);
    }

    protected function signalSubdir(): string
    {
        return 'logs';
    }

    protected function compiler(): PredicateTreeCompiler
    {
        return $this->compilerInstance;
    }

    protected function shortName(): string
    {
        return 'Log';
    }

    protected function collectionPath(): string
    {
        return '/v1/logs';
    }

    protected function rowToResource(array $row): object
    {
        return $this->rowMapper->rowToResource($row);
    }
}
