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
$apiKey = 'YOUR_API_KEY_HERE'; // Replace with your API key
$model = 'gemini-2.5-flash';

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

$question = trim($data['message']);

// API endpoint
$url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apiKey}";

// Prepare the request payload
$payload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $question]
            ]
        ]
    ]
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
