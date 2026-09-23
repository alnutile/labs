<?php

namespace App\Http\Controllers;

use App\Jobs\WriteDemoReport;
use App\Models\DemoRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DemoRunController extends Controller
{
    public function index(Request $request)
    {
        return view('dashboard', ['runs' => DemoRun::where('user_id', $request->user()->id)->latest()->limit(20)->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:12000']]);
        DB::transaction(function () use ($request, $data) {
            $run = DemoRun::create($data + ['user_id' => $request->user()->id]);
            WriteDemoReport::dispatch($run->id)->afterCommit()->delay(now()->addSeconds(5));
        });

        return to_route('dashboard')->with('status', 'Job queued. Refresh in a few seconds to see the report.');
    }

    public function download(Request $request, DemoRun $run)
    {
        abort_unless($run->user_id === $request->user()->id, 403);
        abort_unless($run->status === 'completed' && $run->file_path, 404);

        return Storage::disk('local')->download($run->file_path);
    }
}
