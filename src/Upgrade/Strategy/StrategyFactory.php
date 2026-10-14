<?php

declare(strict_types=1);

namespace Druid\Upgrade\Strategy;

use Druid\Config\Config;

/**
 * Builds the strategy ladder in the order configured under `upgrade.strategies`.
 */
final class StrategyFactory
{
    /**
     * @return list<Strategy>
     */
    public static function fromConfig(Config $config): array
    {
        $names = $config->list('upgrade.strategies');
        if ($names === []) {
            $names = Config::defaults()['upgrade']['strategies'];
        }

        $strategies = [];
        foreach ($names as $name) {
            $strategy = self::create($name, (int) $config->get('upgrade.step_down_attempts', 3));
            if ($strategy !== null) {
                $strategies[] = $strategy;
            }
        }
        return $strategies;
    }

    public static function create(string $name, int $stepDownAttempts = 3): ?Strategy
    {
        return match ($name) {
            'isolated' => new IsolatedStrategy(),
            'require_bump' => new RequireBumpStrategy(),
            'blockers' => new BlockersStrategy(),
            'all_dependencies' => new AllDependenciesStrategy(),
            'no_audit_blocking' => new NoAuditBlockingStrategy(),
            'core_first' => new CoreFirstStrategy(),
            'step_down' => new StepDownStrategy(max(1, $stepDownAttempts)),
            'lenient' => new LenientStrategy(),
            default => null,
        };
    }
}
