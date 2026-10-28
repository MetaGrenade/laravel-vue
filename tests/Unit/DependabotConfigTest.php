<?php

namespace Tests\Unit;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DependabotConfigTest extends TestCase
{
    public function test_weekly_updates_cover_composer_npm_and_github_actions(): void
    {
        $parsed = Yaml::parseFile(base_path('.github/dependabot.yml'));

        $this->assertIsArray($parsed);
        $this->assertSame(2, $parsed['version'] ?? null);

        $updates = $parsed['updates'] ?? null;
        $this->assertIsArray($updates);

        $found = [];

        foreach ($updates as $update) {
            $this->assertIsArray($update);

            $ecosystem = $update['package-ecosystem'] ?? null;
            $this->assertIsString($ecosystem);
            $this->assertSame('/', $update['directory'] ?? null);

            $schedule = $update['schedule'] ?? null;
            $this->assertIsArray($schedule);
            $this->assertSame('weekly', $schedule['interval'] ?? null);

            $found[] = $ecosystem;
        }

        foreach (['composer', 'npm', 'github-actions'] as $ecosystem) {
            $this->assertContains($ecosystem, $found);
        }
    }
}
