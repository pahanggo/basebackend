<?php

namespace Gis\Http;

use Gis\Commands\CommandFailed;
use Illuminate\Http\JsonResponse;

/**
 * RFC 7807 problem documents.
 *
 * `code` is the stable member clients branch on and may not change; `title` and
 * `detail` are for humans and may (specification section 7). That split is the
 * whole reason for the format here — a client that branches on a message string
 * breaks the first time someone improves the wording.
 */
class ProblemResponse
{
    public const CONTENT_TYPE = 'application/problem+json';

    public const BASE = 'https://gis.app/problems/';

    public static function fromCommandFailure(CommandFailed $failure): JsonResponse
    {
        return self::make(
            $failure->errorCode,
            $failure->status,
            $failure->title,
            $failure->getMessage(),
            $failure->extra,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function make(string $code, int $status, string $title, string $detail = '', array $extra = []): JsonResponse
    {
        return new JsonResponse([
            'type' => self::BASE.str_replace('_', '-', $code),
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            ...$extra,
        ], $status, ['Content-Type' => self::CONTENT_TYPE]);
    }
}
