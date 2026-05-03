<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Contract\IngestsSignal;
use App\Otlp\Contract\SignalDecoder;
use App\Otlp\Exception\OtlpDecodeException;
use App\Otlp\Exception\OtlpPayloadTooLargeException;
use App\Security\IngestUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signal-generic ingest path. Owns Content-Type dispatch, gzip decoding,
 * size limits, error mapping, and the OTLP-shaped 200 response so per-signal
 * controllers stay paper-thin (resolve user → call pipeline with their three
 * collaborators).
 */
final class OtlpRequestPipeline
{
    private const string CT_JSON = 'application/json';
    private const string CT_PROTOBUF = 'application/x-protobuf';

    public function __construct(
        private readonly GzipBodyDecoder $gzipDecoder,
        private readonly int $maxBodyBytes,
        private readonly int $maxDecompressedBytes,
    ) {
    }

    public function handle(
        Request $request,
        IngestUser $user,
        SignalDecoder $jsonDecoder,
        SignalDecoder $protobufDecoder,
        IngestsSignal $ingestService,
    ): Response {
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
                ? $jsonDecoder->decode($body)
                : $protobufDecoder->decode($body);
        } catch (OtlpDecodeException $e) {
            return ErrorResponse::create(Response::HTTP_BAD_REQUEST, $e->getMessage());
        }

        try {
            $ingestService->write($dto, $user->tenant);
        } catch (\Throwable) {
            return ErrorResponse::create(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Internal error while persisting signal data.',
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
