<?php

namespace App\Http\Requests;

use App\Enums\CampaignChannel;
use App\Enums\CampaignType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContentCampaignRequest extends FormRequest
{
    /**
     * Authorization is handled by the route's role middleware and the campaign
     * policy, which the controller consults.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'theme' => ['nullable', 'string', 'max:2000'],
            'source_image_path' => ['nullable', 'url', 'max:1024'],
            'campaign_type' => ['required', Rule::enum(CampaignType::class)],
            // A campaign that waits for its moment has to say when that is.
            'scheduled_at' => [
                'nullable',
                'date',
                Rule::requiredIf(fn () => $this->input('campaign_type') === CampaignType::Scheduled->value),
            ],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::enum(CampaignChannel::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'キャンペーン名を入力してください。',
            'campaign_type.required' => '実行タイプを指定してください。',
            'scheduled_at.required' => '予約実行には日時が必要です。',
            'channels.required' => '投稿先を1つ以上選択してください。',
        ];
    }
}
