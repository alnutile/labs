#!/usr/bin/env bash
set -euo pipefail

KUBECTL="${KUBECTL:-kubectl}"
NAMESPACE=classic-stack
export K3S_CONFIG_FILE="${K3S_CONFIG_FILE:-/dev/null}"
export KUBECONFIG="${KUBECONFIG:-$HOME/.kube/config}"

"$KUBECTL" exec -n "$NAMESPACE" deployment/classic-stack -- curl -fsS http://127.0.0.1/ready >/dev/null
"$KUBECTL" auth can-i create pods -n "$NAMESPACE" --as=system:serviceaccount:classic-stack:classic-stack-agent-broker | grep -qx yes
"$KUBECTL" auth can-i list secrets -n "$NAMESPACE" --as=system:serviceaccount:classic-stack:classic-stack-agent-sandbox | grep -qx no

"$KUBECTL" exec -i -n "$NAMESPACE" deployment/classic-stack -- php <<'PHP'
<?php

$token = getenv('AGENT_BROKER_TOKEN');
$baseUrl = 'http://agent-broker:8010';

function post(string $url, string $token, array $body): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
        'content' => json_encode($body, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'timeout' => 100,
    ]]);
    $response = file_get_contents($url, false, $context);
    $status = isset($http_response_header[0]) ? (int) explode(' ', $http_response_header[0])[1] : 0;
    if ($response === false || $status >= 400) {
        throw new RuntimeException("Broker request failed with HTTP {$status}");
    }

    return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
}

$session = post($baseUrl.'/sessions', $token, [
    'file' => ['name' => 'input.txt', 'content' => base64_encode('uploaded input')],
])['session'];

try {
    $command = <<<'BASH'
set -e
test ! -e /var/run/docker.sock
test ! -e /var/run/secrets/kubernetes.io/serviceaccount/token
test "$(cat input.txt)" = 'uploaded input'
curl -fsS --max-time 20 https://example.com -o output/page.html
test "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 http://169.254.169.254/latest/meta-data/)" = 403
printf 'kubernetes sandbox passed' > output/result.txt
BASH;
    $result = post($baseUrl."/sessions/{$session}/command", $token, ['command' => $command]);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('Sandbox command failed: '.$result['output']);
    }
    $artifacts = post($baseUrl."/sessions/{$session}/artifacts", $token, []);
    $files = array_column($artifacts['files'], null, 'name');
    if (base64_decode($files['result.txt']['content'], true) !== 'kubernetes sandbox passed') {
        throw new RuntimeException('Sandbox artifact verification failed.');
    }
} finally {
    post($baseUrl."/sessions/{$session}/stop", $token, []);
}

fwrite(STDOUT, "PASS: Laravel is ready and the Kubernetes agent sandbox executed an isolated task.\n");
PHP
