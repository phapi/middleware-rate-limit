<?php

namespace Phapi\Middleware\RateLimit;

/**
 * Bucket contains the current token counter(s)
 *
 * @category Phapi
 * @package  Phapi\Middleware\RateLimit
 * @author   Peter Ahinko <peter@ahinko.se>
 * @license  MIT (http://opensource.org/licenses/MIT)
 * @link     https://github.com/phapi/middleware-rate-limit
 */
class Bucket
{

    /**
     * Number of total tokens
     *
     * @var int
     */
    public $totalTokens;

    /**
     * Number of new tokens to be added
     *
     * @var int
     */
    public $newTokens;

    /**
     * Window in number of seconds
     *
     * @var int
     */
    public $newTokensWindow;

    /**
     * Continuous addition of new tokens?
     *
     * @var bool
     */
    public $newTokenContinuous;

    /**
     * Remaining tokens
     *
     * @var int
     */
    public $remainingTokens = 0;

    /**
     * When was the cache for this identifier updated?
     *
     * @var int
     */
    public $updated;

    /**
     * @param $totalTokens int Total number of tokens in bucket
     * @param $newTokens int Number of new tokens to be added
     * @param $newTokensWindow int Window in number of seconds
     * @param $newTokenContinuous bool Continuous addition of new tokens?
     */
    public function __construct(
        $totalTokens = 800,
        $newTokens = 400,
        $newTokensWindow = 60,
        $newTokenContinuous = true
    ) {
        $this->totalTokens = $totalTokens;
        $this->newTokens = $newTokens;
        $this->newTokensWindow = $newTokensWindow;
        $this->newTokenContinuous = $newTokenContinuous;
    }
}
