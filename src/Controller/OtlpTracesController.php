<?php

declare(strict_types=1);

namespace App\Controller;

use App\Otlp\OtlpRequestPipeline;
use App\Otlp\TracesJsonDecoder;
use App\Otlp\TracesProtobufDecoder;
use App\Security\IngestUser;
use App\Traces\TracesIngestService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class OtlpTracesController
{
    public function __construct(
        private readonly OtlpRequestPipeline $pipeline,
        private readonly TracesJsonDecoder $jsonDecoder,
        private readonly TracesProtobufDecoder $protobufDecoder,
        private readonly TracesIngestService $ingest,
    ) {
    }

    #[Route(path: '/v1/traces', name: 'crashler_otlp_traces', methods: ['POST'])]
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
