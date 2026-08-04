<?php
// Server-side Validation Utility
// Provides comprehensive validation functions for API endpoints

/**
 * Validate email format
 * @param string $email Email to validate
 * @return bool True if valid, false otherwise
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (basic format)
 * @param string $phone Phone number to validate
 * @return bool True if valid, false otherwise
 */
function validate_phone($phone) {
    // Allow digits, spaces, hyphens, plus, parentheses
    return preg_match('/^[\d\s\-\+\(\)]{10,20}$/', $phone) === 1;
}

/**
 * Validate Rwanda phone number (strict format)
 * Must be exactly 10 digits, numbers only, starting with 079, 078, 072, or 073
 * @param string $phone Phone number to validate
 * @return bool True if valid, false otherwise
 */
function validate_phone_rwanda($phone) {
    // Remove any non-digit characters first
    $digits = preg_replace('/[^0-9]/', '', $phone);
    // Must be exactly 10 digits and start with valid prefix
    return preg_match('/^(079|078|072|073)[0-9]{7}$/', $digits) === 1;
}

/**
 * Validate numeric value with optional min/max
 * @param mixed $value Value to validate
 * @param float|null $min Minimum value (optional)
 * @param float|null $max Maximum value (optional)
 * @return bool True if valid, false otherwise
 */
function validate_numeric($value, $min = null, $max = null) {
    if (!is_numeric($value)) {
        return false;
    }
    $num = floatval($value);
    if ($min !== null && $num < $min) {
        return false;
    }
    if ($max !== null && $num > $max) {
        return false;
    }
    return true;
}

/**
 * Validate integer value
 * @param mixed $value Value to validate
 * @param int|null $min Minimum value (optional)
 * @param int|null $max Maximum value (optional)
 * @return bool True if valid, false otherwise
 */
function validate_integer($value, $min = null, $max = null) {
    if (!is_numeric($value) || $value != (int)$value) {
        return false;
    }
    $num = intval($value);
    if ($min !== null && $num < $min) {
        return false;
    }
    if ($max !== null && $num > $max) {
        return false;
    }
    return true;
}

/**
 * Validate date format (YYYY-MM-DD)
 * @param string $date Date to validate
 * @return bool True if valid, false otherwise
 */
function validate_date($date) {
    return DateTime::createFromFormat('Y-m-d', $date) !== false;
}

/**
 * Validate date is not in the future (for past/current date fields)
 * @param string $date Date to validate (YYYY-MM-DD format)
 * @return bool True if valid (not future), false otherwise
 */
function validate_date_not_future($date) {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) return false;
    $today = new DateTime();
    return $dt <= $today;
}

/**
 * Validate date is not in the past (for future date fields)
 * @param string $date Date to validate (YYYY-MM-DD format)
 * @return bool True if valid (not past), false otherwise
 */
function validate_date_not_past($date) {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) return false;
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    return $dt >= $today;
}

/**
 * Validate date range (start date must be before or equal to end date)
 * @param string $startDate Start date (YYYY-MM-DD format)
 * @param string $endDate End date (YYYY-MM-DD format)
 * @return bool True if valid, false otherwise
 */
function validate_date_range($startDate, $endDate) {
    $start = DateTime::createFromFormat('Y-m-d', $startDate);
    $end = DateTime::createFromFormat('Y-m-d', $endDate);
    if (!$start || !$end) return false;
    return $start <= $end;
}

/**
 * Validate string length
 * @param string $value String to validate
 * @param int|null $min Minimum length (optional)
 * @param int|null $max Maximum length (optional)
 * @return bool True if valid, false otherwise
 */
function validate_string_length($value, $min = null, $max = null) {
    if (!is_string($value)) {
        return false;
    }
    $length = strlen($value);
    if ($min !== null && $length < $min) {
        return false;
    }
    if ($max !== null && $length > $max) {
        return false;
    }
    return true;
}

/**
 * Sanitize string input (remove HTML tags, trim whitespace)
 * @param string $value String to sanitize
 * @return string Sanitized string
 */
function sanitize_string($value) {
    return trim(strip_tags($value));
}

/**
 * Validate required field
 * @param mixed $value Value to check
 * @return bool True if not empty, false otherwise
 */
function validate_required($value) {
    if (is_string($value)) {
        return trim($value) !== '';
    }
    if (is_array($value)) {
        return !empty($value);
    }
    return !is_null($value) && $value !== '';
}

/**
 * Validate enum value against allowed values
 * @param string $value Value to validate
 * @param array $allowedValues Array of allowed values
 * @return bool True if valid, false otherwise
 */
function validate_enum($value, $allowedValues) {
    return in_array($value, $allowedValues, true);
}

/**
 * Check for duplicate record in database
 * @param PDO $pdo Database connection
 * @param string $table Table name
 * @param string $column Column name
 * @param mixed $value Value to check
 * @param int|null $excludeId ID to exclude from check (for updates)
 * @param string $idColumn Primary key column name (default: 'id')
 * @return bool True if duplicate exists, false otherwise
 */
function check_duplicate(PDO $pdo, $table, $column, $value, $excludeId = null, $idColumn = 'id') {
    // Whitelist allowed tables and columns for security
    $allowedTables = [
        'users' => ['Username', 'Email'],
        'customers' => ['Email'],
        'suppliers' => ['CompanyName', 'Email'],
        'categories' => ['CategoryName'],
        'mechanics' => ['Email']
    ];
    
    // Validate table name
    if (!array_key_exists($table, $allowedTables)) {
        throw new InvalidArgumentException("Invalid table name: {$table}");
    }
    
    // Validate column name
    if (!in_array($column, $allowedTables[$table], true)) {
        throw new InvalidArgumentException("Invalid column name: {$column} for table: {$table}");
    }
    
    // Validate ID column name (prevent SQL injection)
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $idColumn)) {
        throw new InvalidArgumentException("Invalid ID column name: {$idColumn}");
    }
    
    $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
    $params = [$value];
    
    if ($excludeId !== null) {
        $sql .= " AND {$idColumn} != ?";
        $params[] = $excludeId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() > 0;
}

/**
 * Validate and return error message for email
 * @param string $email Email to validate
 * @return string|null Error message or null if valid
 */
function validate_email_field($email, $required = true) {
    if (!$required && !validate_required($email)) {
        return null;
    }
    if ($required && !validate_required($email)) {
        return 'Email is required.';
    }
    if (!validate_email($email)) {
        return 'Invalid email format.';
    }
    if (!validate_string_length($email, null, 100)) {
        return 'Email must not exceed 100 characters.';
    }
    return null;
}

/**
 * Validate and return error message for phone
 * @param string $phone Phone to validate
 * @param bool $required Whether phone is required
 * @return string|null Error message or null if valid
 */
function validate_phone_field($phone, $required = true) {
    if (!$required && !validate_required($phone)) {
        return null;
    }
    if ($required && !validate_required($phone)) {
        return 'Phone number is required.';
    }
    if (!validate_phone($phone)) {
        return 'Invalid phone number format.';
    }
    if (!validate_string_length($phone, 10, 20)) {
        return 'Phone number must be between 10 and 20 characters.';
    }
    return null;
}

/**
 * Validate and return error message for Rwanda phone number (strict)
 * @param string $phone Phone to validate
 * @param bool $required Whether phone is required
 * @return string|null Error message or null if valid
 */
function validate_phone_rwanda_field($phone, $required = true) {
    if (!$required && !validate_required($phone)) {
        return null;
    }
    if ($required && !validate_required($phone)) {
        return 'Phone number is required.';
    }
    if (!validate_phone_rwanda($phone)) {
        return 'Phone number must be exactly 10 digits starting with 079, 078, 072, or 073 (e.g., 0781234567).';
    }
    return null;
}

/**
 * Validate and return error message for name
 * @param string $name Name to validate
 * @param bool $required Whether name is required
 * @return string|null Error message or null if valid
 */
function validate_name_field($name, $required = true) {
    if (!$required && !validate_required($name)) {
        return null;
    }
    if ($required && !validate_required($name)) {
        return 'Name is required.';
    }
    if (!validate_string_length($name, 2, 100)) {
        return 'Name must be between 2 and 100 characters.';
    }
    return null;
}

/**
 * Validate and return error message for username
 * @param string $username Username to validate
 * @param bool $required Whether username is required
 * @return string|null Error message or null if valid
 */
function validate_username_field($username, $required = true) {
    if (!$required && !validate_required($username)) {
        return null;
    }
    if ($required && !validate_required($username)) {
        return 'Username is required.';
    }
    if (!preg_match('/^[a-zA-Z0-9_.@-]{3,50}$/', $username)) {
        return 'Username must be 3-50 characters and contain only letters, numbers, and allowed special characters (._@-).';
    }
    return null;
}

/**
 * Validate and return error message for password
 * @param string $password Password to validate
 * @param bool $required Whether password is required
 * @return string|null Error message or null if valid
 */
function validate_password_field($password, $required = true) {
    if ($required && !validate_required($password)) {
        return 'Password is required.';
    }
    if ($password && !validate_string_length($password, 6, 255)) {
        return 'Password must be at least 6 characters.';
    }
    return null;
}

/**
 * Validate and return error message for date fields
 * @param string $date Date string to validate
 * @param string $fieldName Field name for error messages
 * @param string $format Date format to validate against
 * @param bool $required Whether date is required
 * @return string|null Error message or null if valid
 */
function validate_date_field($date, $fieldName, $format = 'Y-m-d', $required = true) {
    if (!$required && !validate_required($date)) {
        return null;
    }
    if ($required && !validate_required($date)) {
        return "{$fieldName} is required.";
    }
    $dt = DateTime::createFromFormat($format, $date);
    if (!$dt || $dt->format($format) !== $date) {
        return "{$fieldName} must be a valid date in {$format} format.";
    }
    return null;
}

/**
 * Validate and return error message for date fields (no future dates allowed)
 * @param string $date Date string to validate
 * @param string $fieldName Field name for error messages
 * @param string $format Date format to validate against
 * @param bool $required Whether date is required
 * @return string|null Error message or null if valid
 */
function validate_date_not_future_field($date, $fieldName, $format = 'Y-m-d', $required = true) {
    $error = validate_date_field($date, $fieldName, $format, $required);
    if ($error) return $error;
    if ($date && !validate_date_not_future($date)) {
        return "{$fieldName} cannot be in the future.";
    }
    return null;
}

/**
 * Validate and return error message for date fields (no past dates allowed)
 * @param string $date Date string to validate
 * @param string $fieldName Field name for error messages
 * @param string $format Date format to validate against
 * @param bool $required Whether date is required
 * @return string|null Error message or null if valid
 */
function validate_date_not_past_field($date, $fieldName, $format = 'Y-m-d', $required = true) {
    $error = validate_date_field($date, $fieldName, $format, $required);
    if ($error) return $error;
    if ($date && !validate_date_not_past($date)) {
        return "{$fieldName} cannot be in the past.";
    }
    return null;
}

/**
 * Validate and return error message for enum fields
 * @param string $value Value to validate
 * @param string $fieldName Field name for error messages
 * @param array $allowedValues Allowed values list
 * @param bool $required Whether value is required
 * @return string|null Error message or null if valid
 */
function validate_enum_field($value, $fieldName, array $allowedValues, $required = true) {
    if (!$required && !validate_required($value)) {
        return null;
    }
    if ($required && !validate_required($value)) {
        return "{$fieldName} is required.";
    }
    if (!validate_enum($value, $allowedValues)) {
        return "Invalid {$fieldName}. Must be one of: " . implode(', ', $allowedValues) . '.';
    }
    return null;
}

/**
 * Validate and return error message for text fields
 * @param string $value Text value to validate
 * @param string $fieldName Field name for error messages
 * @param int|null $min Minimum length
 * @param int|null $max Maximum length
 * @param bool $required Whether value is required
 * @return string|null Error message or null if valid
 */
function validate_text_field($value, $fieldName, $min = null, $max = null, $required = true) {
    if (!$required && !validate_required($value)) {
        return null;
    }
    if ($required && !validate_required($value)) {
        return "{$fieldName} is required.";
    }
    if ($min !== null && !validate_string_length($value, $min, null)) {
        return "{$fieldName} must be at least {$min} characters.";
    }
    if ($max !== null && !validate_string_length($value, null, $max)) {
        return "{$fieldName} must not exceed {$max} characters.";
    }
    return null;
}

/**
 * Validate and return error message for numeric fields
 * @param mixed $value Value to validate
 * @param string $fieldName Field name for error message
 * @param float|null $min Minimum value
 * @param float|null $max Maximum value
 * @return string|null Error message or null if valid
 */
function validate_numeric_field($value, $fieldName, $min = null, $max = null) {
    if (!validate_required($value)) {
        return "{$fieldName} is required.";
    }
    if (!validate_numeric($value, $min, $max)) {
        $msg = "{$fieldName} must be a valid number";
        if ($min !== null) $msg .= " (minimum: {$min})";
        if ($max !== null) $msg .= " (maximum: {$max})";
        $msg .= ".";
        return $msg;
    }
    return null;
}

/**
 * Validate and return error message for positive numeric fields (no negative values)
 * @param mixed $value Value to validate
 * @param string $fieldName Field name for error message
 * @param float|null $max Maximum value (optional)
 * @return string|null Error message or null if valid
 */
function validate_positive_numeric_field($value, $fieldName, $max = null) {
    if (!validate_required($value)) {
        return "{$fieldName} is required.";
    }
    if (!validate_numeric($value, 0, $max)) {
        $msg = "{$fieldName} must be a valid positive number";
        if ($max !== null) $msg .= " (maximum: {$max})";
        $msg .= ".";
        return $msg;
    }
    return null;
}

/**
 * Validate and return error message for non-negative numeric fields (zero or positive)
 * @param mixed $value Value to validate
 * @param string $fieldName Field name for error message
 * @param float|null $max Maximum value (optional)
 * @return string|null Error message or null if valid
 */
function validate_non_negative_numeric_field($value, $fieldName, $max = null) {
    if (!validate_required($value)) {
        return "{$fieldName} is required.";
    }
    if (!validate_numeric($value, 0, $max)) {
        $msg = "{$fieldName} must be a valid non-negative number";
        if ($max !== null) $msg .= " (maximum: {$max})";
        $msg .= ".";
        return $msg;
    }
    return null;
}
