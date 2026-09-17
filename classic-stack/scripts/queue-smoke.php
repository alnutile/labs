<?php
// Integration check: a separate Horizon process must consume this real Redis job.
use App\Jobs\WriteDemoReport;
use App\Models\DemoRun;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

$appPath = getenv('STACK_APP_PATH') ?: __DIR__.'/../app';
require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('queue.default') !== 'redis') {
    throw new RuntimeException('This check requires QUEUE_CONNECTION=redis.');
}
$user = User::factory()->create();
$run = DemoRun::create(['user_id' => $user->id, 'message' => 'Redis → Horizon → PostgreSQL → file']);
try {
    WriteDemoReport::dispatch($run->id);
    for ($attempt = 0; $attempt < 40; $attempt++) {
        usleep(500000);
        $run->refresh();
        if ($run->status === 'completed') {
            if (! Storage::disk('local')->exists($run->file_path)) {
                throw new RuntimeException('Job completed but its report is missing.');
            }
            echo "PASS: Horizon processed the Redis job, updated PostgreSQL, and wrote the report.\n";
            break;
        }
    }
    if ($run->status !== 'completed') {
        throw new RuntimeException('Horizon did not complete the job within 20 seconds.');
    }
} finally {
    if ($run->file_path) {
        Storage::disk('local')->delete($run->file_path);
    }
    $user->delete();
}
