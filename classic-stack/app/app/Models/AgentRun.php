<?php

namespace App\Models;

use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'prompt', 'status', 'input_path', 'input_name', 'session_id', 'events', 'artifacts', 'result', 'error', 'finished_at'];

    protected function casts(): array
    {
        return ['events' => 'array', 'artifacts' => 'array', 'finished_at' => 'datetime'];
    }

    public function recordEvent(string $type, string $text): void
    {
        $events = $this->fresh()->events ?? [];
        $events[] = ['type' => $type, 'text' => mb_substr($text, 0, 20000), 'at' => now()->toIso8601String()];
        $this->update(['events' => $events]);
    }
}
