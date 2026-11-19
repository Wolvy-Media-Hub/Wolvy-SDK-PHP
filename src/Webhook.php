<?php

namespace Wolvy;

use Wolvy\Exception\InvalidArgumentException;
use Wolvy\Exception\SignatureVerificationException;
use Wolvy\Model\WebhookEvent;

/**
 * Webhook signatures. Wolvy signs every delivery with the endpoint's own secret (`whsec_…`,
 * returned once when the endpoint is created):
 *
 *     Wolvy-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
 *
 * Verify the RAW body (php://input, $request->getContent()), never a re-encoded copy.
 */
final class Webhook
{
    /** The documented tolerance: a delivery signed more than 5 minutes from your clock is refused. */
    const DEFAULT_TOLERANCE = 300;

    /** The header Wolvy sends the signature in. */
    const SIGNATURE_HEADER = 'Wolvy-Signature';

    /**
     * Verifies a delivery and returns the decoded event.
     *
     * @param string      $rawBody         the exact bytes that arrived
     * @param string|null $signatureHeader the Wolvy-Signature header (null when absent)
     * @param string      $secret          the endpoint's whsec_… secret, as returned
     * @param int         $tolerance       seconds between `t` and your clock, either way; 0 or less
     *                                     turns the check off (to re-verify a stored delivery)
     * @param int|null    $now             Unix time to check against (tests); default time()
     *
     * @throws SignatureVerificationException the delivery must not be trusted
     * @throws InvalidArgumentException       the secret is empty
     */
    public static function verify(
        string $rawBody,
        ?string $signatureHeader,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null
    ): WebhookEvent {
        if ($secret === '') {
            throw new InvalidArgumentException('The webhook signing secret is empty.');
        }
        if ($signatureHeader === null || trim($signatureHeader) === '') {
            throw new SignatureVerificationException('The Wolvy-Signature header is missing.', $signatureHeader);
        }

        [$timestamp, $signatures] = self::parseHeader($signatureHeader);
        if ($timestamp === null) {
            throw new SignatureVerificationException('The Wolvy-Signature header has no valid timestamp (t=).', $signatureHeader);
        }
        if ($signatures === []) {
            throw new SignatureVerificationException('The Wolvy-Signature header has no v1 signature.', $signatureHeader);
        }

        $now = $now !== null ? $now : time();
        if ($tolerance > 0 && abs($now - (int) $timestamp) > $tolerance) {
            throw new SignatureVerificationException(sprintf(
                'The delivery was signed at %s, more than %d seconds from now (%d): refused as a possible replay.',
                $timestamp,
                $tolerance,
                $now
            ), $signatureHeader);
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        $matched = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower($signature))) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new SignatureVerificationException(
                'The signature does not match the body. Check the endpoint secret, and verify the raw body, not a re-encoded one.',
                $signatureHeader
            );
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event) || !isset($event['id'], $event['type']) || !is_string($event['type'])) {
            throw new SignatureVerificationException('The signed body is not a Wolvy event.', $signatureHeader);
        }

        return new WebhookEvent($event);
    }

    /**
     * Builds a Wolvy-Signature value for a body, the way Wolvy does. For testing your own
     * receiver; Wolvy itself signs real deliveries.
     */
    public static function sign(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('The webhook signing secret is empty.');
        }
        $t = (string) ($timestamp !== null ? $timestamp : time());
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $rawBody, $secret);
    }

    /**
     * Splits "t=…,v1=…" into the timestamp and every v1 value. Unknown keys are ignored.
     *
     * @return array{0: string|null, 1: string[]}
     */
    private static function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            $key = trim($pair[0]);
            $value = trim($pair[1]);
            if ($key === 't') {
                $timestamp = preg_match('/^\d{1,12}$/', $value) === 1 ? $value : null;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        return [$timestamp, $signatures];
    }
}
