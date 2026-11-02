<?php

namespace Tests\Unit;

use App\Support\Search\SearchDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchDriverTest extends TestCase
{
    public function test_the_database_driver_does_not_talk_to_meilisearch(): void
    {
        $this->assertSame('database', config('search.driver'));
        $this->assertSame('null', config('scout.driver'));
        $this->assertFalse(config('scout.queue'));
    }

    /**
     * @return array<string, array{mixed, mixed, bool}>
     */
    public static function queueSettings(): array
    {
        return [
            'meilisearch, not set' => [null, 'meilisearch', true],
            'meilisearch, set but empty' => ['', 'meilisearch', true],
            'database, not set' => [null, 'database', false],
            'database, set but empty' => ['', 'database', false],
            'a driver that is not known, not set' => [null, 'algolia', false],
            'meilisearch, turned off' => [false, 'meilisearch', false],
            'meilisearch, turned off as text' => ['false', 'meilisearch', false],
            'meilisearch, turned off as 0' => ['0', 'meilisearch', false],
            'meilisearch, turned off as no' => ['no', 'meilisearch', false],
            'database, turned on' => [true, 'database', true],
            'database, turned on as 1' => ['1', 'database', true],
            'meilisearch, turned on' => [true, 'meilisearch', true],
        ];
    }

    #[Test]
    #[DataProvider('queueSettings')]
    public function saving_is_indexed_on_the_queue_when_scout_queue_says_so_and_with_meilisearch_when_it_says_nothing(mixed $setting, mixed $driver, bool $expected): void
    {
        $this->assertSame($expected, SearchDriver::indexesOnQueue($setting, $driver));
    }

    #[Test]
    public function the_scout_configuration_reads_both_variables(): void
    {
        $this->assertTrue($this->scoutQueueWith(['SEARCH_DRIVER' => 'meilisearch', 'SCOUT_QUEUE' => null]), 'Meilisearch indexes on the queue unless told not to.');
        $this->assertFalse($this->scoutQueueWith(['SEARCH_DRIVER' => 'meilisearch', 'SCOUT_QUEUE' => 'false']));
        $this->assertFalse($this->scoutQueueWith(['SEARCH_DRIVER' => 'database', 'SCOUT_QUEUE' => null]));
        $this->assertTrue($this->scoutQueueWith(['SEARCH_DRIVER' => 'database', 'SCOUT_QUEUE' => 'true']));
        $this->assertFalse($this->scoutQueueWith(['SEARCH_DRIVER' => null, 'SCOUT_QUEUE' => null]), 'Nothing set is the database driver.');
    }

    /**
     * What config/scout.php says for these variables (null is not set), as if the application had been started with them.
     *
     * @param  array<string, string|null>  $variables
     */
    private function scoutQueueWith(array $variables): bool
    {
        $before = [];

        foreach ($variables as $name => $value) {
            $before[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name) === false ? null : getenv($name)];
            $this->setVariable($name, $value, $value, $value);
        }

        try {
            return (bool) (require config_path('scout.php'))['queue'];
        } finally {
            foreach ($before as $name => [$env, $server, $process]) {
                $this->setVariable($name, $env, $server, $process);
            }
        }
    }

    /**
     * Sets a variable in each of the three places env() reads from, or removes it from one when it is null.
     */
    private function setVariable(string $name, ?string $env, ?string $server, ?string $process): void
    {
        if ($env === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $env;
        }

        if ($server === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $server;
        }

        putenv($process === null ? $name : "{$name}={$process}");
    }
}
