<?php
/**
 * Unified API Backend for Electron
 * Routes requests to either OpenAI or Gemini based on user selection
 * Handles requests via command line arguments
 */

// Configuration
$openaiApiKey = 'YOUR_OPENAI_API_KEY_HERE'; // Replace with your OpenAI API key
$geminiApiKey = 'YOUR_GEMINI_API_KEY_HERE'; // Replace with your Gemini API key
$courseContextFile = __DIR__ . '/course_context.txt'; // Course information in plain text

// Get input from file
if ($argc < 2) {
    echo json_encode([
        'success' => false,
        'error' => 'No input file provided'
    ]);
    exit;
}

$inputFile = $argv[1];
if (!file_exists($inputFile)) {
    echo json_encode([
        'success' => false,
        'error' => 'Input file not found'
    ]);
    exit;
}

$input = file_get_contents($inputFile);
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
$debugFile = __DIR__ . '/debug.log';

// DEBUG LOG
$debugMsg = date('Y-m-d H:i:s') . " - Electron API called with message: $message (Provider: $provider)\n";
file_put_contents($debugFile, $debugMsg, FILE_APPEND);

// Create file if it doesn't exist
if (!file_exists($historyFile)) {
    file_put_contents($historyFile, '');
    chmod($historyFile, 0666);
    $debugMsg = date('Y-m-d H:i:s') . " - Created history file for $provider\n";
    file_put_contents($debugFile, $debugMsg, FILE_APPEND);
}

// IMMEDIATELY write user message to file
$timestamp = time();
$userLine = $timestamp . '|user|' . str_replace(["\r", "\n", '|'], ['', '', ''], $message) . "\n";
$writeResult = file_put_contents($historyFile, $userLine, FILE_APPEND | LOCK_EX);

$debugMsg = date('Y-m-d H:i:s') . " - Wrote user message: " . ($writeResult !== false ? "SUCCESS ($writeResult bytes)" : "FAILED") . "\n";
file_put_contents($debugFile, $debugMsg, FILE_APPEND);

// Read existing conversation history
$history = [];
if (file_exists($historyFile)) {
    $content = file_get_contents($historyFile);
    if (!empty($content)) {
        $lines = explode("\n", trim($content));
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $parts = explode('|', $line, 3);
            if (count($parts) === 3) {
                $history[] = [
                    'role' => $parts[1],
                    'text' => $parts[2]
                ];
            }
        }
    }
}

$debugMsg = date('Y-m-d H:i:s') . " - Loaded " . count($history) . " messages from history\n";
file_put_contents($debugFile, $debugMsg, FILE_APPEND);

// Load course context from text file if it exists
$courseContext = '';
if (file_exists($courseContextFile)) {
    $contextContent = file_get_contents($courseContextFile);
    
    if (!empty(trim($contextContent))) {
        // Limit context to ~8000 characters to avoid token limits
        if (strlen($contextContent) > 8000) {
            $contextContent = substr($contextContent, 0, 8000) . '... [Context truncated]';
        }
        
        $courseContext = "COURSE INFORMATION:\n" . trim($contextContent) . "\n\nUse this information to answer questions about the course.\n\n";
        
        $debugMsg = date('Y-m-d H:i:s') . " - Course context loaded: " . strlen($contextContent) . " characters\n";
        file_put_contents($debugFile, $debugMsg, FILE_APPEND);
    }
}

// Route to appropriate API
if ($provider === 'openai') {
    $response = callOpenAI($openaiApiKey, $history, $courseContext);
} else if ($provider === 'gemini') {
    $response = callGemini($geminiApiKey, $history, $courseContext);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid provider specified'
    ]);
    exit;
}

// If successful, save the assistant's response to history
if ($response['success']) {
    $timestamp = time();
    $modelLine = $timestamp . '|assistant|' . str_replace(["\r", "\n", '|'], [' ', ' ', ''], $response['response']) . "\n";
    $writeResult = file_put_contents($historyFile, $modelLine, FILE_APPEND | LOCK_EX);
    
    $debugMsg = date('Y-m-d H:i:s') . " - Wrote assistant response: " . ($writeResult !== false ? "SUCCESS ($writeResult bytes)" : "FAILED") . "\n";
    file_put_contents($debugFile, $debugMsg, FILE_APPEND);
}

echo json_encode($response);

/**
 * Call OpenAI API
 */
function callOpenAI($apiKey, $history, $courseContext) {
    $url = 'https://api.openai.com/v1/chat/completions';
    
    // Build messages array for OpenAI
    $messages = [];
    
    // Add course context as system message if available and this is the first message
    if (!empty($courseContext) && count($history) === 1) {
        $messages[] = [
            'role' => 'system',
            'content' => $courseContext
        ];
    }
    
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
function callGemini($apiKey, $history, $courseContext) {
    $model = 'gemini-2.0-flash-exp';
    $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apiKey}";
    
    // Build contents array for Gemini
    $contents = [];
    
    // If course context exists and this is the first message, add it
    if (!empty($courseContext) && count($history) === 1) {
        // Add course context with the first user message
        $contents[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $courseContext . "\n\n" . $history[0]['text']]
            ]
        ];
        array_shift($history); // Remove the first message since we already added it with context
    }
    
    // Add remaining history
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
