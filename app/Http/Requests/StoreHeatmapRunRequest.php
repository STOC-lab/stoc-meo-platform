<?php

namespace App\Http\Requests;

use App\Enums\HeatmapGridSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHeatmapRunRequest extends FormRequest
{
    /**
     * Authorization is handled by the route's role middleware and the heatmap
     * policy, which the controller consults. Whether the plan includes the
     * grid asked for is an entitlement, checked there too.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The keyword is only checked for existence here; that it belongs to this
     * store front is the controller's business, since answering otherwise
     * would confirm another organization's keyword exists.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'keyword_id' => ['required', 'integer'],
            'grid_size' => ['required', Rule::enum(HeatmapGridSize::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'grid_size.required' => 'グリッドサイズを指定してください。',
            'grid_size.enum' => 'グリッドサイズは 5x5 または 7x7 を指定してください。',
            'keyword_id.required' => 'キーワードを指定してください。',
        ];
    }
}
