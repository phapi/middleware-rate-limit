<?php


namespace Phapi\Tests\Middleware;

use Phapi\Middleware\RateLimit\Bucket;
use Phapi\Middleware\RateLimit\RateLimit;
use \PHPUnit_Framework_TestCase as TestCase;

/**
 * @coversDefaultClass \Phapi\Middleware\RateLimit\RateLimit
 */
class RateLimitTest extends TestCase
{

    public function testMiddleware()
    {
        $rateLimitBuckets = array(
            'default' => new Bucket(),
            '\\Phapi\\Tests\\Page' => new Bucket(800, 60, 10, false),
        );

        $cache = \Mockery::mock(['\Phapi\Cache\Memcache', '\Phapi\Contract\Cache\Cache']);
        $cache->shouldReceive('get')->with('rateLimitPhapiTestsPagephapi')->andReturn(null);
        $cache->shouldReceive('get')->with('rateLimitUpdatedPhapiTestsPagephapi')->andReturn(null);

        $cache->shouldReceive('set')->with('rateLimitPhapiTestsPagephapi', 799);
        $cache->shouldReceive('set')->with('rateLimitUpdatedPhapiTestsPagephapi', '/[0-9]+/');

        $request = \Mockery::mock('Psr\Http\Message\ServerRequestInterface');
        $request->shouldReceive('hasHeader')->with('Client-ID')->andReturn(true);
        $request->shouldReceive('getHeaderLine')->with('Client-ID')->andReturn('phapi');
        $request->shouldReceive('getAttribute')->with('routeEndpoint', null)->andReturn('\\Phapi\\Tests\\Page');

        $response = \Mockery::mock('Psr\Http\Message\ResponseInterface');
        $response->shouldReceive('withHeader')->with('X-Rate-Limit-Limit', '800')->andReturnSelf();
        $response->shouldReceive('withHeader')->with('X-Rate-Limit-Remaining', '799')->andReturnSelf();
        $response->shouldReceive('withHeader')->with('X-Rate-Limit-Window', '10')->andReturnSelf();
        $response->shouldReceive('withHeader')->with('X-Rate-Limit-New', '60')->andReturnSelf();

        $next = function($request, $response) { return $response; };

        $middleware = new RateLimit('Client-ID', $rateLimitBuckets, $cache);
        $middleware($request, $response, $next);

    }

    public function testNoCache()
    {
        $rateLimitBuckets = array(
            'default' => new Bucket(),
            '\\Phapi\\Tests\\Page' => new Bucket(800, 60, 10, false),
        );

        $cache = \Mockery::mock(['\Phapi\Cache\Nullcache', '\Phapi\Contract\Cache\Cache']);

        $request = \Mockery::mock('Psr\Http\Message\ServerRequestInterface');
        $request->shouldReceive('getAttribute')->with('routeEndpoint', null)->andReturn('\\Phapi\\Tests\\Page');

        $response = \Mockery::mock('Psr\Http\Message\ResponseInterface');

        $next = function($request, $response) { return $response; };

        $middleware = new RateLimit('Client-ID', $rateLimitBuckets, $cache);
        $this->setExpectedException('\Phapi\Exception\InternalServerError', 'Rate Limit needs a cache to work.');
        $middleware($request, $response, $next);

    }

    public function testNoBuckets()
    {
        $rateLimitBuckets = array();

        $cache = \Mockery::mock(['\Phapi\Cache\Nullcache', '\Phapi\Contract\Cache\Cache']);

        $request = \Mockery::mock('Psr\Http\Message\ServerRequestInterface');
        $request->shouldReceive('getAttribute')->with('routeEndpoint', null)->andReturn('\\Phapi\\Tests\\Page');

        $response = \Mockery::mock('Psr\Http\Message\ResponseInterface');

        $next = function($request, $response) { return $response; };

        $middleware = new RateLimit('Client-ID', $rateLimitBuckets, $cache);
        $this->setExpectedException('\Phapi\Exception\InternalServerError', 'Rate Limit needs at least one (default) bucket to work.');
        $middleware($request, $response, $next);
    }

    public function testDefaultBucket()
    {
        $rateLimitBuckets = array(
            'default' => new Bucket(),
        );

        $cache = \Mockery::mock(['\Phapi\Cache\Memcache', '\Phapi\Contract\Cache\Cache']);
        $cache->shouldReceive('get')->with('rateLimitPhapiTestsPagephapi')->andReturn(null);
        $cache->shouldReceive('get')->with('rateLimitUpdatedPhapiTestsPagephapi')->andReturn(null);

        $cache->shouldReceive('set')->with('rateLimitPhapiTestsPagephapi', 799);
        $cache->shouldReceive('set')->with('rateLimitUpdatedPhapiTestsPagephapi', '/[0-9]+/');

        $request = \Mockery::mock('Psr\Http\Message\ServerRequestInterface');
        $request->shouldReceive('hasHeader')->with('Client-ID')->andReturn(true);
        $request->shouldReceive('getHeaderLine')->with('Client-ID')->andReturn('phapi');
        $request->shouldReceive('getAttribute')->with('routeEndpoint', null)->andReturn('\\Phapi\\Tests\\Page');

        $response = \Mockery::mock('Psr\Http\Message\ResponseInterface');
        $response->shouldReceive('withHeader')->with('X-Rate-Limit-Limit', '800')->andReturnSelf();
        $response->shouldReceive('withHeader')->with('X-Rate-Limit-Remaining', '799')->andReturnSelf();
        $response->shouldReceive('withHeader')->with('X-Rate-Limit-Window', '1')->andReturnSelf();
        $response->shouldReceive('withHeader')->with('X-Rate-Limit-New', '7')->andReturnSelf();

        $next = function($request, $response) { return $response; };

        $middleware = new RateLimit('Client-ID', $rateLimitBuckets, $cache);
        $middleware($request, $response, $next);
    }

    public function testNoIdentifier()
    {
        $rateLimitBuckets = array(
            'default' => new Bucket(),
            '\\Phapi\\Resource\\Page' => new Bucket(800, 60, 10, false),
        );

        $container = \Mockery::mock('\Phapi\Contract\Di\Container');
        // Return self and act like a logger on next line
        $container->shouldReceive('offsetGet')->with('log')->andReturnSelf();
        $container->shouldReceive('warning')->with('Request (ID: U-u-i-d) made but without the Client-ID header. Please note that the request was executed as normal.');

        $cache = \Mockery::mock(['\Phapi\Cache\Memcache', '\Phapi\Contract\Cache\Cache']);

        $request = \Mockery::mock('Psr\Http\Message\ServerRequestInterface');
        $request->shouldReceive('hasHeader')->with('Client-ID')->andReturn(false);
        $request->shouldReceive('getAttribute')->with('routeEndpoint', null)->andReturn('\\Phapi\\Tests\\Page');

        $response = \Mockery::mock('Psr\Http\Message\ResponseInterface');
        $response->shouldReceive('getHeaderLine')->with('Uuid')->andReturn('U-u-i-d');

        $next = function($request, $response) { return $response; };

        $middleware = new RateLimit('Client-ID', $rateLimitBuckets, $cache);
        $middleware->setContainer($container);
        $middleware($request, $response, $next);
    }

    public function testNoTokensContinuous()
    {
        $rateLimitBuckets = array(
            'default' => new Bucket(),
            '\\Phapi\\Tests\\Page' => new Bucket(800, 60, 10, true),
        );

        $cache = \Mockery::mock(['\Phapi\Cache\Memcache', '\Phapi\Contract\Cache\Cache']);
        $cache->shouldReceive('get')->with('rateLimitPhapiTestsPagephapi')->andReturn(0);
        $cache->shouldReceive('get')->with('rateLimitUpdatedPhapiTestsPagephapi')->andReturn(time());

        $request = \Mockery::mock('Psr\Http\Message\ServerRequestInterface');
        $request->shouldReceive('hasHeader')->with('Client-ID')->andReturn(true);
        $request->shouldReceive('getHeaderLine')->with('Client-ID')->andReturn('phapi');
        $request->shouldReceive('getAttribute')->with('routeEndpoint', null)->andReturn('\\Phapi\\Tests\\Page');

        $response = \Mockery::mock('Psr\Http\Message\ResponseInterface');
        $response->shouldReceive('getHeaderLine')->with('Uuid')->andReturn('U-u-i-d');

        $next = function($request, $response) { return $response; };

        $middleware = new RateLimit('Client-ID', $rateLimitBuckets, $cache);
        $this->setExpectedException('\Phapi\Exception\TooManyRequests', 'You\'ve run out of request tokens. You receive 6 new tokens every second.');
        $middleware($request, $response, $next);
    }

    public function testNoTokensNotContinuous()
    {
        $rateLimitBuckets = array(
            'default' => new Bucket(),
            '\\Phapi\\Tests\\Page' => new Bucket(800, 60, 10, false),
        );

        $cache = \Mockery::mock(['\Phapi\Cache\Memcache', '\Phapi\Contract\Cache\Cache']);
        $cache->shouldReceive('get')->with('rateLimitPhapiTestsPagephapi')->andReturn(0);
        $cache->shouldReceive('get')->with('rateLimitUpdatedPhapiTestsPagephapi')->andReturn(time());

        $request = \Mockery::mock('Psr\Http\Message\ServerRequestInterface');
        $request->shouldReceive('hasHeader')->with('Client-ID')->andReturn(true);
        $request->shouldReceive('getHeaderLine')->with('Client-ID')->andReturn('phapi');
        $request->shouldReceive('getAttribute')->with('routeEndpoint', null)->andReturn('\\Phapi\\Tests\\Page');

        $response = \Mockery::mock('Psr\Http\Message\ResponseInterface');
        $response->shouldReceive('getHeaderLine')->with('Uuid')->andReturn('U-u-i-d');

        $next = function($request, $response) { return $response; };

        $middleware = new RateLimit('Client-ID', $rateLimitBuckets, $cache);
        $this->setExpectedException('\Phapi\Exception\TooManyRequests', 'You\'ve run out of request tokens. You receive 60 new tokens every 10 seconds.');
        $middleware($request, $response, $next);
    }

    public function testNoEndpoint()
    {
        $rateLimitBuckets = array(
            'default' => new Bucket(),
            '\\Phapi\\Tests\\Page' => new Bucket(800, 60, 10, false),
        );

        $cache = \Mockery::mock(['\Phapi\Cache\Memcache', '\Phapi\Contract\Cache\Cache']);

        $request = \Mockery::mock('Psr\Http\Message\ServerRequestInterface');
        $request->shouldReceive('getAttribute')->with('routeEndpoint', null)->andReturn(null);

        $response = \Mockery::mock('Psr\Http\Message\ResponseInterface');

        $next = function($request, $response) { return $response; };

        $middleware = new RateLimit('Client-ID', $rateLimitBuckets, $cache);
        $this->setExpectedException('\Phapi\Exception\InternalServerError', 'Rate Limit could not find a matched endpoint from the router. Make sure the requestAttribName is correct.');
        $middleware($request, $response, $next);
    }
}
