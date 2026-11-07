<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StreamCampaignContentRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'tone' => ['nullable', 'string', 'max:'.config('llm.content.tone_max_length')],
        ];
    }

    public function tone(): string
    {
        $tone = trim((string) $this->validated('tone'));

        return $tone === '' ? (string) config('llm.content.default_tone') : $tone;
    }
}
