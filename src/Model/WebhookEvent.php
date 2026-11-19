<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A verified webhook delivery, as returned by Wolvy::verifyWebhook(). The body Wolvy posts is
 * `{"id": "evt_…", "type": "video.ready", "created_at": "…", "data": {…}}`; `data` is typed by its
 * `object` field like any API object (a Video for the video events, a SecurityEvent, a Payment,
 * a UsageThreshold; a plain WolvyObject for `webhook.test`).
 *
 * Deliveries can repeat (a retry after a lost answer): record `id` and skip one you have seen.
 *
 * @property-read string      $id         "evt_" + 20 hex: the de-duplication key.
 * @property-read string      $type       One of the TYPE_* constants.
 * @property-read string|null $created_at RFC 3339.
 * @property-read WolvyObject $data
 */
class WebhookEvent extends WolvyObject
{
    /** Wolvy downloaded an API upload's source and handed it to encoding. */
    const VIDEO_CREATED = 'video.created';
    /** Every resolution is encoded. */
    const VIDEO_READY = 'video.ready';
    /** Ingest or encoding failed; ingest failures carry `error`. */
    const VIDEO_FAILED = 'video.failed';
    /** A video was deleted through the API. */
    const VIDEO_DELETED = 'video.deleted';
    /** A caption was added or replaced. */
    const CAPTION_READY = 'caption.ready';
    /** A security event was recorded. */
    const SECURITY_EVENT = 'security.event';
    /** A payment settled. */
    const PAYMENT_PAID = 'payment.paid';
    /** 80 % or 100 % of the plan's storage or bandwidth was reached. */
    const USAGE_THRESHOLD_REACHED = 'usage.threshold_reached';
    /** Only from POST /v1/webhook-endpoints/{id}/test. Not subscribable. */
    const WEBHOOK_TEST = 'webhook.test';

    /** The events an endpoint can subscribe to (`events` of create and update). */
    const SUBSCRIBABLE = [
        self::VIDEO_CREATED,
        self::VIDEO_READY,
        self::VIDEO_FAILED,
        self::VIDEO_DELETED,
        self::CAPTION_READY,
        self::SECURITY_EVENT,
        self::PAYMENT_PAID,
        self::USAGE_THRESHOLD_REACHED,
    ];

    public function getId(): string
    {
        return (string) $this->__get('id');
    }

    public function getType(): string
    {
        return (string) $this->__get('type');
    }

    public function getData(): WolvyObject
    {
        $data = $this->__get('data');
        return $data instanceof WolvyObject ? $data : new WolvyObject([]);
    }

    public function isTest(): bool
    {
        return $this->getType() === self::WEBHOOK_TEST;
    }
}
