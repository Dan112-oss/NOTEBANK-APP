<?php
/**
 * Small server-side validator. Frontend JS validation (see assets/js)
 * is for user experience only — every rule here is re-checked
 * server-side regardless of what the client already validated.
 */

declare(strict_types=1);

final class Validator
{
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    public function required(string $field, string $label): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->errors[$field] = "{$label} is required.";
        }
        return $this;
    }

    public function email(string $field, string $label = 'Email'): self
    {
        $value = $this->data[$field] ?? '';
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "{$label} must be a valid email address.";
        }
        return $this;
    }

    public function minLength(string $field, int $min, string $label): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if (mb_strlen($value) > 0 && mb_strlen($value) < $min) {
            $this->errors[$field] = "{$label} must be at least {$min} characters.";
        }
        return $this;
    }

    public function maxLength(string $field, int $max, string $label): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if (mb_strlen($value) > $max) {
            $this->errors[$field] = "{$label} must be at most {$max} characters.";
        }
        return $this;
    }

    public function numeric(string $field, string $label): self
    {
        $value = $this->data[$field] ?? '';
        if ($value !== '' && !is_numeric($value)) {
            $this->errors[$field] = "{$label} must be a number.";
        }
        return $this;
    }

    public function min(string $field, float $min, string $label): self
    {
        $value = $this->data[$field] ?? null;
        if (is_numeric($value) && (float) $value < $min) {
            $this->errors[$field] = "{$label} must be at least {$min}.";
        }
        return $this;
    }

    public function in(string $field, array $allowed, string $label): self
    {
        $value = $this->data[$field] ?? null;
        if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
            $this->errors[$field] = "{$label} is not a valid choice.";
        }
        return $this;
    }

    public function matches(string $field, string $otherField, string $label): self
    {
        if (($this->data[$field] ?? null) !== ($this->data[$otherField] ?? null)) {
            $this->errors[$field] = "{$label} does not match.";
        }
        return $this;
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors === [] ? null : array_values($this->errors)[0];
    }
}

function is_strong_password(string $password): bool
{
    // At least 8 chars with a letter and a number — a floor, not a
    // full policy; keep it usable for a student audience.
    return strlen($password) >= 8 && preg_match('/[A-Za-z]/', $password) && preg_match('/\d/', $password);
}

/**
 * Validate an uploaded file against an allowlist of MIME types and a
 * byte size cap. Checks the real content type via finfo, not just the
 * client-supplied extension/MIME (which is trivially spoofable).
 */
function validate_upload(array $file, array $allowedMime, int $maxBytes): ?string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return 'The file did not upload correctly. Please try again.';
    }
    if ($file['size'] > $maxBytes) {
        return 'The file is too large.';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMime, true)) {
        return 'That file type is not allowed.';
    }
    // Reject anything with an executable-looking extension regardless
    // of reported MIME.
    $dangerous = ['php', 'phtml', 'php3', 'php4', 'php5', 'exe', 'sh', 'bat', 'js', 'html', 'htm'];
    $ext = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $dangerous, true)) {
        return 'That file type is not allowed.';
    }
    return null;
}
