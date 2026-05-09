<?php

declare(strict_types=1);

namespace App\Read\Controller;

use App\Read\Compute\ParquetScanner;
use App\Read\Compute\PartitionPruner;
use App\Read\Compute\PredicateTreeCompiler;
use App\Read\Http\PostSearchRequestParser;
use App\Read\Http\PostSearchResponseShaper;
use App\Read\State\MetricsStateProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/metrics/search` — predicate-tree search over the metrics
 * partition. Supports the `exemplarTraceId` sugar (compiles to
 * `JsonAttributeEquals('exemplars_json', 'traceId', <hex>)`) at the leaf
 * level, mirroring the GET endpoint's parameter.
 */
final readonly class PostMetricsSearchController extends PostSearchController
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
        private MetricsStateProvider $rowMapper,
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
                'metric_name',
                'metric_type',
                'aggregation_temporality_text',
                'time_unix_nano',
            ],
            allowsBodyLeaf: false,
            maxAttributeFilters: $maxAttributeFilters,
            attributesColumn: 'attributes_json',
            aliases: [
                'service' => 'resource_service_name',
                'environment' => 'resource_deployment_environment',
                'host' => 'resource_host_name',
                'metricName' => 'metric_name',
                'metricType' => 'metric_type',
                'aggregationTemporality' => 'aggregation_temporality_text',
                'timeUnixNano' => 'time_unix_nano',
            ],
        );
    }

    #[Route(path: '/v1/metrics/search', name: 'crashler_post_metrics_search', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        return $this->handle($request);
    }

    protected function signalSubdir(): string
    {
        return 'metrics';
    }

    protected function compiler(): PredicateTreeCompiler
    {
        return $this->compilerInstance;
    }

    protected function shortName(): string
    {
        return 'Metric';
    }

    protected function collectionPath(): string
    {
        return '/v1/metrics';
    }

    protected function rowToResource(array $row): object
    {
        return $this->rowMapper->rowToResource($row);
    }
}
