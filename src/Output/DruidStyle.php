<?php

declare(strict_types=1);

namespace Druid\Output;

use Druid\Security\Severity;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SymfonyStyle with druid's coloured status lines.
 */
final class DruidStyle extends SymfonyStyle
{
    public function __construct(InputInterface $input, private readonly OutputInterface $druidOutput)
    {
        Theme::apply($druidOutput);
        parent::__construct($input, $druidOutput);
    }

    public function stepLine(int $number, int $total, string $title): void
    {
        $this->newLine();
        $this->writeln(sprintf('<step>[%d/%d]</step> <title>%s</title>', $number, $total, self::escape($title)));
    }

    public function okLine(string $message): void
    {
        $this->writeln('  <ok>' . Symbols::OK . '</ok> ' . self::escape($message));
    }

    public function failLine(string $message): void
    {
        $this->writeln('  <fail>' . Symbols::FAIL . '</fail> ' . self::escape($message));
    }

    public function warnLine(string $message): void
    {
        $this->writeln('  <warn>' . Symbols::WARN . '</warn> ' . self::escape($message));
    }

    public function infoLine(string $message): void
    {
        $this->writeln('  <note>' . Symbols::INFO . '</note> ' . self::escape($message));
    }

    public function arrowLine(string $message): void
    {
        $this->writeln('  <step>' . Symbols::ARROW . '</step> ' . self::escape($message));
    }

    public function commandLine(string $command, int $indent = 4): void
    {
        $this->writeln(str_repeat(' ', $indent) . '<cmd>$ ' . self::escape($command) . '</cmd>');
    }

    public function mutedLine(string $message, int $indent = 4): void
    {
        $this->writeln(str_repeat(' ', $indent) . '<muted>' . self::escape($message) . '</muted>');
    }

    public function heading(string $title): void
    {
        $this->newLine();
        $this->writeln('<label>' . self::escape($title) . '</label>');
        $this->writeln('<muted>' . str_repeat('─', max(10, mb_strlen($title))) . '</muted>');
    }

    public function keyValue(string $key, string $value): void
    {
        $this->writeln(sprintf('  <muted>%-18s</muted> %s', self::escape($key), $value));
    }

    public function isVerboseOutput(): bool
    {
        return $this->druidOutput->isVerbose();
    }

    /**
     * Wraps text in the colour style for a severity.
     */
    public static function severity(Severity $severity, ?string $text = null): string
    {
        $text = self::escape($text ?? $severity->label());
        $style = $severity->style();
        return $style === null ? $text : "<{$style}>{$text}</{$style}>";
    }

    /**
     * Colours a count: zero is green, otherwise the given style.
     */
    public static function count(int $count, string $style = 'warn'): string
    {
        return $count === 0 ? "<ok>0</ok>" : "<{$style}>{$count}</{$style}>";
    }

    public static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
