<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Exceptions\ErrorResponse;
use App\Llm\Exceptions\LlmException;
use App\Llm\Exceptions\LlmRefused;
use App\Llm\Exceptions\LlmTruncated;
use App\Llm\Exceptions\LlmUnavailable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every API failure is rendered here, once, as {error:{code,message,details}}.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(fn (ApiException $e) => ErrorResponse::make($e->errorCode(), $e->getMessage(), $e->status(), $e->details()));

        // 503 when Claude is unreachable (retrying later may work); 502 when it answered but unusably.
        $exceptions->render(function (LlmException $e) {
            [$code, $status] = match (true) {
                $e instanceof LlmUnavailable => ['llm_unavailable', 503],
                $e instanceof LlmTruncated => ['llm_truncated', 502],
                $e instanceof LlmRefused => ['llm_refused', 502],
                default => ['llm_bad_response', 502],
            };

            return ErrorResponse::make($code, $e->getMessage(), $status);
        });

        $exceptions->render(fn (ModelNotFoundException $e) => ErrorResponse::make(
            'not_found',
            class_basename($e->getModel()).' not found.',
            404,
            ['ids' => $e->getIds()],
        ));

        $exceptions->render(fn (ValidationException $e) => ErrorResponse::make(
            'validation_failed',
            $e->getMessage(),
            $e->status,
            ['errors' => $e->errors()],
        ));

        $exceptions->render(function (HttpExceptionInterface $e) {
            $status = $e->getStatusCode();
            $code = match ($status) {
                404 => 'not_found',
                405 => 'method_not_allowed',
                429 => 'too_many_requests',
                default => 'http_error',
            };

            return ErrorResponse::make($code, $e->getMessage() !== '' ? $e->getMessage() : $code, $status, [], $e->getHeaders());
        });

        // Unexpected failures: keep internals out of the body unless debugging.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || config('app.debug')) {
                return null;
            }

            return ErrorResponse::make('server_error', 'Unexpected server error.', 500);
        });
    })->create();
