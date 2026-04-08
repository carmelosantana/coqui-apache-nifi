<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitApacheNifi\Runtime;

use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Immutable result from a NiFi API call.
 *
 * Bridges NiFi JSON responses to php-agents ToolResult.
 */
final readonly class NiFiResult
{
    /** @param array<int, array<string, mixed>> $errors */
    public function __construct(
        public bool $success,
        public mixed $data,
        public array $errors = [],
        public int $statusCode = 200,
    ) {}

    public static function error(string $message, int $statusCode = 0): self
    {
        return new self(
            success: false,
            data: null,
            errors: [['message' => $message]],
            statusCode: $statusCode,
        );
    }

    public function errorMessage(): string
    {
        if ($this->errors === []) {
            return 'Unknown error (HTTP ' . $this->statusCode . ')';
        }

        $parts = [];
        foreach ($this->errors as $err) {
            $msg = (string) ($err['message'] ?? 'Unknown error');
            $code = isset($err['code']) ? " (code {$err['code']})" : '';
            $parts[] = $msg . $code;
        }

        return implode('; ', $parts);
    }

    public function toToolResult(): ToolResult
    {
        if ($this->success) {
            return ToolResult::success($this->formatData());
        }
        return ToolResult::error($this->errorMessage());
    }

    public function toToolResultWith(string $successPrefix): ToolResult
    {
        if ($this->success) {
            $formatted = $this->formatData();
            $output = $successPrefix;
            if ($formatted !== '' && $formatted !== '""' && $formatted !== 'null') {
                $output .= "\n\n" . $formatted;
            }
            return ToolResult::success($output);
        }
        return ToolResult::error($this->errorMessage());
    }

    private function formatData(): string
    {
        if ($this->data === null) {
            return '';
        }
        if (is_string($this->data)) {
            return $this->data;
        }
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '';
    }
}
