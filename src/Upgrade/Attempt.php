<?php

declare(strict_types=1);

namespace Druid\Upgrade;

/**
 * One Composer invocation tried for a step, optionally preceded by prerequisite commands
 * (e.g. `composer require --no-update` or a core update) that must succeed first.
 */
final class Attempt
{
    /**
     * @param list<string> $args Composer arguments
     * @param array<string, string> $env
     * @param list<Attempt> $prerequisites
     */
    public function __construct(
        public readonly string $strategy,
        public readonly string $description,
        public readonly array $args,
        public readonly array $env = [],
        public readonly array $prerequisites = [],
    ) {
    }

    /**
     * Identity used to avoid running the same command twice for a step.
     */
    public function key(): string
    {
        $parts = [];
        foreach ($this->prerequisites as $prerequisite) {
            $parts[] = $prerequisite->key();
        }
        $env = $this->env;
        ksort($env);
        $parts[] = implode(' ', $this->args) . '|' . http_build_query($env);
        return md5(implode('&&', $parts));
    }

    /**
     * @param array<string, string> $env
     */
    public function withEnv(array $env): self
    {
        return new self($this->strategy, $this->description, $this->args, $env + $this->env, $this->prerequisites);
    }

    public function withStrategy(string $strategy, string $description): self
    {
        return new self($strategy, $description, $this->args, $this->env, $this->prerequisites);
    }
}
