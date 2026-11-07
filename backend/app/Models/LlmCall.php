<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LlmCallOutcome;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $purpose
 * @property string|null $prompt_version
 * @property string $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int $latency_ms
 * @property int $attempt
 * @property LlmCallOutcome $outcome
 * @property string|null $error_message
 * @property list<string>|null $validation_errors
 * @property string|null $raw_output
 */
class LlmCall extends Model
{
    // Rows are append-only; the database fills created_at.
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'purpose', 'prompt_version', 'model', 'input_tokens', 'output_tokens', 'latency_ms',
        'attempt', 'outcome', 'error_message', 'validation_errors', 'raw_output',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => LlmCallOutcome::class,
            'validation_errors' => 'array',
        ];
    }

    /**
     * The model answered but the answer failed validation. Changes the model in memory only; the caller saves it.
     *
     * @param  list<string>  $errors  path-based messages, as shown to the repair prompt
     */
    public function markInvalid(array $errors): void
    {
        $this->outcome = LlmCallOutcome::Invalid;
        $this->validation_errors = $errors;
    }
}
