<?php
/**
 * Gemini API Backend for Electron
 * Handles requests via command line arguments
 */

// Configuration
$geminiApiKey = 'YOUR_GEMINI_API_KEY_HERE'; // Replace with your Gemini API key
$model = 'gemini-2.5-flash'; 
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

$message = trim($data['message']);

// Conversation history file
$historyFile = __DIR__ . '/conversation_history.txt';
$debugFile = __DIR__ . '/debug.log';

// DEBUG LOG
$debugMsg = date('Y-m-d H:i:s') . " - Electron API called with message: $message\n";
file_put_contents($debugFile, $debugMsg, FILE_APPEND);

// Create file if it doesn't exist
if (!file_exists($historyFile)) {
    file_put_contents($historyFile, '');
    chmod($historyFile, 0666);
    $debugMsg = date('Y-m-d H:i:s') . " - Created history file\n";
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
    } else {
        $debugMsg = date('Y-m-d H:i:s') . " - Course context file is empty\n";
        file_put_contents($debugFile, $debugMsg, FILE_APPEND);
    }
} else {
    $debugMsg = date('Y-m-d H:i:s') . " - No course context file found (this is OK)\n";
    file_put_contents($debugFile, $debugMsg, FILE_APPEND);
}

// API endpoint
$url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$geminiApiKey}";

// Build contents array for Gemini
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
    exit;
}

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
    
    // IMMEDIATELY write Gemini's response to file
    $timestamp = time();
    $modelLine = $timestamp . '|model|' . str_replace(["\r", "\n", '|'], [' ', ' ', ''], $answer) . "\n";
    $writeResult = file_put_contents($historyFile, $modelLine, FILE_APPEND | LOCK_EX);
    
    $debugMsg = date('Y-m-d H:i:s') . " - Wrote model response: " . ($writeResult !== false ? "SUCCESS ($writeResult bytes)" : "FAILED") . "\n";
    file_put_contents($debugFile, $debugMsg, FILE_APPEND);
    
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
