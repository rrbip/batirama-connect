<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that forces JSON responses for API routes.
 * This ensures that even exceptions are returned as JSON, not HTML.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        // Force Accept header to application/json
        $request->headers->set('Accept', 'application/json');

        try {
            $response = $next($request);

            // If response is not JSON and status is error, convert to JSON
            if (!$response instanceof JsonResponse && $response->getStatusCode() >= 400) {
                $content = $response->getContent();

                // Check if response is HTML (error page)
                if (str_contains($content, '<!DOCTYPE') || str_contains($content, '<html')) {
                    return response()->json([
                        'error' => 'server_error',
                        'message' => 'Une erreur serveur est survenue',
                        'status' => $response->getStatusCode(),
                    ], $response->getStatusCode());
                }
            }

            return $response;

        } catch (\Throwable $e) {
            // Catch any exception and return as JSON
            $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

            return response()->json([
                'error' => 'exception',
                'message' => config('app.debug') ? $e->getMessage() : 'Une erreur est survenue',
                'exception' => config('app.debug') ? get_class($e) : null,
                'file' => config('app.debug') ? $e->getFile() . ':' . $e->getLine() : null,
            ], $statusCode);
        }
    }
}
