<?php

namespace App\Http\Requests;

use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContentCampaignRequest extends FormRequest
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
     * A PATCH may carry only the fields being changed, so every rule is
     * conditional on the field being present.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'theme' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'source_image_path' => ['sometimes', 'nullable', 'url', 'max:1024'],
            'campaign_type' => ['sometimes', Rule::enum(CampaignType::class)],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            // Only the two live states can be set by hand; the finished ones
            // are reached by the posts settling or by cancelling.
            'status' => [
                'sometimes',
                Rule::enum(CampaignStatus::class)->only([CampaignStatus::Draft, CampaignStatus::Active]),
            ],
            'channels' => ['sometimes', 'array', 'min:1'],
            'channels.*' => [Rule::enum(CampaignChannel::class)],
        ];
    }
}
