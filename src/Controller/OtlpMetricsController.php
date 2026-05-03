<?php

declare(strict_types=1);

namespace App\Controller;

use App\Metrics\MetricsIngestService;
use App\Otlp\MetricsJsonDecoder;
use App\Otlp\MetricsProtobufDecoder;
use App\Otlp\OtlpRequestPipeline;
use App\Security\IngestUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class OtlpMetricsController
{
    public function __construct(
        private readonly OtlpRequestPipeline $pipeline,
        private readonly MetricsJsonDecoder $jsonDecoder,
        private readonly MetricsProtobufDecoder $protobufDecoder,
        private readonly MetricsIngestService $ingest,
    ) {
    }

    #[Route(path: '/v1/metrics', name: 'crashler_otlp_metrics', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] IngestUser $user): Response
    {
        return $this->pipeline->handle(
            $request,
            $user,
            $this->jsonDecoder,
            $this->protobufDecoder,
            $this->ingest,
        );
    }
}
