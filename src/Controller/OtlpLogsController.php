<?php

declare(strict_types=1);

namespace App\Controller;

use App\Logs\LogsIngestService;
use App\Otlp\LogsJsonDecoder;
use App\Otlp\LogsProtobufDecoder;
use App\Otlp\OtlpRequestPipeline;
use App\Security\IngestUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class OtlpLogsController
{
    public function __construct(
        private readonly OtlpRequestPipeline $pipeline,
        private readonly LogsJsonDecoder $jsonDecoder,
        private readonly LogsProtobufDecoder $protobufDecoder,
        private readonly LogsIngestService $ingest,
    ) {
    }

    #[Route(path: '/v1/logs', name: 'crashler_otlp_logs', methods: ['POST'])]
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
