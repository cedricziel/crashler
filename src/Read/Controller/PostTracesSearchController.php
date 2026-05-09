<?php

declare(strict_types=1);

namespace App\Read\Controller;

use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\PredicateTreeCompiler;
use App\Read\Http\PostSearchRequestParser;
use App\Read\Http\PostSearchResponseShaper;
use App\Read\State\TracesStateProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/traces/search` — predicate-tree search over the traces partition.
 *
 * Returns the flat span collection (one row per span). The OTLP-shaped
 * `ResourceSpans` tree is reserved for the by-ID `GET /v1/traces/{traceId}`
 * operation; POST search is for collection-style filtering.
 */
final readonly class PostTracesSearchController extends PostSearchController
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
        private TracesStateProvider $rowMapper,
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
                'name',
                'kind_text',
                'status_text',
                'http_response_status_code',
                'trace_id_hex',
                'parent_span_id_hex',
                'start_time_unix_nano',
                'end_time_unix_nano',
            ],
            allowsBodyLeaf: false,
            maxAttributeFilters: $maxAttributeFilters,
            attributesColumn: 'attributes_json',
            aliases: [
                'service' => 'resource_service_name',
                'environment' => 'resource_deployment_environment',
                'host' => 'resource_host_name',
                'kind' => 'kind_text',
                'statusCode' => 'status_text',
                'httpResponseStatusCode' => 'http_response_status_code',
                'traceId' => 'trace_id_hex',
                'parentSpanId' => 'parent_span_id_hex',
                'startTimeUnixNano' => 'start_time_unix_nano',
                'endTimeUnixNano' => 'end_time_unix_nano',
            ],
        );
    }

    #[Route(path: '/v1/traces/search', name: 'crashler_post_traces_search', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        return $this->handle($request);
    }

    protected function signalSubdir(): string
    {
        return 'traces';
    }

    protected function timeColumn(): string
    {
        return 'start_time_unix_nano';
    }

    protected function compiler(): PredicateTreeCompiler
    {
        return $this->compilerInstance;
    }

    protected function shortName(): string
    {
        return 'Trace';
    }

    protected function collectionPath(): string
    {
        return '/v1/traces';
    }

    protected function rowToResource(array $row): object
    {
        return $this->rowMapper->rowToResource($row);
    }
}
