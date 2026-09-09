<?php

namespace App\Http\Requests;

use App\Support\Tenancy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    /**
     * Authorization is handled by the route's role middleware and the location
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
            // A brand from another organization must not be reachable, so the
            // existence check is scoped to the active tenant.
            'brand_id' => [
                'nullable',
                'integer',
                Rule::exists('brands', 'id')
                    ->where('organization_id', app(Tenancy::class)->id()),
            ],
            'gbp_location_id' => ['nullable', 'string', 'max:255', Rule::unique('locations', 'gbp_location_id')],
            'website_url' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            // Where the store front sits, which is what a heatmap lays its
            // grid around. Optional: rank tracking does not need it.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'brand_id.exists' => '指定されたブランドが見つかりません。',
            'gbp_location_id.unique' => 'このGoogleビジネスプロフィールは既に別の店舗に紐付いています。',
        ];
    }
}
