<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function get_csrf_max_age(): int {
    $configured = getenv('CSRF_MAX_AGE');
    if ($configured === false || trim((string) $configured) === '') {
        return 86400;
    }

    $value = (int) $configured;
    return $value > 0 ? $value : 86400;
}

/**
 * @return string The CSRF token
 */
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) || (time() - $_SESSION['csrf_token_time'] > get_csrf_max_age())) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token from a request
 * @param string $token The token to validate
 * @param int|null $max_age Maximum age of token in seconds. Defaults to the configured lifetime.
 * @return bool True if valid, false otherwise
 */
function validate_csrf_token($token, $max_age = null) {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }

    $effectiveMaxAge = $max_age ?? get_csrf_max_age();

    // Check if token has expired
    if (time() - $_SESSION['csrf_token_time'] > $effectiveMaxAge) {
        unset($_SESSION['csrf_token']);
        unset($_SESSION['csrf_token_time']);
        return false;
    }

    // Use hash_equals to prevent timing attacks
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Regenerate the CSRF token (use after successful form submission)
 */
function regenerate_csrf_token() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
    return $_SESSION['csrf_token'];
}

/**
 * Get the CSRF token as an HTML hidden input field
 * @return string HTML input field with CSRF token
 */
function get_csrf_input() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * @return string|null
 */
function get_request_csrf_token() {
    // 1. Header - preferred, works for POST/PUT/DELETE/PATCH with any body type
    $headerToken = null;
    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'X-CSRF-Token') === 0) {
                $headerToken = $value;
                break;
            }
        }
    }
    if (is_string($headerToken) && $headerToken !== '') {
        return trim($headerToken);
    }

    // 2. Classic form POST
    if (isset($_POST['csrf_token']) && $_POST['csrf_token'] !== '') {
        return (string) $_POST['csrf_token'];
    }

    // 3. Query string / request payload fallback
    if (isset($_GET['csrf_token']) && $_GET['csrf_token'] !== '') {
        return (string) $_GET['csrf_token'];
    }
    if (isset($_REQUEST['csrf_token']) && $_REQUEST['csrf_token'] !== '') {
        return (string) $_REQUEST['csrf_token'];
    }

    // 4. JSON body. php://input can only be read once per request in some
    //    SAPIs, so cache it for the API handlers that read it again later.
    static $jsonToken = null;
    static $jsonRead  = false;
    if (!$jsonRead) {
        $jsonRead = true;
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $GLOBALS['__RAW_REQUEST_BODY'] = $raw;
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['csrf_token']) && $data['csrf_token'] !== '') {
                $jsonToken = (string) $data['csrf_token'];
            }
        }
    }
    return $jsonToken;
}

/**
 * Validate CSRF token from POST/PUT/DELETE request
 * Exits with a JSON error response if invalid
 */
function require_csrf_token() {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        return;
    }

    $token = get_request_csrf_token();

    if (!$token || !validate_csrf_token($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Your session security token has expired. Please refresh the page and try again.'
        ]);
        exit;
    }

}

