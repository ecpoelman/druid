<?php

declare(strict_types=1);

namespace Druid\Output;

use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registers druid's colour styles on console formatters.
 *
 * Styles: critical, high, moderate, low, ok, step, cmd, muted, title, label.
 */
final class Theme
{
    /**
     * @return array<string, OutputFormatterStyle>
     */
    public static function styles(): array
    {
        return [
            'critical' => new OutputFormatterStyle('white', 'red', ['bold']),
            'high' => new OutputFormatterStyle('red', null, ['bold']),
            'moderate' => new OutputFormatterStyle('yellow'),
            'low' => new OutputFormatterStyle('cyan'),
            'ok' => new OutputFormatterStyle('green'),
            'fail' => new OutputFormatterStyle('red'),
            'warn' => new OutputFormatterStyle('yellow'),
            'note' => new OutputFormatterStyle('blue'),
            'step' => new OutputFormatterStyle('magenta', null, ['bold']),
            'cmd' => new OutputFormatterStyle('gray'),
            'muted' => new OutputFormatterStyle('gray'),
            'title' => new OutputFormatterStyle('white', null, ['bold']),
            'label' => new OutputFormatterStyle('blue', null, ['bold']),
        ];
    }

    public static function apply(OutputInterface $output): void
    {
        self::applyToFormatter($output->getFormatter());
        if ($output instanceof ConsoleOutputInterface) {
            self::applyToFormatter($output->getErrorOutput()->getFormatter());
        }
    }

    public static function applyToFormatter(OutputFormatterInterface $formatter): void
    {
        foreach (self::styles() as $name => $style) {
            $formatter->setStyle($name, $style);
        }
    }
}
