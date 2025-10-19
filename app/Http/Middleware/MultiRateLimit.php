<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MultiRateLimit
{
    protected $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request with multiple rate limits.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  mixed  ...$params  Laravel splits by comma, so we receive: 20, 10:400, 60:10000, 86400
     *                              We need to reconstruct this into pairs: [20,10], [400,60], [10000,86400]
     */
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        $key = $this->resolveRequestSignature($request);

        // Reconstruct the limits from the split parameters
        // Laravel splits "20,10:400,60:10000,86400" into ["20", "10:400", "60:10000", "86400"]
        $limits = [];
        $fullString = implode(',', $params);
        $pairs = explode(':', $fullString);

        foreach ($pairs as $pair) {
            $parts = explode(',', $pair);
            if (count($parts) === 2) {
                $limits[] = $pair;
            }
        }

        foreach ($limits as $limit) {
            [$maxAttempts, $decaySeconds] = explode(',', $limit);
            $maxAttempts = (int) $maxAttempts;
            $decaySeconds = (int) $decaySeconds;

            $rateLimitKey = $key . ':' . $limit;

            if ($this->limiter->tooManyAttempts($rateLimitKey, $maxAttempts)) {
                return $this->buildResponse($rateLimitKey, $maxAttempts, $decaySeconds);
            }

            $this->limiter->hit($rateLimitKey, $decaySeconds);
        }

        $response = $next($request);

        return $this->addHeaders($response, $key, $limits);
    }

    /**
     * Resolve the request signature.
     */
    protected function resolveRequestSignature(Request $request): string
    {
        return sha1(
            $request->method() .
            '|' . $request->server('SERVER_NAME') .
            '|' . $request->path() .
            '|' . $request->ip()
        );
    }

    /**
     * Create a 'too many attempts' response.
     */
    protected function buildResponse(string $key, int $maxAttempts, int $decaySeconds): Response
    {
        $retryAfter = $this->limiter->availableIn($key);

        return response()->json([
            'message' => 'Too Many Requests',
            'retry_after' => $retryAfter,
        ], 429, [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
            'Retry-After' => $retryAfter,
            'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->timestamp,
        ]);
    }

    /**
     * Add rate limit headers to the response.
     */
    protected function addHeaders(Response $response, string $key, array $limits): Response
    {
        // Add headers for the most restrictive limit
        $mostRestrictive = $this->getMostRestrictiveLimit($key, $limits);

        if ($mostRestrictive) {
            [$maxAttempts, $decaySeconds] = explode(',', $mostRestrictive);
            $maxAttempts = (int) $maxAttempts;
            $decaySeconds = (int) $decaySeconds;
            $rateLimitKey = $key . ':' . $mostRestrictive;

            $response->headers->add([
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => max(0, $this->limiter->remaining($rateLimitKey, $maxAttempts)),
                'X-RateLimit-Reset' => now()->addSeconds($decaySeconds)->timestamp,
            ]);
        }

        return $response;
    }

    /**
     * Get the most restrictive limit based on remaining attempts.
     */
    protected function getMostRestrictiveLimit(string $key, array $limits): ?string
    {
        $minRemaining = PHP_INT_MAX;
        $mostRestrictive = null;

        foreach ($limits as $limit) {
            [$maxAttempts, $decaySeconds] = explode(',', $limit);
            $maxAttempts = (int) $maxAttempts;
            $decaySeconds = (int) $decaySeconds;
            $rateLimitKey = $key . ':' . $limit;
            $remaining = $this->limiter->remaining($rateLimitKey, $maxAttempts);

            if ($remaining < $minRemaining) {
                $minRemaining = $remaining;
                $mostRestrictive = $limit;
            }
        }

        return $mostRestrictive;
    }
}
