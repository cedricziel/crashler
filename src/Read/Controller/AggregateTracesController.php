<?php

declare(strict_types=1);

namespace App\Read\Controller;

use App\Read\Compute\Predicates\ColumnEquals;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AggregateTracesController extends AggregateController
{
    #[Route(path: '/v1/traces/aggregate', name: 'crashler_aggregate_traces', methods: ['GET'])]
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

    protected function allowedValueColumns(): array
    {
        return ['http_response_status_code', 'duration_nano'];
    }

    protected function allowedGroupByColumns(): array
    {
        return ['resource_service_name', 'resource_deployment_environment', 'resource_host_name', 'kind_text', 'status_text', 'name'];
    }

    protected function valueColumnAliases(): array
    {
        return [
            'httpResponseStatusCode' => 'http_response_status_code',
            'durationNano' => 'duration_nano',
        ];
    }

    protected function groupByAliases(): array
    {
        return [
            'service' => 'resource_service_name',
            'environment' => 'resource_deployment_environment',
            'host' => 'resource_host_name',
            'kind' => 'kind_text',
            'statusCode' => 'status_text',
        ];
    }

    protected function buildSignalPredicates(Request $request): iterable
    {
        foreach (['service' => 'resource_service_name', 'environment' => 'resource_deployment_environment', 'host' => 'resource_host_name'] as $param => $column) {
            $value = $request->query->get($param);
            if (\is_string($value) && '' !== $value) {
                yield new ColumnEquals($column, $value);
            }
        }
    }
}
