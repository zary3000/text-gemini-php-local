<?php
/**
 * Gemini API Backend
 * Handles requests from the HTML chat interface
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for JSON response
header('Content-Type: application/json');

// Configuration
$geminiApiKey = 'YOUR_GEMINI_API_KEY_HERE'; // Replace with your Gemini API key
$model = 'gemini-2.0-flash-exp';
$courseContextFile = __DIR__ . '/course_context.txt'; // Optional course information

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

$message = trim($data['message']);

// Conversation history file
$historyFile = __DIR__ . '/conversation_history.txt';

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
    }
}

// API endpoint
$url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$geminiApiKey}";

// Build conversation contents from history
$contents = [];

// ALWAYS prepend course context to the first user message in history
if (!empty($courseContext) && !empty($history)) {
    $firstMsg = array_shift($history); // Remove first message from history
    $contents[] = [
        'role' => 'user',
        'parts' => [
            ['text' => $courseContext . "\n\n" . $firstMsg['text']]
        ]
    ];
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

// Prepare the request payload with full conversation history
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
    echo json_encode([
        'success' => false,
        'error' => 'cURL Error: ' . curl_error($ch)
    ]);
    curl_close($ch);
    exit;
}

curl_close($ch);

// Check HTTP response code
if ($httpCode !== 200) {
    $errorData = json_decode($response, true);
    $errorMessage = isset($errorData['error']['message']) 
        ? $errorData['error']['message'] 
        : 'Unknown API error';
    
    echo json_encode([
        'success' => false,
        'error' => "API Error (HTTP {$httpCode}): {$errorMessage}"
    ]);
    exit;
}

// Parse the response
$responseData = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'success' => false,
        'error' => 'JSON Parse Error: ' . json_last_error_msg()
    ]);
    exit;
}

// Extract and return the text response
if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
    $answer = $responseData['candidates'][0]['content']['parts'][0]['text'];
    
    // Add Gemini's response to history
    $timestamp = date('Y-m-d H:i:s');
    $history[] = [
        'timestamp' => $timestamp,
        'role' => 'model',
        'text' => $answer
    ];
    
    // Reconstruct full history (including the first message with course context)
    // We need to save it without the course context prepended
    $allHistory = [];
    
    // Re-read the file to get the original structure
    if (file_exists($historyFile)) {
        $content = file_get_contents($historyFile);
        if (!empty($content)) {
            $lines = explode("\n", trim($content));
            foreach ($lines as $line) {
                if (empty($line)) continue;
                $parts = explode('|', $line, 3);
                if (count($parts) === 3) {
                    $allHistory[] = [
                        'timestamp' => $parts[0],
                        'role' => $parts[1],
                        'text' => $parts[2]
                    ];
                }
            }
        }
    }
    
    // Add the new user message (if not already there)
    $userMessageExists = false;
    foreach ($allHistory as $msg) {
        if ($msg['role'] === 'user' && $msg['text'] === $message) {
            $userMessageExists = true;
            break;
        }
    }
    
    if (!$userMessageExists) {
        $allHistory[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'role' => 'user',
            'text' => $message
        ];
    }
    
    // Add the model's response
    $allHistory[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'role' => 'model',
        'text' => $answer
    ];
    
    // Save updated history to file
    $lines = [];
    foreach ($allHistory as $msg) {
        $lines[] = $msg['timestamp'] . '|' . $msg['role'] . '|' . $msg['text'];
    }
    file_put_contents($historyFile, implode("\n", $lines) . "\n");
    
    echo json_encode([
        'success' => true,
        'response' => $answer
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Unexpected response structure from Gemini API'
    ]);
}
?>
