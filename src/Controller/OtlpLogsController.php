<?php

declare(strict_types=1);

namespace App\Controller;

use App\Logs\LogsIngestService;
use App\Otlp\ErrorResponse;
use App\Otlp\Exception\OtlpDecodeException;
use App\Otlp\Exception\OtlpPayloadTooLargeException;
use App\Otlp\GzipBodyDecoder;
use App\Otlp\LogsJsonDecoder;
use App\Otlp\LogsProtobufDecoder;
use App\Security\IngestUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class OtlpLogsController
{
    private const string CT_JSON = 'application/json';
    private const string CT_PROTOBUF = 'application/x-protobuf';

    public function __construct(
        private readonly LogsJsonDecoder $jsonDecoder,
        private readonly LogsProtobufDecoder $protobufDecoder,
        private readonly GzipBodyDecoder $gzipDecoder,
        private readonly LogsIngestService $ingest,
        private readonly int $maxBodyBytes,
        private readonly int $maxDecompressedBytes,
    ) {
    }

    #[Route(path: '/v1/logs', name: 'crashler_otlp_logs', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] IngestUser $user): Response
    {
        $contentType = self::stripParameters((string) $request->headers->get('Content-Type', ''));
        if (self::CT_JSON !== $contentType && self::CT_PROTOBUF !== $contentType) {
            return ErrorResponse::create(
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                'OTLP/HTTP requires Content-Type: application/json or application/x-protobuf.',
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
            $dto = self::CT_JSON === $contentType
                ? $this->jsonDecoder->decode($body)
                : $this->protobufDecoder->decode($body);
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

    /**
     * 'application/json; charset=utf-8' → 'application/json'. Some OTLP
     * exporters append parameters; we ignore them but still match the type.
     */
    private static function stripParameters(string $contentType): string
    {
        $semi = strpos($contentType, ';');

        return strtolower(trim(false === $semi ? $contentType : substr($contentType, 0, $semi)));
    }
}
