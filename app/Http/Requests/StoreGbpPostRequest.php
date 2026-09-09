<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGbpPostRequest extends FormRequest
{
    /**
     * The call to action types Google accepts on a standard local post.
     */
    public const CTA_TYPES = ['BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'];

    /**
     * Authorization is handled by the route's role middleware and the post
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
            // Google caps a local post's summary at 1500 characters.
            'content' => ['required', 'string', 'max:1500'],
            'media_url' => ['nullable', 'url', 'max:1024'],
            'cta_type' => ['nullable', Rule::in(self::CTA_TYPES)],
            // CALL is the one action Google takes without a url; the rest need
            // somewhere to send the person.
            'cta_url' => [
                'nullable',
                'url',
                'max:1024',
                Rule::requiredIf(fn () => filled($this->input('cta_type')) && $this->input('cta_type') !== 'CALL'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => '投稿内容を入力してください。',
            'content.max' => '投稿内容は1500文字以内で入力してください。',
            'cta_type.in' => '指定されたボタンの種類は利用できません。',
            'cta_url.required' => 'このボタンにはリンク先URLが必要です。',
        ];
    }
}
