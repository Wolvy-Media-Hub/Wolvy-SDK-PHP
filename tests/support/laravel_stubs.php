<?php
/**
 * Just enough of Laravel (and Symfony's Response) to run the service provider, the facade and
 * the middleware without installing a framework. Each stub is defined only when the real class
 * is absent. They imitate the few framework calls the SDK makes, nothing more.
 */

namespace Illuminate\Support {
    if (!class_exists(ServiceProvider::class)) {
        abstract class ServiceProvider
        {
            /** @var \FakeLaravelApp */
            protected $app;

            /** @var array<string,array<string,string>> */
            public static $publishGroups = [];

            public function __construct($app)
            {
                $this->app = $app;
            }

            protected function mergeConfigFrom($path, $key)
            {
                $config = $this->app['config'];
                $config->set($key, array_merge(require $path, $config->get($key, [])));
            }

            protected function publishes(array $paths, $groups = null)
            {
                static::$publishGroups[(string) $groups] = $paths;
            }
        }
    }
}

namespace Illuminate\Support\Facades {
    if (!class_exists(Facade::class)) {
        abstract class Facade
        {
            /** @var \FakeLaravelApp */
            public static $app;

            public static function __callStatic($method, $args)
            {
                return static::$app->make(static::getFacadeAccessor())->$method(...$args);
            }
        }
    }
}

namespace Symfony\Component\HttpFoundation {
    if (!class_exists(Response::class)) {
        class Response
        {
            public $content;
            public $status;
            public $headers;

            public function __construct($content = '', $status = 200, array $headers = [])
            {
                $this->content = $content;
                $this->status = $status;
                $this->headers = $headers;
            }

            public function getStatusCode()
            {
                return $this->status;
            }
        }
    }
}

namespace {
    if (!function_exists('env')) {
        function env($key, $default = null)
        {
            $value = getenv($key);
            return $value === false ? $default : $value;
        }
    }

    final class FakeLaravelConfig
    {
        /** @var array<string,mixed> */
        private $items = [];

        public function get($key, $default = null)
        {
            return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
        }

        public function set($key, $value)
        {
            $this->items[$key] = $value;
        }
    }

    final class FakeLaravelApp implements ArrayAccess
    {
        /** @var array<string,callable> */
        private $factories = [];
        /** @var array<string,object> */
        private $instances = [];
        /** @var array<string,string> */
        private $aliases = [];
        /** @var FakeLaravelConfig */
        public $config;

        public function __construct()
        {
            $this->config = new FakeLaravelConfig();
        }

        public function singleton($abstract, $factory)
        {
            $this->factories[$abstract] = $factory;
        }

        public function alias($abstract, $alias)
        {
            $this->aliases[$alias] = $abstract;
        }

        public function make($abstract)
        {
            $abstract = isset($this->aliases[$abstract]) ? $this->aliases[$abstract] : $abstract;
            if (!isset($this->instances[$abstract])) {
                $this->instances[$abstract] = call_user_func($this->factories[$abstract], $this);
            }
            return $this->instances[$abstract];
        }

        public function runningInConsole()
        {
            return true;
        }

        public function configPath($path = '')
        {
            return '/app/config/' . $path;
        }

        public function offsetExists($offset): bool
        {
            return $offset === 'config';
        }

        #[\ReturnTypeWillChange]
        public function offsetGet($offset)
        {
            return $offset === 'config' ? $this->config : $this->make($offset);
        }

        public function offsetSet($offset, $value): void
        {
        }

        public function offsetUnset($offset): void
        {
        }
    }

    final class FakeLaravelBag
    {
        /** @var array<string,mixed> */
        private $items;

        public function __construct(array $items = [])
        {
            $this->items = array_change_key_case($items, CASE_LOWER);
        }

        public function get($key, $default = null)
        {
            $key = strtolower($key);
            return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
        }

        public function set($key, $value)
        {
            $this->items[strtolower($key)] = $value;
        }
    }

    final class FakeLaravelRequest
    {
        public $headers;
        public $attributes;
        private $content;

        public function __construct($content, array $headers)
        {
            $this->content = $content;
            $this->headers = new FakeLaravelBag($headers);
            $this->attributes = new FakeLaravelBag();
        }

        public function getContent()
        {
            return $this->content;
        }
    }
}
