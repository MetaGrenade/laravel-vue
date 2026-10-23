<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The columns that let an API key be rotated and limited by its owner, on every database CI supports.
 *
 * DDL commits the test's transaction on MySQL, so a test that changes the schema puts it back, and removes what it added, before it ends.
 */
class PersonalAccessTokenRotationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_27_000000_add_rotation_and_limit_owner_to_personal_access_tokens_table.php');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertKey(User $user, string $name, array $attributes = []): int
    {
        return DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => $name,
            'token' => hash('sha256', $name),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    #[Test]
    public function the_columns_roll_back_and_forward_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('personal_access_tokens', ['rotated_at', 'limits_set_by_staff_at']));

        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'rotated_at'));
        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'limits_set_by_staff_at'));
        $this->assertTrue(Schema::hasColumns('personal_access_tokens', ['hourly_quota', 'daily_quota', 'revoked_at']), 'what was there before is left alone');

        $this->migration()->up();
        $this->assertTrue(Schema::hasColumns('personal_access_tokens', ['rotated_at', 'limits_set_by_staff_at']));
    }

    #[Test]
    public function a_database_that_already_has_limits_treats_them_as_staffs(): void
    {
        $user = User::factory()->create();

        // An installation from before the migration: only staff could set a limit, so any limit that exists is theirs.
        $this->migration()->down();

        $hourly = $this->insertKey($user, 'hourly', ['hourly_quota' => 100]);
        $daily = $this->insertKey($user, 'daily', ['daily_quota' => 1000]);
        $both = $this->insertKey($user, 'both', ['hourly_quota' => 5, 'daily_quota' => 50]);
        $none = $this->insertKey($user, 'none');

        try {
            $this->migration()->up();

            $locked = fn (int $id) => DB::table('personal_access_tokens')->where('id', $id)->value('limits_set_by_staff_at');

            $this->assertNotNull($locked($hourly));
            $this->assertNotNull($locked($daily));
            $this->assertNotNull($locked($both));
            $this->assertNull($locked($none), 'a key with no limit is the owner\'s to limit');
            $this->assertNull(DB::table('personal_access_tokens')->whereNotNull('rotated_at')->first(), 'nothing has been rotated');
        } finally {
            if (! Schema::hasColumn('personal_access_tokens', 'rotated_at')) {
                $this->migration()->up();
            }

            DB::table('personal_access_tokens')->whereIn('id', [$hourly, $daily, $both, $none])->delete();
        }
    }
}
