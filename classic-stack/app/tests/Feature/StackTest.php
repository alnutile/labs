<?php

namespace Tests\Feature;

use App\Jobs\WriteDemoReport;
use App\Models\DemoRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StackTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_must_sign_in(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/demo-runs', ['message' => 'hello'])->assertRedirect('/login');
        $this->get('/horizon')->assertForbidden();
    }

    public function test_registration_uses_fortify_and_hashes_password(): void
    {
        $this->post('/register', ['name' => 'Demo', 'email' => 'demo@example.com', 'password' => 'secret-password', 'password_confirmation' => 'secret-password'])->assertRedirect('/dashboard');
        $user = User::where('email', 'demo@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_dispatch_is_authenticated_validated_and_delayed(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/demo-runs', ['message' => ''])->assertSessionHasErrors('message');
        $this->post('/demo-runs', ['message' => 'A report'])->assertRedirect('/dashboard');
        $run = DemoRun::firstOrFail();
        $this->assertEquals($user->id, $run->user_id);
        $this->assertSame('queued', $run->status);
        Bus::assertDispatched(WriteDemoReport::class, fn ($job) => $job->runId === $run->id && $job->afterCommit && $job->delay !== null);
    }

    public function test_job_writes_a_private_report_and_is_safe_to_retry(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $run = DemoRun::create(['user_id' => $user->id, 'message' => 'A report']);
        $job = new WriteDemoReport($run->id);
        $job->handle();
        $run->refresh();
        $completedAt = $run->completed_at;
        $this->assertSame('completed', $run->status);
        Storage::disk('local')->assertExists($run->file_path);
        $this->assertStringContainsString('A report', Storage::disk('local')->get($run->file_path));
        $this->travel(10)->minutes();
        $job->handle();
        $this->assertTrue($completedAt->equalTo($run->fresh()->completed_at));
        $this->actingAs($user)->get('/demo-runs/'.$run->id.'/download')->assertDownload();
        $this->actingAs(User::factory()->create())->get('/demo-runs/'.$run->id.'/download')->assertForbidden();
        $this->get('/dashboard')->assertDontSee('A report');
    }

    public function test_failed_jobs_update_the_demo_status(): void
    {
        $run = DemoRun::create(['user_id' => User::factory()->create()->id, 'message' => 'Test']);
        (new WriteDemoReport($run->id))->failed(new \RuntimeException('Test failure'));
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_horizon_requires_an_allowlisted_user_outside_local(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/horizon')->assertForbidden();
        config(['stack.horizon_emails' => [$user->email]]);
        $this->get('/horizon')->assertOk();
    }
}
