<?php

namespace App\Http\Requests;

use App\Models\Location;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
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
     * A PATCH may carry only the fields being changed, so every rule is
     * conditional on the field being present.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $location = $this->route('location');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'brand_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('brands', 'id')
                    ->where('organization_id', app(Tenancy::class)->id()),
            ],
            'gbp_location_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('locations', 'gbp_location_id')
                    ->ignore($location instanceof Location ? $location->getKey() : $location),
            ],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{3}-?[0-9]{4}$/'],
            'prefecture' => ['sometimes', 'nullable', 'string', 'max:32'],
            'city' => ['sometimes', 'nullable', 'string', 'max:64'],
            'google_place_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'google_maps_url' => ['sometimes', 'nullable', 'url', 'max:512'],
            'is_active' => ['sometimes', 'boolean'],
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
            'postal_code.regex' => '郵便番号は 1234567 または 123-4567 の形式で入力してください。',
        ];
    }
}
