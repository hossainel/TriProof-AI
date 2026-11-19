<?php

use App\Exceptions\AiResponseException;
use App\Exceptions\AiUnavailableException;
use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\AuthenticateApi;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\OptionalApiAuth;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('triproof:anchor-merkle')->dailyAt('01:30');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.auth' => AuthenticateApi::class,
            'api.optional' => OptionalApiAuth::class,
            'role' => EnsureRole::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
            SecurityHeaders::class,
        ]);

        $middleware->api(append: [
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('validation_error', 'Please correct the submitted fields.', 422, $e->errors());
            }
        });

        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
            }

            return back()->withInput()->with('error', $e->getMessage());
        });

        $exceptions->render(function (AiUnavailableException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('ai_unavailable', $e->getMessage(), 503);
            }

            return back()->withInput()->with('error', $e->getMessage().' Configure AI in .env or enter the serial manually.');
        });

        $exceptions->render(function (AiResponseException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('ai_provider_error', $e->getMessage(), 502);
            }

            return back()->withInput()->with('error', $e->getMessage());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('unauthenticated', $e->getMessage() ?: 'Authentication is required.', 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('forbidden', $e->getMessage() ?: 'This action is forbidden.', 403);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('forbidden', $e->getMessage() ?: 'This action is forbidden.', 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('not_found', 'The requested resource was not found.', 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('rate_limited', 'Too many requests. Please retry later.', 429)
                    ->withHeaders($e->getHeaders());
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('not_found', 'The requested API endpoint was not found.', 404);
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('method_not_allowed', 'This HTTP method is not allowed for the endpoint.', 405);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                report($e);

                return ApiResponse::error('server_error', 'An unexpected server error occurred.', 500);
            }
        });
    })->create();
