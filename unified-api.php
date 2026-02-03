<?php
/**
 * Unified API Backend
 * Routes requests to either OpenAI or Gemini based on user selection
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for JSON response
header('Content-Type: application/json');

// Configuration
$openaiApiKey = 'YOUR_OPENAI_API_KEY_HERE'; // Replace with your OpenAI API key
$geminiApiKey = 'YOUR_GEMINI_API_KEY_HERE'; // Replace with your Gemini API key

// Get the JSON input from the request
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!isset($data['message']) || empty(trim($data['message']))) {
    echo json_encode([
        'success' => false,
        'error' => 'No message provided'
    ]);
    exit;
}

if (!isset($data['provider']) || empty($data['provider'])) {
    echo json_encode([
        'success' => false,
        'error' => 'No provider specified'
    ]);
    exit;
}

$message = trim($data['message']);
$provider = $data['provider'];

// Determine history file based on provider
$historyFile = __DIR__ . '/conversation_history_' . $provider . '.txt';

// Read existing conversation history
$history = [];
if (file_exists($historyFile)) {
    $content = file_get_contents($historyFile);
    if (!empty($content)) {
        $lines = explode("\n", trim($content));
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $parts = explode('|', $line, 3); // timestamp|role|text
            if (count($parts) === 3) {
                $history[] = [
                    'timestamp' => $parts[0],
                    'role' => $parts[1],
                    'text' => $parts[2]
                ];
            }
        }
    }
}

// Add current user message to history
$timestamp = date('Y-m-d H:i:s');
$history[] = [
    'timestamp' => $timestamp,
    'role' => 'user',
    'text' => $message
];

// Route to appropriate API
if ($provider === 'openai') {
    $response = callOpenAI($openaiApiKey, $history);
} else if ($provider === 'gemini') {
    $response = callGemini($geminiApiKey, $history);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid provider specified'
    ]);
    exit;
}

// If successful, save history
if ($response['success']) {
    $timestamp = date('Y-m-d H:i:s');
    $history[] = [
        'timestamp' => $timestamp,
        'role' => 'assistant',
        'text' => $response['response']
    ];
    
    // Save updated history to file
    $lines = [];
    foreach ($history as $msg) {
        $lines[] = $msg['timestamp'] . '|' . $msg['role'] . '|' . $msg['text'];
    }
    file_put_contents($historyFile, implode("\n", $lines) . "\n");
}

echo json_encode($response);

/**
 * Call OpenAI API
 */
function callOpenAI($apiKey, $history) {
    $url = 'https://api.openai.com/v1/chat/completions';
    
    // Build messages array for OpenAI
    $messages = [];
    foreach ($history as $msg) {
        $role = $msg['role'] === 'assistant' ? 'assistant' : 'user';
        $messages[] = [
            'role' => $role,
            'content' => $msg['text']
        ];
    }
    
    $payload = [
        'model' => 'gpt-4o-mini', // You can change this to gpt-4, gpt-3.5-turbo, etc.
        'messages' => $messages,
        'temperature' => 0.7
    ];
    
    // Initialize cURL
    $ch = curl_init($url);
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Check for cURL errors
    if (curl_errno($ch)) {
        return [
            'success' => false,
            'error' => 'cURL Error: ' . curl_error($ch)
        ];
    }
    
    curl_close($ch);
    
    // Check HTTP response code
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = isset($errorData['error']['message']) 
            ? $errorData['error']['message'] 
            : 'Unknown API error';
        
        return [
            'success' => false,
            'error' => "OpenAI API Error (HTTP {$httpCode}): {$errorMessage}"
        ];
    }
    
    // Parse the response
    $responseData = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'JSON Parse Error: ' . json_last_error_msg()
        ];
    }
    
    // Extract the response
    if (isset($responseData['choices'][0]['message']['content'])) {
        return [
            'success' => true,
            'response' => $responseData['choices'][0]['message']['content']
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Unexpected response structure from OpenAI API'
        ];
    }
}

/**
 * Call Gemini API
 */
function callGemini($apiKey, $history) {
    $model = 'gemini-2.0-flash-exp';
    $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apiKey}";
    
    // Build contents array for Gemini
    $contents = [];
    foreach ($history as $msg) {
        $role = ($msg['role'] === 'user') ? 'user' : 'model';
        $contents[] = [
            'role' => $role,
            'parts' => [
                ['text' => $msg['text']]
            ]
        ];
    }
    
    $payload = [
        'contents' => $contents
    ];
    
    // Initialize cURL
    $ch = curl_init($url);
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Check for cURL errors
    if (curl_errno($ch)) {
        return [
            'success' => false,
            'error' => 'cURL Error: ' . curl_error($ch)
        ];
    }
    
    curl_close($ch);
    
    // Check HTTP response code
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = isset($errorData['error']['message']) 
            ? $errorData['error']['message'] 
            : 'Unknown API error';
        
        return [
            'success' => false,
            'error' => "Gemini API Error (HTTP {$httpCode}): {$errorMessage}"
        ];
    }
    
    // Parse the response
    $responseData = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'JSON Parse Error: ' . json_last_error_msg()
        ];
    }
    
    // Extract the response
    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        return [
            'success' => true,
            'response' => $responseData['candidates'][0]['content']['parts'][0]['text']
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Unexpected response structure from Gemini API'
        ];
    }
}
?>
