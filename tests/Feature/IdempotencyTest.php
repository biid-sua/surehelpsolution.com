<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D52: create endpoints of the mobile API accept an Idempotency-Key, so a retried request after a
 * dropped connection doesn't create the record twice.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_retried_create_returns_the_first_answer_and_creates_nothing_new(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($owner);
        Sanctum::actingAs($owner);
        $headers = ['Idempotency-Key' => 'task-create-0001'];

        $first = $this->postJson('/api/v1/client/tasks', ['title' => 'Send quote'], $headers)->assertCreated();
        $again = $this->postJson('/api/v1/client/tasks', ['title' => 'Send quote'], $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.task.id'), $again->json('data.task.id'));
        $this->assertSame(1, Task::query()->count());

        $this->postJson('/api/v1/client/tasks', ['title' => 'Something else'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/client/tasks', ['title' => 'Bad key'], ['Idempotency-Key' => 'x'])->assertStatus(422);

        // Without a key, or with a new one, it's a new task. Keys are per person.
        $this->postJson('/api/v1/client/tasks', ['title' => 'Send quote'])->assertCreated();
        $this->postJson('/api/v1/client/tasks', ['title' => 'Send quote'], ['Idempotency-Key' => 'task-create-0002'])->assertCreated();
        $this->assertSame(3, Task::query()->count());

        $colleague = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($colleague);
        Sanctum::actingAs($colleague);
        $this->postJson('/api/v1/client/tasks', ['title' => 'Send quote'], $headers)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    }
}
