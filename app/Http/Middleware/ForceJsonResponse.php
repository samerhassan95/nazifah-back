<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Force Accept header to application/json for all requests
        $request->headers->set('Accept', 'application/json');

        // Don't override Content-Type if it's already set (e.g., multipart/form-data for file uploads)
        // Only set to application/json if no Content-Type is present and request has body
        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            if (! $request->headers->has('Content-Type') && $request->getContent()) {
                // Only set if content looks like JSON
                $content = $request->getContent();
                if ($content && (str_starts_with(trim($content), '{') || str_starts_with(trim($content), '['))) {
                    $request->headers->set('Content-Type', 'application/json');
                }
            }
        }

        return $next($request);
    }
}
