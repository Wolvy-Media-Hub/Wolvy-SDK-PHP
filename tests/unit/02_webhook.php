<?php
/**
 * verifyWebhook(): the published vector (docs.wolvy.net/webhooks/#verify), the 5-minute
 * tolerance in both directions, tampering, malformed headers, and the decoded event.
 */

use Wolvy\Exception\InvalidArgumentException;
use Wolvy\Exception\SignatureVerificationException;
use Wolvy\Model\Video;
use Wolvy\Model\WebhookEvent;
use Wolvy\Webhook;
use Wolvy\Wolvy;
use Wolvy\WolvyObject;

T::section('Webhook signatures');

$SECRET = 'whsec_56b87e856166601d9414d8e3b96f78fe26cbda605a38676a';
$T = 1790000000;
$V1 = 'e43bfc1bae59608bba1a11c2eef01cde3b04a7794673edf8365eafd94cd8e2aa';
$BODY = '{"id":"evt_3f9a1c07b2e84d5a6c10","type":"video.ready","created_at":"2026-09-21T14:13:10+00:00","data":{"id":"a1b2c3d4e5f60718293a","object":"video","status":"ready"}}';
$HEADER = "t={$T},v1={$V1}";

T::run('test vector', function () use ($SECRET, $T, $V1, $BODY, $HEADER) {
    $event = Wolvy::verifyWebhook($BODY, $HEADER, $SECRET, 300, $T + 100);
    T::ok($event instanceof WebhookEvent, 'returns a WebhookEvent');
    T::same($event->id, 'evt_3f9a1c07b2e84d5a6c10', 'event id');
    T::same($event->getType(), WebhookEvent::VIDEO_READY, 'event type');
    T::ok($event->data instanceof Video, 'data of a video event is a Model\\Video');
    T::same($event->data->id, 'a1b2c3d4e5f60718293a', 'data->id');
    T::same($event['data']['status'], 'ready', 'array access works too');
    T::same($event->toArray(), json_decode($BODY, true), 'toArray() is the decoded body');
    T::same(Webhook::sign($BODY, $SECRET, $T), $HEADER, 'Webhook::sign() rebuilds the published header');
    T::same(\Wolvy\verifyWebhook($BODY, $HEADER, $SECRET, 300, $T)->id, 'evt_3f9a1c07b2e84d5a6c10', '\\Wolvy\\verifyWebhook() too');
});

T::run('tolerance', function () use ($SECRET, $T, $BODY, $HEADER) {
    T::ok(Wolvy::verifyWebhook($BODY, $HEADER, $SECRET, 300, $T + 300) instanceof WebhookEvent, 'exactly 300 s old is accepted');
    T::ok(Wolvy::verifyWebhook($BODY, $HEADER, $SECRET, 300, $T - 300) instanceof WebhookEvent, 'exactly 300 s ahead is accepted');
    T::throws(function () use ($SECRET, $T, $BODY, $HEADER) { Wolvy::verifyWebhook($BODY, $HEADER, $SECRET, 300, $T + 301); }, SignatureVerificationException::class, '301 s old is refused (replay)');
    T::throws(function () use ($SECRET, $T, $BODY, $HEADER) { Wolvy::verifyWebhook($BODY, $HEADER, $SECRET, 300, $T - 301); }, SignatureVerificationException::class, '301 s in the future is refused');
    T::throws(function () use ($SECRET, $BODY, $HEADER) { Wolvy::verifyWebhook($BODY, $HEADER, $SECRET); }, SignatureVerificationException::class, 'the 2026 vector is stale against the real clock (default tolerance 300)');
    T::ok(Wolvy::verifyWebhook($BODY, $HEADER, $SECRET, 900, $T + 899) instanceof WebhookEvent, 'a custom tolerance (900 s) is honoured');
    T::ok(Wolvy::verifyWebhook($BODY, $HEADER, $SECRET, 0) instanceof WebhookEvent, 'tolerance 0 turns the time check off');
    $fresh = Webhook::sign($BODY, $SECRET);
    T::ok(Wolvy::verifyWebhook($BODY, $fresh, $SECRET) instanceof WebhookEvent, 'a delivery signed now verifies with the real clock');
});

T::run('tampering', function () use ($SECRET, $T, $V1, $BODY, $HEADER) {
    $at = $T + 10;
    $cases = [
        'one byte of the body changed'  => [str_replace('"ready"}', '"failed"}', $BODY), $HEADER, $SECRET],
        'the body re-encoded (pretty)'  => [json_encode(json_decode($BODY), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $HEADER, $SECRET],
        'a trailing newline added'      => [$BODY . "\n", $HEADER, $SECRET],
        'the timestamp changed'         => [$BODY, 't=' . ($T + 1) . ",v1={$V1}", $SECRET],
        'the signature changed'         => [$BODY, "t={$T},v1=" . strrev($V1), $SECRET],
        'another endpoint\'s secret'    => [$BODY, $HEADER, 'whsec_' . str_repeat('0', 48)],
        'the secret without whsec_'     => [$BODY, $HEADER, substr($SECRET, 6)],
    ];
    foreach ($cases as $label => [$body, $header, $secret]) {
        T::throws(function () use ($body, $header, $secret, $at) { Wolvy::verifyWebhook($body, $header, $secret, 300, $at); }, SignatureVerificationException::class, "refused: {$label}");
    }
});

T::run('headers', function () use ($SECRET, $T, $V1, $BODY) {
    $at = $T + 10;
    foreach ([
        'missing (null)'      => null,
        'empty'               => '',
        'no t'                => "v1={$V1}",
        'no v1'               => "t={$T}",
        't not a number'      => "t=abc,v1={$V1}",
        't negative'          => "t=-{$T},v1={$V1}",
        'v1 empty'            => "t={$T},v1=",
        'only a v0 signature' => "t={$T},v0={$V1}",
        'garbage'             => 'Bearer something',
    ] as $label => $header) {
        $e = T::throws(function () use ($header, $SECRET, $BODY, $at) { Wolvy::verifyWebhook($BODY, $header, $SECRET, 300, $at); }, SignatureVerificationException::class, "refused header: {$label}");
    }
    T::same($e instanceof SignatureVerificationException ? $e->getSignatureHeader() : null, 'Bearer something', 'the exception keeps the header for logging');
    T::ok(Wolvy::verifyWebhook($BODY, " t={$T} , v1={$V1} ", $SECRET, 300, $at) instanceof WebhookEvent, 'spaces around the parts are tolerated');
    T::ok(Wolvy::verifyWebhook($BODY, "t={$T},v1=" . strtoupper($V1), $SECRET, 300, $at) instanceof WebhookEvent, 'upper-case hex is tolerated');
    T::ok(Wolvy::verifyWebhook($BODY, "t={$T},v1=" . str_repeat('0', 64) . ",v1={$V1}", $SECRET, 300, $at) instanceof WebhookEvent, 'any one of several v1 values may match (secret rotation)');
    T::ok(Wolvy::verifyWebhook($BODY, "v1={$V1},t={$T},x=1", $SECRET, 300, $at) instanceof WebhookEvent, 'order and unknown keys do not matter');
    T::throws(function () use ($BODY, $T, $V1) { Wolvy::verifyWebhook($BODY, "t={$T},v1={$V1}", ''); }, InvalidArgumentException::class, 'an empty secret is a usage error, not a bad signature');
});

T::run('signed but not an event', function () use ($SECRET) {
    $at = 1790000000;
    foreach (['not JSON' => 'hello', 'a JSON list' => '[1,2]', 'no type' => '{"id":"evt_1"}'] as $label => $body) {
        T::throws(function () use ($body, $SECRET, $at) { Wolvy::verifyWebhook($body, Webhook::sign($body, $SECRET, $at), $SECRET, 300, $at); }, SignatureVerificationException::class, "refused: {$label}");
    }
});

T::run('event payloads are typed', function () use ($SECRET) {
    $at = 1790000000;
    $events = [
        'webhook.test'            => [['object' => 'test', 'message' => 'If you can verify this signature, your endpoint is configured correctly.'], WolvyObject::class],
        'security.event'          => [['id' => 20977, 'object' => 'security_event', 'event_type' => 'drm_device_revoked', 'severity' => 'critical', 'video_id' => 'a', 'viewer_id' => 'u'], \Wolvy\Model\SecurityEvent::class],
        'payment.paid'            => [['id' => 5512, 'object' => 'payment', 'amount' => 129, 'plan_type' => 'fixed', 'billing_cycle' => 'monthly'], \Wolvy\Model\Payment::class],
        'usage.threshold_reached' => [['object' => 'usage_threshold', 'metric' => 'storage', 'threshold_percent' => 80, 'used_percent' => 81.2, 'used_bytes' => 1, 'limit_bytes' => 2, 'available_bytes' => 1, 'period' => ['start' => '2026-11-01', 'end' => '2026-12-01']], \Wolvy\Model\UsageThreshold::class],
        'caption.ready'           => [['id' => 'a', 'object' => 'video', 'captions' => [['label' => 'English', 'language_code' => 'en']]], Video::class],
    ];
    foreach ($events as $type => [$data, $class]) {
        $body = json_encode(['id' => 'evt_' . str_repeat('1', 20), 'type' => $type, 'created_at' => '2026-11-19T10:00:00+00:00', 'data' => $data]);
        $event = Wolvy::verifyWebhook($body, Webhook::sign($body, $SECRET, $at), $SECRET, 300, $at);
        T::ok(get_class($event->getData()) === $class, "{$type}: data is " . $class);
    }
    T::ok(in_array('usage.threshold_reached', WebhookEvent::SUBSCRIBABLE, true) && !in_array('webhook.test', WebhookEvent::SUBSCRIBABLE, true), 'SUBSCRIBABLE lists the API\'s eight events, not webhook.test');
    T::same(count(WebhookEvent::SUBSCRIBABLE), 8, 'eight subscribable events');
});
