<?php

return [
    'enabled' => env('AGENT_ENABLED', false),
    'provider' => env('AGENT_PROVIDER', 'openai'),
    'model' => env('AGENT_MODEL', 'gpt-5.2'),
    'api_key' => env('AGENT_API_KEY') ?: env('OPENAI_API_KEY', ''),
    'ollama_url' => env('AGENT_OLLAMA_URL', 'http://host.docker.internal:11434'),
    'broker_url' => env('AGENT_BROKER_URL', 'http://agent-broker:8010'),
    'broker_token' => env('AGENT_BROKER_TOKEN', ''),
];
