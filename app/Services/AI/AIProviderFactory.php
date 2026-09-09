<?php

namespace App\Services\AI;

use App\Services\AI\Exceptions\AIException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Hands out the configured text provider.
 *
 * There is one today, but the product's AI work is described in terms of what
 * it needs rather than who supplies it, so the choice sits behind this rather
 * than being resolved by class name at each call site.
 */
class AIProviderFactory
{
    /**
     * @param  array<string, class-string<AIProviderInterface>>  $providers
     */
    public function __construct(
        protected Container $container,
        protected array $providers,
        protected string $default,
    ) {}

    /**
     * @throws AIException
     */
    public function make(?string $provider = null): AIProviderInterface
    {
        $name = $provider ?? $this->default;

        if (! isset($this->providers[$name])) {
            throw new InvalidArgumentException("No AI provider is registered as [{$name}].");
        }

        $resolved = $this->container->make($this->providers[$name]);

        if (! $resolved->isAvailable()) {
            throw AIException::for($name, 'no API key is configured');
        }

        return $resolved;
    }

    /**
     * Whether the named provider — or the default — could answer right now.
     */
    public function isAvailable(?string $provider = null): bool
    {
        $name = $provider ?? $this->default;

        return isset($this->providers[$name])
            && $this->container->make($this->providers[$name])->isAvailable();
    }

    /**
     * @return array<int, string>
     */
    public function providerNames(): array
    {
        return array_keys($this->providers);
    }
}
