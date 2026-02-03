<?php
/**
 * Clear conversation history
 * Called when user switches between AI providers
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for JSON response
header('Content-Type: application/json');

// Get the JSON input from the request
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!isset($data['provider']) || empty($data['provider'])) {
    echo json_encode([
        'success' => false,
        'error' => 'No provider specified'
    ]);
    exit;
}

$provider = $data['provider'];
$historyFile = __DIR__ . '/conversation_history_' . $provider . '.txt';

// Delete history file if it exists
if (file_exists($historyFile)) {
    if (unlink($historyFile)) {
        echo json_encode([
            'success' => true,
            'message' => 'History cleared successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to clear history'
        ]);
    }
} else {
    echo json_encode([
        'success' => true,
        'message' => 'No history to clear'
    ]);
}
?>
