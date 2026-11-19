<?php

namespace Wolvy;

/**
 * A read-only API object. Every result of the client is one of these (or a Collection of them),
 * typed by its `object` field: a `video` becomes a Model\Video, a `folder` a Model\Folder, and so
 * on (see OBJECT_TYPES). Fields read as properties or as array keys:
 *
 *     $video->title;            $video['title'];
 *     $settings->colors->accent; $settings['colors']['accent'];
 *
 * A field the API did not send reads as null. Nested objects are WolvyObjects too; JSON lists are
 * PHP arrays. toArray() gives back the decoded JSON exactly, and json_encode() an object gives
 * the same JSON. Objects are snapshots: setting a field throws.
 */
class WolvyObject implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * The `object` value of every documented API object, and the class it becomes.
     *
     * @var array<string,class-string<WolvyObject>>
     */
    const OBJECT_TYPES = [
        'list'               => Collection::class,
        'account'            => Model\Account::class,
        'health'             => Model\Health::class,
        'player_settings'    => Model\PlayerSettings::class,
        'video'              => Model\Video::class,
        'video_status'       => Model\VideoStatus::class,
        'bulk_result'        => Model\BulkResult::class,
        'folder'             => Model\Folder::class,
        'tag'                => Model\Tag::class,
        'video_tags'         => Model\VideoTags::class,
        'caption'            => Model\Caption::class,
        'chapter'            => Model\Chapter::class,
        'moment'             => Model\Moment::class,
        'playback_token'     => Model\PlaybackToken::class,
        'embed'              => Model\Embed::class,
        'analytics_overview' => Model\AnalyticsOverview::class,
        'analytics_day'      => Model\AnalyticsDay::class,
        'analytics_country'  => Model\AnalyticsCountry::class,
        'analytics_devices'  => Model\AnalyticsDevices::class,
        'video_analytics'    => Model\VideoAnalytics::class,
        'usage'              => Model\Usage::class,
        'usage_day'          => Model\UsageDay::class,
        'plan'               => Model\Plan::class,
        'viewer_session'     => Model\ViewerSession::class,
        'viewer'             => Model\Viewer::class,
        'viewer_sharing'     => Model\ViewerSharing::class,
        'security_event'     => Model\SecurityEvent::class,
        'webhook_endpoint'   => Model\WebhookEndpoint::class,
        'webhook_delivery'   => Model\WebhookDelivery::class,
        'usage_threshold'    => Model\UsageThreshold::class,
        'payment'            => Model\Payment::class,
    ];

    /** @var array<string|int,mixed> converted values */
    protected $values = [];

    /** @var array<string|int,mixed> the decoded JSON, untouched */
    protected $raw = [];

    /**
     * @param array<string|int,mixed> $values decoded JSON (associative arrays)
     */
    final public function __construct(array $values = [])
    {
        $this->raw = $values;
        foreach ($values as $key => $value) {
            $this->values[$key] = self::convert($value);
        }
        $this->init();
    }

    /** Hook for subclasses that keep extra state. */
    protected function init(): void
    {
    }

    /**
     * Builds the typed object for decoded JSON: the class named by its `object` field, a plain
     * WolvyObject otherwise.
     *
     * @param array<string|int,mixed> $values
     */
    public static function constructFrom(array $values): self
    {
        $type = isset($values['object']) && is_string($values['object']) ? $values['object'] : null;
        $class = ($type !== null && isset(self::OBJECT_TYPES[$type])) ? self::OBJECT_TYPES[$type] : self::class;
        return new $class($values);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function convert($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (self::isList($value)) {
            return array_map([self::class, 'convert'], $value);
        }
        return self::constructFrom($value);
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @return mixed null when the field is absent
     */
    public function __get(string $name)
    {
        return array_key_exists($name, $this->values) ? $this->values[$name] : null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    /**
     * @param mixed $value
     */
    public function __set(string $name, $value): void
    {
        throw new \LogicException('Wolvy API objects are read-only. Copy toArray() to change values.');
    }

    public function __unset(string $name): void
    {
        throw new \LogicException('Wolvy API objects are read-only. Copy toArray() to change values.');
    }

    /** True when the API sent this field (even as null). */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    /** @return array<int,string|int> the field names the API sent */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /**
     * The decoded JSON, as the API sent it.
     *
     * @return array<string|int,mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** @param mixed $offset */
    public function offsetExists($offset): bool
    {
        return isset($this->values[$offset]);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return array_key_exists($offset, $this->values) ? $this->values[$offset] : null;
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        throw new \LogicException('Wolvy API objects are read-only. Copy toArray() to change values.');
    }

    /** @param mixed $offset */
    public function offsetUnset($offset): void
    {
        throw new \LogicException('Wolvy API objects are read-only. Copy toArray() to change values.');
    }

    public function count(): int
    {
        return count($this->values);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->values);
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        // An empty object stays {} in JSON, not [].
        return $this->raw === [] ? new \stdClass() : $this->raw;
    }

    /** @return array<string|int,mixed> */
    public function __debugInfo()
    {
        return $this->raw;
    }
}
