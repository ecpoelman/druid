<?php

declare(strict_types=1);

namespace Druid\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads configuration in Drush-like layers: defaults, then ~/.druid/druid.yml,
 * then <project>/druid.yml (or <project>/drush/druid.yml).
 */
final class ConfigLoader
{
    /** @var list<string> */
    private array $loadedFiles = [];

    public function __construct(private readonly string $home)
    {
    }

    public function load(?string $projectRoot = null): Config
    {
        $this->loadedFiles = [];
        $data = Config::defaults();

        $files = [$this->home . '/druid.yml'];
        if ($projectRoot !== null) {
            $files[] = $projectRoot . '/drush/druid.yml';
            $files[] = $projectRoot . '/druid.yml';
        }

        foreach ($files as $file) {
            $layer = $this->read($file);
            if ($layer !== null) {
                $data = Config::merge($data, $layer);
                $this->loadedFiles[] = $file;
            }
        }

        return new Config($data);
    }

    /**
     * Files that contributed to the last load(), in order.
     *
     * @return list<string>
     */
    public function loadedFiles(): array
    {
        return $this->loadedFiles;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new \RuntimeException("Invalid YAML in {$file}: {$e->getMessage()}", 0, $e);
        }
        return is_array($parsed) ? $parsed : null;
    }
}
