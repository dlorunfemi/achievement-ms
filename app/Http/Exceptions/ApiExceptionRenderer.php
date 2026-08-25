<?php

namespace App\Http\Exceptions;

use App\Http\Responses\ErrorCode;
use App\Payments\Exceptions\MissingPayoutAccountException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every failure as the one error shape the application promises.
 *
 * This exists as a single renderer rather than as a branch in each controller so the
 * contract also covers the responses nobody writes by hand: a 405 from the router, a
 * 429 from the throttle middleware, an uncaught exception from a queue-adjacent code
 * path. Those are exactly the ones that would otherwise answer in a different shape.
 */
class ApiExceptionRenderer
{
    /**
     * Map a throwable onto the error contract, or return null to let Laravel handle it.
     */
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        // The welcome page is the one HTML surface in the application; leaving it to
        // Laravel keeps its error pages rendering as pages.
        if ($request->is('/')) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => ErrorCode::ValidationFailed->response(
                $e->getMessage(),
                ['errors' => $e->errors()],
                $e->status,
            ),

            // Raised by route model binding. Laravel's own message names the model
            // class, which is an internal detail leaking out of a public endpoint.
            $e instanceof ModelNotFoundException => ErrorCode::ResourceNotFound->response(
                $this->describeMissingModel($e),
            ),

            $e instanceof MissingPayoutAccountException => ErrorCode::PayoutAccountMissing->response(),
            $e instanceof AuthenticationException => ErrorCode::Unauthenticated->response(),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => ErrorCode::Forbidden->response(),
            $e instanceof ThrottleRequestsException => $this->fromHttpException($e, ErrorCode::TooManyRequests),
            $e instanceof MethodNotAllowedHttpException => $this->fromHttpException($e, ErrorCode::MethodNotAllowed),
            $e instanceof NotFoundHttpException => ErrorCode::ResourceNotFound
                ->response($this->describeNotFound($e))
                ->withHeaders($e->getHeaders()),
            $e instanceof HttpExceptionInterface => $this->fromHttpException($e, $this->codeForStatus($e->getStatusCode())),

            default => $this->fromUnexpected($e),
        };
    }

    /**
     * Answer an HTTP exception, keeping the headers that carry part of the meaning —
     * Retry-After on a 429, Allow on a 405.
     */
    private function fromHttpException(HttpExceptionInterface $e, ErrorCode $code): JsonResponse
    {
        $message = trim($e->getMessage());

        return $code->response(
            $message === '' ? null : $message,
            status: $e->getStatusCode(),
        )->withHeaders($e->getHeaders());
    }

    /**
     * Anything unplanned. The real message is withheld unless the application is in
     * debug mode: an exception string routinely carries a query, a path or a key.
     */
    private function fromUnexpected(Throwable $e): JsonResponse
    {
        if (! config('app.debug')) {
            return ErrorCode::ServerError->response();
        }

        return ErrorCode::ServerError->response(context: [
            'exception' => $e::class,
            'debug_message' => $e->getMessage(),
        ]);
    }

    /**
     * Laravel converts a binding failure into a NotFoundHttpException before any
     * render callback sees it, keeping the original as the previous exception. Its
     * message names the model class, so it is rebuilt here rather than echoed: the
     * caller of a public endpoint has no business learning our namespace.
     */
    private function describeNotFound(NotFoundHttpException $e): string
    {
        $previous = $e->getPrevious();

        return $previous instanceof ModelNotFoundException
            ? $this->describeMissingModel($previous)
            : ErrorCode::ResourceNotFound->message();
    }

    /**
     * "No user matches [42]." — the model, in words, without its namespace.
     *
     * @param  ModelNotFoundException<covariant \Illuminate\Database\Eloquent\Model>  $e
     */
    private function describeMissingModel(ModelNotFoundException $e): string
    {
        $model = $e->getModel();

        if ($model === null) {
            return ErrorCode::ResourceNotFound->message();
        }

        $noun = Str::of(class_basename($model))->headline()->lower()->toString();
        $ids = implode(', ', array_map(strval(...), $e->getIds()));

        return $ids === ''
            ? "No {$noun} matches that identifier."
            : "No {$noun} matches [{$ids}].";
    }

    /**
     * A status Laravel raised that has no dedicated case above.
     */
    private function codeForStatus(int $status): ErrorCode
    {
        return match ($status) {
            401 => ErrorCode::Unauthenticated,
            403 => ErrorCode::Forbidden,
            404 => ErrorCode::ResourceNotFound,
            405 => ErrorCode::MethodNotAllowed,
            422 => ErrorCode::ValidationFailed,
            429 => ErrorCode::TooManyRequests,
            503 => ErrorCode::ServiceUnavailable,
            default => ErrorCode::ServerError,
        };
    }
}
