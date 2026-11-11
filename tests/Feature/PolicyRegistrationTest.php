<?php

namespace Tests\Feature;

use App\Models\SupportTicketMessageAttachment;
use App\Policies\SupportTicketMessageAttachmentPolicy;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `Gate::policy()` in `AppServiceProvider` is written with class names, and a name with no `use` line is read as a class next to the
 * provider that does not exist. Laravel finds `App\Policies\{Model}Policy` by itself, so a policy registered like that still worked,
 * and the line was wrong for as long as nobody looked. A registration must name real classes.
 */
class PolicyRegistrationTest extends TestCase
{
    #[Test]
    public function every_registered_policy_names_a_model_and_a_policy_that_exist(): void
    {
        $policies = Gate::policies();

        $this->assertNotEmpty($policies);

        foreach ($policies as $model => $policy) {
            $this->assertTrue(class_exists($model), "Gate::policy() is given the model `{$model}`, which does not exist (a missing `use` line?).");
            $this->assertTrue(class_exists($policy), "Gate::policy() is given the policy `{$policy}`, which does not exist (a missing `use` line?).");
        }
    }

    #[Test]
    public function the_support_attachment_policy_is_registered_for_its_model(): void
    {
        $this->assertSame(SupportTicketMessageAttachmentPolicy::class, Gate::policies()[SupportTicketMessageAttachment::class] ?? null);
    }
}
