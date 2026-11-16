<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Illuminate\Contracts\Container\Container;

/**
 * What each module registers from its service provider: handlers and authorizers per
 * command class, a command factory per client event type, projectors, policies, reactors.
 * Classes are resolved lazily from the container so registration is cheap.
 */
final class Registry
{
    /** @var array<class-string<Command>, class-string<Handler>> */
    private array $handlers = [];

    /** @var array<class-string<Command>, class-string<Authorizer>> */
    private array $authorizers = [];

    /** @var array<string, class-string<CommandFactory>> */
    private array $factories = [];

    /** @var list<class-string<Projector>> */
    private array $projectors = [];

    /** @var list<class-string<Policy>> */
    private array $policies = [];

    /** @var list<class-string<Reactor>> */
    private array $reactors = [];

    /** @var array<string, list<Projector>>|null type → projectors, built on first use */
    private ?array $projectorIndex = null;

    /** @var array<string, list<Policy>>|null */
    private ?array $policyIndex = null;

    /** @var array<string, list<Reactor>>|null */
    private ?array $reactorIndex = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<Command>  $command
     * @param  class-string<Handler>  $handler
     * @param  class-string<Authorizer>|null  $authorizer
     */
    public function command(string $command, string $handler, ?string $authorizer = null): self
    {
        $this->handlers[$command] = $handler;
        if ($authorizer !== null) {
            $this->authorizers[$command] = $authorizer;
        }

        return $this;
    }

    /** @param class-string<CommandFactory> $factory */
    public function eventType(string $type, string $factory): self
    {
        $this->factories[$type] = $factory;

        return $this;
    }

    /** @param class-string<Projector> ...$projectors */
    public function projectors(string ...$projectors): self
    {
        array_push($this->projectors, ...$projectors);
        $this->projectorIndex = null;

        return $this;
    }

    /** @param class-string<Policy> ...$policies */
    public function policies(string ...$policies): self
    {
        array_push($this->policies, ...$policies);
        $this->policyIndex = null;

        return $this;
    }

    /** @param class-string<Reactor> ...$reactors */
    public function reactors(string ...$reactors): self
    {
        array_push($this->reactors, ...$reactors);
        $this->reactorIndex = null;

        return $this;
    }

    public function handlerFor(Command $command): Handler
    {
        $class = $this->handlers[$command::class] ?? null;
        if ($class === null) {
            throw new \LogicException('No handler registered for '.$command::class);
        }

        return $this->container->make($class);
    }

    /** @return list<class-string<Command>> */
    public function registeredCommands(): array
    {
        return array_keys($this->handlers);
    }

    /** @param class-string<Command> $commandClass */
    public function hasAuthorizer(string $commandClass): bool
    {
        return isset($this->authorizers[$commandClass]);
    }

    public function authorizerFor(Command $command): ?Authorizer
    {
        $class = $this->authorizers[$command::class] ?? null;

        return $class === null ? null : $this->container->make($class);
    }

    public function factoryFor(string $eventType): CommandFactory
    {
        $class = $this->factories[$eventType] ?? null;
        if ($class === null) {
            throw Rejection::unknownType($eventType);
        }

        return $this->container->make($class);
    }

    public function knowsEventType(string $eventType): bool
    {
        return isset($this->factories[$eventType]);
    }

    /** @return list<Projector> */
    public function allProjectors(): array
    {
        return array_map(fn (string $c): Projector => $this->container->make($c), $this->projectors);
    }

    /** @return list<Projector> */
    public function projectorsFor(string $type): array
    {
        $this->projectorIndex ??= $this->index($this->allProjectors());

        return $this->projectorIndex[$type] ?? [];
    }

    /** @return list<Policy> */
    public function policiesFor(string $type): array
    {
        $this->policyIndex ??= $this->index(array_map(fn (string $c): Policy => $this->container->make($c), $this->policies));

        return $this->policyIndex[$type] ?? [];
    }

    /** @return list<Reactor> */
    public function reactorsFor(string $type): array
    {
        $this->reactorIndex ??= $this->index(array_map(fn (string $c): Reactor => $this->container->make($c), $this->reactors));

        return $this->reactorIndex[$type] ?? [];
    }

    /**
     * @template T of Projector|Policy|Reactor
     *
     * @param  list<T>  $items
     * @return array<string, list<T>>
     */
    private function index(array $items): array
    {
        $index = [];
        foreach ($items as $item) {
            foreach ($item->handles() as $type) {
                $index[$type][] = $item;
            }
        }

        return $index;
    }
}
