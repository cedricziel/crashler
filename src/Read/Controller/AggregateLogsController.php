<?php

declare(strict_types=1);

namespace App\Read\Controller;

use App\Read\Compute\Predicates\ColumnEquals;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AggregateLogsController extends AggregateController
{
    #[Route(path: '/v1/logs/aggregate', name: 'crashler_aggregate_logs', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->handle($request);
    }

    protected function signalSubdir(): string
    {
        return 'logs';
    }

    protected function allowedValueColumns(): array
    {
        return ['severity_number'];
    }

    protected function allowedGroupByColumns(): array
    {
        return ['resource_service_name', 'resource_deployment_environment', 'resource_host_name', 'severity_text', 'severity_number', 'event_name'];
    }

    protected function valueColumnAliases(): array
    {
        return ['severityNumber' => 'severity_number'];
    }

    protected function groupByAliases(): array
    {
        return [
            'service' => 'resource_service_name',
            'environment' => 'resource_deployment_environment',
            'host' => 'resource_host_name',
            'severityText' => 'severity_text',
            'severityNumber' => 'severity_number',
            'eventName' => 'event_name',
        ];
    }

    protected function buildSignalPredicates(Request $request): iterable
    {
        $service = $request->query->get('service');
        if (\is_string($service) && '' !== $service) {
            yield new ColumnEquals('resource_service_name', $service);
        }
        $environment = $request->query->get('environment');
        if (\is_string($environment) && '' !== $environment) {
            yield new ColumnEquals('resource_deployment_environment', $environment);
        }
        $host = $request->query->get('host');
        if (\is_string($host) && '' !== $host) {
            yield new ColumnEquals('resource_host_name', $host);
        }
    }
}
