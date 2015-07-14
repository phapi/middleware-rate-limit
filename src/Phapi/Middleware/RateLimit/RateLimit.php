<?php

namespace Phapi\Middleware\RateLimit;


use Phapi\Cache\NullCache;
use Phapi\Contract\Cache\Cache;
use Phapi\Contract\Di\Container;
use Phapi\Contract\Middleware\Middleware;
use Phapi\Exception\InternalServerError;
use Phapi\Exception\TooManyRequests;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * RateLimit Middleware
 *
 * @category Phapi
 * @package  Phapi\Middleware\RateLimit
 * @author   Peter Ahinko <peter@ahinko.se>
 * @license  MIT (http://opensource.org/licenses/MIT)
 * @link     https://github.com/phapi/middleware-rate-limit
 */
class RateLimit implements Middleware
{

    /**
     * What header should we use that includes an identifier?
     *
     * @var string
     */
    protected $identifierHeader;

    /**
     * Unique identifier
     *
     * @var string
     */
    protected $identifier;

    /**
     * The request attribute name that contains the matched
     * endpoint.
     *
     * @var string
     */
    protected $requestAttribName;

    /**
     * Bucket configuration
     *
     * @var array
     */
    protected $buckets;

    /**
     * Matched bucket by requested resource
     *
     * @var \Phapi\Middleware\RateLimit\Bucket
     */
    protected $bucket;

    /**
     * DI container
     *
     * @var Container
     */
    protected $container;

    /**
     * Cache
     *
     * @var Cache
     */
    protected $cache;

    /**
     * Set the dependency injection container
     *
     * @param Container $container
     */
    public function setContainer(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Create the middleware
     *
     * @param string $identifierHeader Header identifier. Example: Client-ID
     * @param array $buckets
     * @param Cache $cache Cache instance
     * @param string $requestAttribName The name that the router uses to store the matched
     *                                  endpoint as an attribute on the request object
     */
    public function __construct(
        $identifierHeader,
        array $buckets,
        Cache $cache,
        $requestAttribName = 'routeEndpoint'
    ) {
        // Settings
        $this->identifierHeader = $identifierHeader;
        $this->cache = $cache;
        $this->requestAttribName = $requestAttribName;
        $this->buckets = $buckets;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param callable|null $next
     * @return ResponseInterface
     * @throws InternalServerError
     * @throws TooManyRequests
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next = null)
    {
        // Get resource
        $endpoint = $request->getAttribute($this->requestAttribName, null);

        if (is_null($endpoint)) {
            throw new InternalServerError(
                'Rate Limit could not find a matched endpoint from the router. '.
                'Make sure the requestAttribName is correct.'
            );
        }

        // Check what bucket to use
        if (array_key_exists($endpoint, $this->buckets)) {
            $this->bucket = $this->buckets[$endpoint];
        } elseif (array_key_exists('default', $this->buckets)) {
            $this->bucket = $this->buckets['default'];
        } else {
            // No bucket configured
            throw new InternalServerError('Rate Limit needs at least one (default) bucket to work.');
        }

        // Check for cache
        if ($this->cache instanceof NullCache) {
            // Throw error since we don't have anywhere to save the data
            throw new InternalServerError('Rate Limit needs a cache to work.');
        }

        // Get identifier and check it it's null
        if (null === $this->identifier = $this->getIdentifier($request)) {
            // No identifier found
            $this->container['log']->warning(
                'Request (ID: '. $response->getHeaderLine('Uuid') .') made but without '.
                'the '. $this->identifierHeader .' header. Please note that the request was executed as normal.'
            );

            // Call next middleware if one is set
            return $next($request, $response, $next);
        }

        // Sanitize the endpoint name(space) a little bit
        $endpoint = preg_replace("/[^a-zA-Z0-9]+/", "", $endpoint);

        // Get saved data from cache
        $this->bucket->remainingTokens = $this->cache->get('rateLimit'. $endpoint . $this->identifier);
        $this->bucket->updated = $this->cache->get('rateLimitUpdated'. $endpoint . $this->identifier);

        // Refill tokens
        $this->refillTokens();

        // Check if there are enough tokens left
        $this->checkTokens();

        // Set headers
        $response = $this->setHeaders($response);

        // Save to cache
        $this->cache->set('rateLimit'. $endpoint . $this->identifier, $this->bucket->remainingTokens);
        $this->cache->set('rateLimitUpdated'. $endpoint . $this->identifier, $this->bucket->updated);

        // Call next middleware if one is set
        return $next($request, $response, $next);
    }

    /**
     * Refill tokens based on strategy
     */
    protected function refillTokens()
    {
        // Calculate how many seconds it is since cache was updated
        $seconds = time() - $this->bucket->updated;

        // Check if we should add tokens continuously
        if ($this->bucket->newTokenContinuous) {
            // Calculate how many tokens to add for each second
            $rate = $this->bucket->newTokens / $this->bucket->newTokensWindow;

            // Add tokens based on seconds since last cache update
            $this->bucket->remainingTokens += round($rate * $seconds);

            // Update when refill was made
            $this->bucket->updated = time();
        } else {
            // Calculate how many periods has passed since cache update
            $periods = floor($seconds / $this->bucket->newTokensWindow);

            // Add tokens based on periods
            $this->bucket->remainingTokens += $this->bucket->newTokens * $periods;

            // Check if more than one period has passed since last refill
            if ($periods >= 1) {
                $this->bucket->updated = time();
            }
        }

        // Make sure remaining tokens never exceeds the max number of total tokens
        if ($this->bucket->remainingTokens > $this->bucket->totalTokens) {
            $this->bucket->remainingTokens = $this->bucket->totalTokens;
        }
    }

    /**
     * Set the headers to the response
     *
     * @param ResponseInterface $response
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function setHeaders(ResponseInterface $response)
    {
        // Set response headers
        $response = $response->withHeader('X-Rate-Limit-Limit', (string) $this->bucket->totalTokens);
        $response = $response->withHeader('X-Rate-Limit-Remaining', (string) $this->bucket->remainingTokens);

        // Headers about how many new tokens are added over time differs depending on if
        // continuous adding is active
        if ($this->bucket->newTokenContinuous) {
            $response = $response->withHeader('X-Rate-Limit-Window', (string) 1);
            $response = $response->withHeader(
                'X-Rate-Limit-New',
                (string) round($this->bucket->newTokens / $this->bucket->newTokensWindow)
            );
        } else {
            $response = $response->withHeader('X-Rate-Limit-Window', (string) $this->bucket->newTokensWindow);
            $response = $response->withHeader(
                'X-Rate-Limit-New',
                (string) $this->bucket->newTokens
            );
        }

        return $response;
    }

    /**
     * Check if there are enough tokens left in the bucket
     *
     * @throws TooManyRequests
     */
    protected function checkTokens()
    {
        // enough tokens left?
        if ($this->bucket->remainingTokens > 0) {
            // yes, lets remove one
            $this->bucket->remainingTokens--;
        } else {
            // no tokens left
            if ($this->bucket->newTokenContinuous) {
                throw new TooManyRequests(
                    'You\'ve run out of request tokens. You receive '.
                    round($this->bucket->newTokens / $this->bucket->newTokensWindow) .
                    ' new tokens every second.'
                );
            } else {
                throw new TooManyRequests(
                    'You\'ve run out of request tokens. You receive '.
                    $this->bucket->newTokens .' new tokens every '.
                    $this->bucket->newTokensWindow .' seconds.'
                );
            }
        }
    }

    /**
     * Get the unique identifier. Uses the provided header name to look
     * for a unique header.
     *
     * IMPORTANT: Extend this class and implement your own getIdentifier function if you want to.
     *
     * @param ServerRequestInterface $request
     * @return null|string
     */
    public function getIdentifier(ServerRequestInterface $request)
    {
        if ($this->identifier === null) {
            if ($request->hasHeader($this->identifierHeader)) {
                $this->identifier = $request->getHeaderLine($this->identifierHeader);
            }
        }
        return $this->identifier;
    }
}
