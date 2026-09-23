<?php

namespace Tests\Feature;

use App\Jobs\RunComputerAgent;
use App\Models\AgentRun;
use App\Models\User;
use App\Services\AgentSandbox;
use App\Services\ComputerAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use Tests\TestCase;

class ComputerAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.enabled' => true, 'agent.api_key' => 'testing', 'agent.broker_token' => 'testing']);
        Storage::fake('local');
    }

    public function test_agent_requires_login_and_is_disabled_in_production(): void
    {
        $this->get('/agent')->assertRedirect('/login');
        $this->post('/agent', ['prompt' => 'Hello'])->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/agent')->assertOk();
        config(['agent.enabled' => false]);
        $this->get('/agent')->assertForbidden();
        config(['agent.enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/agent')->assertForbidden();
        config(['agent.allowed_environments' => ['production']]);
        $this->get('/agent')->assertOk();
    }

    public function test_task_queues_private_input_and_limits_active_runs(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/agent', ['prompt' => ''])->assertSessionHasErrors('prompt');
        $this->post('/agent', ['prompt' => 'Parse this', 'file' => UploadedFile::fake()->create('big.mp4', 20481)])->assertSessionHasErrors('file');
        $this->post('/agent', ['prompt' => 'Count these rows', 'file' => UploadedFile::fake()->createWithContent('data.csv', "a,b\n1,2")])->assertRedirect();
        $run = AgentRun::firstOrFail();
        $this->assertSame($user->id, $run->user_id);
        Storage::disk('local')->assertExists($run->input_path);
        Bus::assertDispatched(RunComputerAgent::class, fn ($job) => $job->runId === $run->id && $job->queue === 'agents' && $job->connection === 'agent' && $job->afterCommit);
        $this->post('/agent', ['prompt' => 'Another task'])->assertSessionHasErrors('prompt');
        $this->assertDatabaseCount('agent_runs', 1);
    }

    public function test_missing_model_key_does_not_queue_a_task(): void
    {
        Bus::fake();
        config(['agent.api_key' => '']);
        $this->actingAs(User::factory()->create())->post('/agent', ['prompt' => 'Hello'])->assertSessionHasErrors('prompt');
        Bus::assertNothingDispatched();
    }

    public function test_results_are_owner_only_and_render_untrusted_output_as_text(): void
    {
        $user = User::factory()->create();
        $run = AgentRun::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'result' => '<script>alert(1)</script>', 'artifacts' => [['path' => 'result', 'name' => 'report.json', 'size' => 2]]]);
        Storage::disk('local')->put('result', '{}');
        $this->actingAs($user)->get('/agent/'.$run->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
        $this->get('/agent/'.$run->id.'/files/0')->assertDownload('report.json');
        $this->get('/agent/'.$run->id.'/files/1')->assertNotFound();
        $run->update(['status' => 'failed']);
        $this->get('/agent/'.$run->id.'/files/0')->assertDownload('report.json');
        $this->actingAs(User::factory()->create())->get('/agent/'.$run->id)->assertForbidden();
        $this->get('/agent/'.$run->id.'/files/0')->assertForbidden();
        $this->get('/agent')->assertDontSee($run->prompt);
    }

    public function test_owner_can_cancel_a_running_task_and_stop_its_sandbox(): void
    {
        Http::fake([
            'agent-broker:8010/sessions/live-session/stop' => Http::response(['stopped' => true]),
        ]);
        $user = User::factory()->create();
        $run = AgentRun::factory()->create([
            'user_id' => $user->id,
            'status' => 'running',
            'session_id' => 'live-session',
        ]);

        $this->actingAs(User::factory()->create())->post(route('agent.cancel', $run))->assertForbidden();
        $this->actingAs($user)->post(route('agent.cancel', $run))->assertRedirect(route('agent.show', $run));

        $run->refresh();
        $this->assertSame('canceled', $run->status);
        $this->assertSame('Canceled by user.', $run->events[0]['text']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/live-session/stop'));
    }

    public function test_neuron_loop_records_shell_output_saves_artifacts_and_cleans_up(): void
    {
        $provider = $this->shellProvider('printf hello > output/hello.txt');
        $agent = \Mockery::mock(ComputerAgent::class)->makePartial();
        $agent->shouldReceive('provider')->andReturn($provider);
        Http::fake([
            'agent-broker:8010/sessions' => Http::response(['session' => 'test-session']),
            'agent-broker:8010/sessions/test-session/command' => Http::response(['exit_code' => 0, 'output' => 'hello']),
            'agent-broker:8010/sessions/test-session/artifacts' => Http::response(['files' => [['name' => 'hello.txt', 'content' => base64_encode('hello')]]]),
            'agent-broker:8010/sessions/test-session/stop' => Http::response(['stopped' => true]),
        ]);
        $run = AgentRun::factory()->create();
        $job = new RunComputerAgent($run->id);
        $job->handle($agent, new AgentSandbox);
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('Done.', $run->result);
        $this->assertSame('command', $run->events[1]['type']);
        $this->assertSame('hello', Storage::disk('local')->get($run->artifacts[0]['path']));
        $provider->assertCallCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/stop'));
        $job->handle($agent, new AgentSandbox);
        Http::assertSentCount(4);
    }

    public function test_failure_marks_run_failed_and_releases_sandbox_without_exposing_provider_errors(): void
    {
        $agent = \Mockery::mock(ComputerAgent::class);
        $agent->shouldReceive('provider')->andReturn(new FakeAIProvider);
        $agent->shouldReceive('run')->andThrow(new \RuntimeException('SECRET_PROVIDER_RESPONSE'));
        $sandbox = \Mockery::mock(AgentSandbox::class);
        $sandbox->shouldReceive('start')->once()->andReturn('session');
        $sandbox->shouldReceive('stop')->with('session')->once();
        $run = AgentRun::factory()->create();
        try {
            (new RunComputerAgent($run->id))->handle($agent, $sandbox);
            $this->fail('Expected execution failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('SECRET_PROVIDER_RESPONSE', $error->getMessage());
        }
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertStringNotContainsString('SECRET_PROVIDER_RESPONSE', $run->fresh()->error);
        $this->assertGreaterThan((new RunComputerAgent($run->id))->timeout, config('queue.connections.agent.retry_after'));
    }

    public function test_real_sandbox_executes_neuron_commands_and_preserves_downloads(): void
    {
        if (! getenv('RUN_AGENT_SANDBOX_TESTS')) {
            $this->markTestSkipped('Opt in after ./scripts/agent up by running RUN_AGENT_SANDBOX_TESTS=1 ./scripts/dev test --filter=real_sandbox.');
        }
        config(['agent.broker_token' => env('AGENT_BROKER_TOKEN')]);
        $command = <<<'BASH'
set -e
test ! -e /var/run/docker.sock
test ! -e /var/www/html/.env
test -z "${AGENT_API_KEY:-}"
test "$(cat input.txt)" = 'uploaded input'
curl -fsS --max-time 20 https://example.com -o output/page.html
python3 -c 'from bs4 import BeautifulSoup; import json; print(json.dumps({"title": BeautifulSoup(open("output/page.html").read(), "html.parser").title.text}))' > output/page.json
ffmpeg -v error -f lavfi -i color=c=blue:s=160x120:d=1 -c:v mpeg4 /workspace/clip.mp4
ffprobe -v quiet -print_format json -show_format /workspace/clip.mp4 > output/video.json
test "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 http://169.254.169.254/latest/meta-data/)" = 403
test "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 http://127.0.0.1/)" = 403
test "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 http://host.docker.internal/)" = 403
ln -s /etc/passwd output/forbidden.txt
printf 'sandbox smoke passed'
BASH;
        $provider = $this->shellProvider($command);
        $agent = \Mockery::mock(ComputerAgent::class)->makePartial();
        $agent->shouldReceive('provider')->andReturn($provider);
        Storage::disk('local')->put('input', 'uploaded input');
        $run = AgentRun::factory()->create(['input_path' => 'input', 'input_name' => 'input.txt']);
        (new RunComputerAgent($run->id))->handle($agent, new AgentSandbox);
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $output = json_decode($run->events[2]['text'], true);
        $this->assertSame(0, $output['exit_code'], $output['output']);
        $this->assertStringContainsString('sandbox smoke passed', $output['output']);
        $files = collect($run->artifacts)->keyBy('name');
        $this->assertFalse($files->has('forbidden.txt'));
        $this->assertStringContainsString('Example Domain', Storage::disk('local')->get($files['page.json']['path']));
        $this->assertStringContainsString('duration', Storage::disk('local')->get($files['video.json']['path']));
    }

    private function shellProvider(string $command): FakeAIProvider
    {
        return new class($command) extends FakeAIProvider
        {
            private int $turn = 0;

            public function __construct(private string $command)
            {
                parent::__construct();
            }

            public function chat(Message ...$messages): Message
            {
                $this->addResponses($this->turn++ === 0
                    ? new ToolCallMessage(null, [array_values($this->tools)[0]->setInputs(['command' => $this->command])->setCallId('shell-test')])
                    : new AssistantMessage('Done.'));

                return parent::chat(...$messages);
            }
        };
    }
}
