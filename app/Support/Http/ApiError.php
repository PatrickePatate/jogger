<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Support\Exceptions\JoggerException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders {"error": {"code", "message", "details", "request_id"}} for api/*, agent/* and webhooks/*.
 */
final class ApiError
{
    public static function handles(Request $request): bool
    {
        return $request->is('api/*', 'agent/*', 'webhooks/*');
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $message, $details, $headers] = self::describe($e);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], $status, $headers);
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>, 4: array<string, string>}
     */
    private static function describe(Throwable $e): array
    {
        return match (true) {
            $e instanceof JoggerException => [$e->httpStatus(), $e->errorCode(), $e->getMessage(), $e->details(), $e->headers()],
            $e instanceof ValidationException => [422, 'VALIDATION_ERROR', 'The given data was invalid.', ['errors' => $e->errors()], []],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Authentication is required.', [], []],
            $e instanceof AuthorizationException => [403, 'FORBIDDEN', 'You are not allowed to do this.', [], []],
            $e instanceof ModelNotFoundException => [404, 'NOT_FOUND', 'Resource not found.', [], []],
            $e instanceof HttpExceptionInterface => self::fromHttp($e),
            default => [
                500,
                'INTERNAL_ERROR',
                config('app.debug') ? $e->getMessage() : 'Something went wrong.',
                [],
                [],
            ],
        };
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>, 4: array<string, string>}
     */
    private static function fromHttp(HttpExceptionInterface $e): array
    {
        $status = $e->getStatusCode();
        $code = match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            413 => 'PAYLOAD_TOO_LARGE',
            422 => 'UNPROCESSABLE',
            429 => 'TOO_MANY_REQUESTS',
            default => $status >= 500 ? 'INTERNAL_ERROR' : 'HTTP_ERROR',
        };
        $message = $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$status] ?? 'Error');
        /** @var array<string, string> $headers */
        $headers = array_map(static fn ($v) => is_array($v) ? (string) ($v[0] ?? '') : (string) $v, $e->getHeaders());

        return [$status, $code, $message, [], $headers];
    }
}
