<?php
/**
 * DMS - Distribution Management System
 * Input & Form Validation Helpers
 */

if (!defined('DMS_RUNNING')) {
    exit('Direct access not permitted.');
}

class Validator {
    private array $errors = [];

    /**
     * Check if a field has a non-empty value
     *
     * @param string $field
     * @param mixed $value
     * @param string|null $customMessage
     * @return self
     */
    public function required(string $field, $value, ?string $customMessage = null): self {
        $val = is_string($value) ? trim($value) : $value;
        if ($val === '' || $val === null || (is_array($val) && empty($val))) {
            $this->errors[$field] = $customMessage ?? ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
        return $this;
    }

    /**
     * Validate numeric value with optional bounds
     *
     * @param string $field
     * @param mixed $value
     * @param float|null $min
     * @param float|null $max
     * @param string|null $customMessage
     * @return self
     */
    public function numeric(string $field, $value, ?float $min = null, ?float $max = null, ?string $customMessage = null): self {
        if ($value !== '' && $value !== null) {
            if (!is_numeric($value)) {
                $this->errors[$field] = $customMessage ?? ucfirst(str_replace('_', ' ', $field)) . ' must be a valid number.';
                return $this;
            }
            $num = (float)$value;
            if ($min !== null && $num < $min) {
                $this->errors[$field] = $customMessage ?? ucfirst(str_replace('_', ' ', $field)) . " must be at least {$min}.";
            }
            if ($max !== null && $num > $max) {
                $this->errors[$field] = $customMessage ?? ucfirst(str_replace('_', ' ', $field)) . " cannot exceed {$max}.";
            }
        }
        return $this;
    }

    /**
     * Validate date format
     *
     * @param string $field
     * @param mixed $value
     * @param string $format
     * @param string|null $customMessage
     * @return self
     */
    public function date(string $field, $value, string $format = 'Y-m-d', ?string $customMessage = null): self {
        if (!empty($value)) {
            $d = DateTime::createFromFormat($format, (string)$value);
            if (!($d && $d->format($format) === (string)$value)) {
                $this->errors[$field] = $customMessage ?? ucfirst(str_replace('_', ' ', $field)) . " must be a valid date ({$format}).";
            }
        }
        return $this;
    }

    /**
     * Validate email format
     *
     * @param string $field
     * @param mixed $value
     * @param string|null $customMessage
     * @return self
     */
    public function email(string $field, $value, ?string $customMessage = null): self {
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = $customMessage ?? 'Invalid email address format.';
        }
        return $this;
    }

    /**
     * Validate uniqueness in database
     *
     * @param string $field
     * @param mixed $value
     * @param string $table
     * @param string $column
     * @param int|string|null $ignoreId
     * @param string $idColumn
     * @param string|null $customMessage
     * @return self
     */
    public function unique(string $field, $value, string $table, string $column, $ignoreId = null, string $idColumn = 'id', ?string $customMessage = null): self {
        if (!empty($value)) {
            $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
            $params = [$value];
            if ($ignoreId !== null) {
                $sql .= " AND {$idColumn} != ?";
                $params[] = $ignoreId;
            }
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            if ((int)$stmt->fetchColumn() > 0) {
                $this->errors[$field] = $customMessage ?? ucfirst(str_replace('_', ' ', $field)) . ' already exists in the system.';
            }
        }
        return $this;
    }

    /**
     * Validate positive integer ID (e.g. primary/foreign keys > 0)
     *
     * @param string $field
     * @param mixed $value
     * @param string|null $customMessage
     * @return self
     */
    public function positiveInt(string $field, $value, ?string $customMessage = null): self {
        if (!is_numeric($value) || (int)$value <= 0) {
            $this->errors[$field] = $customMessage ?? ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
        return $this;
    }

    /**
     * Validate record existence in database
     *
     * @param string $field
     * @param mixed $value
     * @param string $table
     * @param string $column
     * @param string|null $customMessage
     * @return self
     */
    public function exists(string $field, $value, string $table, string $column = 'id', ?string $customMessage = null): self {
        if ($value !== null && $value !== '') {
            $stmt = db()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
            $stmt->execute([$value]);
            if ((int)$stmt->fetchColumn() === 0) {
                $this->errors[$field] = $customMessage ?? ucfirst(str_replace('_', ' ', $field)) . ' does not exist in master records.';
            }
        }
        return $this;
    }

    /**
     * Add a custom error
     *
     * @param string $field
     * @param string $message
     * @return self
     */
    public function addError(string $field, string $message): self {
        $this->errors[$field] = $message;
        return $this;
    }

    /**
     * Check if validation passed
     *
     * @return bool
     */
    public function passes(): bool {
        return empty($this->errors);
    }

    /**
     * Check if validation passed (alias for passes)
     *
     * @return bool
     */
    public function isValid(): bool {
        return empty($this->errors);
    }

    /**
     * Check if validation failed
     *
     * @return bool
     */
    public function fails(): bool {
        return !empty($this->errors);
    }

    /**
     * Get all errors
     *
     * @return array
     */
    public function getErrors(): array {
        return $this->errors;
    }

    /**
     * Get first error message
     *
     * @return string
     */
    public function getFirstError(): string {
        return !empty($this->errors) ? reset($this->errors) : '';
    }
}
