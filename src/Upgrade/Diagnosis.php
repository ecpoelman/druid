<?php

declare(strict_types=1);

namespace Druid\Upgrade;

use Druid\Composer\ComposerProblem;

/**
 * Why a Composer attempt failed, and what to try or tell the user next.
 */
final class Diagnosis
{
    public const NETWORK = 'network_timeout';
    public const AUDIT_BLOCKED = 'audit_blocked';
    public const NEEDS_NEWER_CORE = 'needs_newer_core';
    public const CONTRIB_BLOCKS_CORE = 'contrib_blocks_core';
    public const ROOT_DEPENDENCIES = 'root_dependencies';
    public const DEPENDENCY_CONFLICT = 'dependency_conflict';
    public const PHP_REQUIREMENT = 'php_requirement';
    public const MEMORY = 'memory_limit';
    public const SCAFFOLD_PERMISSIONS = 'scaffold_permissions';
    public const PLUGIN_BLOCKED = 'plugin_blocked';
    public const PATCH_FAILED = 'patch_failed';
    public const VERIFICATION_FAILED = 'verification_failed';
    public const SPAWN_FAILED = 'spawn_failed';
    public const UNKNOWN = 'unknown';

    /**
     * @param list<string> $hints
     * @param list<string> $suggestedCommands
     * @param list<string> $advisoryIds
     * @param list<ComposerProblem> $problems
     * @param list<string> $blockers packages that block the update
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $summary,
        public readonly array $hints = [],
        public readonly array $suggestedCommands = [],
        public readonly array $advisoryIds = [],
        public readonly array $problems = [],
        public readonly array $blockers = [],
    ) {
    }

    public function isAuditBlocked(): bool
    {
        return $this->kind === self::AUDIT_BLOCKED || $this->advisoryIds !== [];
    }

    public function needsCore(): bool
    {
        return $this->kind === self::NEEDS_NEWER_CORE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'summary' => $this->summary,
            'hints' => $this->hints,
            'suggested_commands' => $this->suggestedCommands,
            'advisories' => $this->advisoryIds,
            'blockers' => $this->blockers,
            'problems' => array_map(static fn (ComposerProblem $p): array => $p->toArray(), $this->problems),
        ];
    }
}
