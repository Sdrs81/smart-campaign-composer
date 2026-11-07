<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Content\MessageContent;
use App\Enums\Channel;
use Illuminate\Foundation\Http\FormRequest;

/** Only the shape is checked here; lengths and variables are ContentValidator's job (one rule set). */
class UpdateCampaignContentRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'sms.body' => ['required', 'string'],
            'email.subject' => ['required', 'string'],
            'email.body' => ['required', 'string'],
        ];
    }

    public function sms(): MessageContent
    {
        return new MessageContent(Channel::Sms, null, (string) $this->validated('sms.body'));
    }

    public function email(): MessageContent
    {
        return new MessageContent(Channel::Email, (string) $this->validated('email.subject'), (string) $this->validated('email.body'));
    }
}
