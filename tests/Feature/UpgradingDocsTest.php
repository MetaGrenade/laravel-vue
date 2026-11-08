<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * docs/upgrading.md is read by somebody who is about to change a live site, and who types what it says. A command that does not exist
 * (renamed, removed) or an option it does not take stops them half way, and a wrong account of what the role seeder does is how a
 * stranger becomes an administrator.
 */
class UpgradingDocsTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'docs/upgrading.md';

    #[Test]
    public function every_artisan_command_the_guide_names_exists_and_takes_the_options_it_is_given(): void
    {
        $registered = Artisan::all();
        $found = 0;
        $wrong = [];

        foreach (explode("\n", $this->page()) as $number => $line) {
            preg_match_all('/php artisan ([a-z][a-z0-9:\-]*)([^`#]*)/', $line, $calls, PREG_SET_ORDER);

            foreach ($calls as [, $name, $rest]) {
                $found++;

                if (! isset($registered[$name])) {
                    $wrong[] = 'line '.($number + 1).": `php artisan {$name}` is not a command of this site";

                    continue;
                }

                preg_match_all('/(?<![\w-])--([a-z][a-z-]*)/', $rest, $options);

                foreach ($options[1] as $option) {
                    if (! $registered[$name]->getDefinition()->hasOption($option)) {
                        $wrong[] = 'line '.($number + 1).": `{$name}` has no option --{$option}";
                    }
                }
            }
        }

        $this->assertGreaterThan(15, $found, 'The test found hardly any commands in the guide, so it is not reading it.');
        $this->assertSame([], $wrong, "docs/upgrading.md names commands or options that do not exist:\n  ".implode("\n  ", $wrong));
    }

    #[Test]
    public function every_metaforger_command_the_guide_names_exists(): void
    {
        $registered = array_keys(Artisan::all());

        preg_match_all('/`(metaforger:[a-z:\-]+)/', $this->page(), $names);

        $missing = array_values(array_diff(array_unique($names[1]), $registered));

        $this->assertGreaterThanOrEqual(6, count(array_unique($names[1])), 'The guide lists the six renamed commands.');
        $this->assertSame([], $missing, 'docs/upgrading.md names commands that do not exist: '.implode(', ', $missing));
    }

    #[Test]
    public function the_permissions_the_guide_says_the_seeder_gives_are_the_ones_it_gives(): void
    {
        $line = collect(explode("\n", $this->page()))->first(fn (string $line): bool => str_contains($line, 'It gives `editor`'));

        $this->assertNotNull($line, 'The guide no longer has the line that says what the seeder gives editor and moderator.');

        preg_match_all('/`([a-z_]+\.acp\.[a-z_]+)`/', $line, $listed);

        $this->seed(RolePermissionSeeder::class);

        $moderator = Role::findByName('moderator')->permissions->pluck('name')->sort()->values()->all();
        $editor = Role::findByName('editor')->permissions->pluck('name')->all();

        $this->assertSame(collect($listed[1])->sort()->values()->all(), $moderator, 'The guide lists other permissions for the moderator than the seeder gives.');
        $this->assertCount(7, $moderator, 'The guide says "the seven".');
        $this->assertCount(3, $editor, 'The guide says "the three poll permissions".');
        $this->assertSame([], array_values(array_filter($editor, fn (string $name): bool => ! str_starts_with($name, 'polls.acp.'))));
    }

    #[Test]
    public function the_seeder_does_what_the_guide_warns_it_does(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // "It never takes a permission away, but it puts back one you took away from those two roles."
        $moderator = Role::findByName('moderator');
        $moderator->revokePermissionTo('users.acp.ban');
        $moderator->givePermissionTo(Permission::findByName('blogs.acp.view'));

        // "It gives the admin role to the member with id 1 when that member does not have it."
        $member = User::factory()->create(['id' => 1]);

        $this->assertFalse($member->hasRole('admin'));

        $this->seed(RolePermissionSeeder::class);

        $moderator = Role::findByName('moderator');

        $this->assertTrue($moderator->hasPermissionTo('users.acp.ban'), 'The guide says the seeder puts back a permission taken from the moderator.');
        $this->assertTrue($moderator->hasPermissionTo('blogs.acp.view'), 'The guide says the seeder never takes a permission away.');
        $this->assertTrue($member->fresh()->hasRole('admin'), 'The guide says the seeder makes member 1 an administrator.');
    }

    private function page(): string
    {
        return (string) file_get_contents(base_path(self::PAGE));
    }
}
