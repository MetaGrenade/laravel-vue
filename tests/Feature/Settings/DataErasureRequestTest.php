<?php

namespace Tests\Feature\Settings;

use App\Models\DataErasureRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataErasureRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_submit_erasure_request(): void
    {
        $this->post(route('privacy.erasure.store'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_submit_erasure_request(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('privacy.erasure.store'));

        $response->assertRedirect(route('privacy.index'));

        $this->assertDatabaseHas('data_erasure_requests', [
            'user_id' => $user->id,
            'status' => DataErasureRequest::STATUS_PENDING,
        ]);
    }

    public function test_duplicate_erasure_requests_are_blocked(): void
    {
        $user = User::factory()->create();

        $user->dataErasureRequests()->create([
            'status' => DataErasureRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->post(route('privacy.erasure.store'));

        $response->assertRedirect(route('privacy.index'));
        $response->assertSessionHasErrors('erasure');
    }
}
