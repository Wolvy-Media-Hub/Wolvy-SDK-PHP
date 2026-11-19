<?php

namespace Wolvy;

/**
 * The rate-limit state the API reported on one answer.
 *
 * Every answer after the API key was accepted carries these headers, errors included:
 * X-RateLimit-Limit / -Remaining / -Reset (the per-minute window, reset as a Unix time) and
 * X-RateLimit-Daily-Limit / -Daily-Remaining. A 429 adds Retry-After (seconds). A field is null
 * when its header was absent.
 */
final class RateLimit
{
    /** @var int|null Requests allowed per minute for this key. */
    public $limit;

    /** @var int|null Requests left in the current minute. */
    public $remaining;

    /** @var int|null Unix time at which the minute window resets. */
    public $reset;

    /** @var int|null Requests allowed per day (UTC) for this key. */
    public $dailyLimit;

    /** @var int|null Requests left today. */
    public $dailyRemaining;

    /** @var int|null Seconds to wait before retrying (only on a 429). */
    public $retryAfter;

    /**
     * @param array<string,string> $headers lower-cased header names
     */
    public static function fromHeaders(array $headers, ?int $now = null): ?self
    {
        $names = [
            'limit'          => 'x-ratelimit-limit',
            'remaining'      => 'x-ratelimit-remaining',
            'reset'          => 'x-ratelimit-reset',
            'dailyLimit'     => 'x-ratelimit-daily-limit',
            'dailyRemaining' => 'x-ratelimit-daily-remaining',
        ];

        $rateLimit = new self();
        $found = false;
        foreach ($names as $property => $header) {
            if (isset($headers[$header]) && preg_match('/^\s*(\d+)\s*$/', $headers[$header], $m)) {
                $rateLimit->{$property} = (int) $m[1];
                $found = true;
            }
        }

        if (isset($headers['retry-after'])) {
            $retryAfter = self::parseRetryAfter($headers['retry-after'], $now);
            if ($retryAfter !== null) {
                $rateLimit->retryAfter = $retryAfter;
                $found = true;
            }
        }

        return $found ? $rateLimit : null;
    }

    /**
     * Retry-After is seconds from the API; an HTTP date (allowed by RFC 9110) is accepted too.
     */
    public static function parseRetryAfter(string $value, ?int $now = null): ?int
    {
        $value = trim($value);
        if (preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }
        $time = strtotime($value);
        if ($value === '' || $time === false) {
            return null;
        }
        return max(0, $time - ($now !== null ? $now : time()));
    }

    /** @return array<string,int|null> */
    public function toArray(): array
    {
        return [
            'limit'           => $this->limit,
            'remaining'       => $this->remaining,
            'reset'           => $this->reset,
            'daily_limit'     => $this->dailyLimit,
            'daily_remaining' => $this->dailyRemaining,
            'retry_after'     => $this->retryAfter,
        ];
    }
}
