<?php

declare(strict_types=1);

namespace App\Controller;

use App\Logs\LogsIngestService;
use App\Otlp\ErrorResponse;
use App\Otlp\Exception\OtlpDecodeException;
use App\Otlp\Exception\OtlpPayloadTooLargeException;
use App\Otlp\GzipBodyDecoder;
use App\Otlp\LogsJsonDecoder;
use App\Security\IngestUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class OtlpLogsController
{
    public function __construct(
        private readonly LogsJsonDecoder $jsonDecoder,
        private readonly GzipBodyDecoder $gzipDecoder,
        private readonly LogsIngestService $ingest,
        private readonly int $maxBodyBytes,
        private readonly int $maxDecompressedBytes,
    ) {
    }

    #[Route(path: '/v1/logs', name: 'crashler_otlp_logs', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] IngestUser $user): Response
    {
        if ('application/json' !== $request->headers->get('Content-Type')) {
            return ErrorResponse::create(
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                'OTLP/HTTP-JSON requires Content-Type: application/json.',
            );
        }

        $body = $request->getContent();

        if (\strlen($body) > $this->maxBodyBytes) {
            return ErrorResponse::create(
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                \sprintf('Compressed request body exceeds the configured limit of %d bytes.', $this->maxBodyBytes),
            );
        }

        if ('gzip' === $request->headers->get('Content-Encoding')) {
            try {
                $body = $this->gzipDecoder->decode($body, $this->maxDecompressedBytes);
            } catch (OtlpPayloadTooLargeException $e) {
                return ErrorResponse::create(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $e->getMessage());
            } catch (OtlpDecodeException $e) {
                return ErrorResponse::create(Response::HTTP_BAD_REQUEST, $e->getMessage());
            }
        } elseif (\strlen($body) > $this->maxDecompressedBytes) {
            return ErrorResponse::create(
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                \sprintf('Request body exceeds the configured limit of %d bytes.', $this->maxDecompressedBytes),
            );
        }

        try {
            $dto = $this->jsonDecoder->decode($body);
        } catch (OtlpDecodeException $e) {
            return ErrorResponse::create(Response::HTTP_BAD_REQUEST, $e->getMessage());
        }

        try {
            $this->ingest->write($dto, $user->tenant);
        } catch (\Throwable $e) {
            return ErrorResponse::create(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Internal error while persisting logs.',
            );
        }

        return new JsonResponse(new \stdClass(), Response::HTTP_OK);
    }

}
