<?php

namespace Wolvy\Exception;

/**
 * A webhook delivery did not verify: the `Wolvy-Signature` header is missing or malformed, the
 * timestamp is outside the tolerance, the HMAC does not match, or the verified body is not a
 * JSON event. Answer the delivery with HTTP 400 and do not act on it.
 */
class SignatureVerificationException extends \UnexpectedValueException implements WolvyException
{
    /** @var string|null */
    private $signatureHeader;

    public function __construct(string $message, ?string $signatureHeader = null)
    {
        parent::__construct($message);
        $this->signatureHeader = $signatureHeader;
    }

    /** The header as it was received, for logging. */
    public function getSignatureHeader(): ?string
    {
        return $this->signatureHeader;
    }
}
