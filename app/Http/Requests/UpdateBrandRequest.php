<?php

namespace App\Http\Requests;

use App\Models\Brand;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends FormRequest
{
    /**
     * Authorization is handled by the route's role middleware and the brand
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
        $brand = $this->route('brand');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('brands', 'name')
                    ->where('organization_id', app(Tenancy::class)->id())
                    ->ignore($brand instanceof Brand ? $brand->getKey() : $brand),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'この名前のブランドは既に登録されています。',
        ];
    }
}
