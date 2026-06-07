<?php
// api/core/Validator.php
// ============================================================
declare(strict_types=1);

class Validator
{
    private array  $data;
    private array  $_errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function required(string $field): self
    {
        if (empty($this->data[$field]) && $this->data[$field] !== 0 && $this->data[$field] !== '0') {
            $this->_errors[] = "Поле «{$field}» обязательно";
        }
        return $this;
    }

    public function in(string $field, array $values): self
    {
        if (isset($this->data[$field]) && !in_array($this->data[$field], $values, true)) {
            $this->_errors[] = "Поле «{$field}» должно быть одним из: " . implode(', ', $values);
        }
        return $this;
    }

    public function numeric(string $field): self
    {
        if (isset($this->data[$field]) && !is_numeric($this->data[$field])) {
            $this->_errors[] = "Поле «{$field}» должно быть числом";
        }
        return $this;
    }

    public function min(string $field, float $min): self
    {
        if (isset($this->data[$field]) && (float)$this->data[$field] < $min) {
            $this->_errors[] = "Поле «{$field}» должно быть не менее {$min}";
        }
        return $this;
    }

    public function maxLength(string $field, int $max): self
    {
        if (isset($this->data[$field]) && mb_strlen((string)$this->data[$field]) > $max) {
            $this->_errors[] = "Поле «{$field}» не более {$max} символов";
        }
        return $this;
    }

    public function fails(): bool  { return !empty($this->_errors); }
    public function errors(): array { return $this->_errors; }
    public function firstError(): string { return $this->_errors[0] ?? ''; }
}