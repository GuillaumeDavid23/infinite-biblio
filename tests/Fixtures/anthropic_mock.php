<?php
// Faux serveur de l'API Anthropic pour les tests (php -S). Aucun appel réseau réel.
// Le comportement dépend du modèle demandé : « mock-401 », « mock-529 », « mock-truncated », sinon succès.
$body = (string) file_get_contents('php://input');
$data = json_decode($body, true) ?: [];
file_put_contents(sys_get_temp_dir().'/anthropic_mock_last.json', json_encode(['uri' => $_SERVER['REQUEST_URI'], 'headers' => getallheaders(), 'body' => $data]));
header('Content-Type: application/json');

$model = $data['model'] ?? '';
if ('mock-401' === $model) {
    http_response_code(401);
    echo '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}';
    return;
}
if ('mock-529' === $model) {
    http_response_code(529);
    echo '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}';
    return;
}
if (str_contains($_SERVER['REQUEST_URI'], 'count_tokens')) {
    echo '{"input_tokens": 1234}';
    return;
}
echo json_encode([
    'id' => 'msg_mock', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
    'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => '{"summary":"ok","issues":[]}']],
    'stop_reason' => 'mock-truncated' === $model ? 'max_tokens' : 'end_turn', 'stop_sequence' => null, 'stop_details' => null,
    'usage' => [
        'input_tokens' => 1200, 'output_tokens' => 345, 'cache_creation_input_tokens' => 10, 'cache_read_input_tokens' => 20,
        'cache_creation' => ['ephemeral_5m_input_tokens' => 10, 'ephemeral_1h_input_tokens' => 0],
        'service_tier' => 'standard', 'inference_geo' => null, 'server_tool_use' => null, 'output_tokens_details' => null,
    ],
]);
