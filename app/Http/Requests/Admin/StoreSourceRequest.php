<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:500',
            'feed_url' => 'required|url|max:500',
            'logo_url' => 'nullable|url|max:500',
            'category_id' => 'required|exists:categories,id',
            'is_active' => 'boolean',
            'prestige' => 'nullable|integer|min:1|max:5',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'prestige' => $this->input('prestige') ?? 3,
        ]);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tên nguồn tin là bắt buộc.',
            'url.required' => 'URL trang chủ là bắt buộc.',
            'url.url' => 'URL trang chủ không hợp lệ.',
            'feed_url.required' => 'URL RSS feed là bắt buộc.',
            'feed_url.url' => 'URL RSS feed không hợp lệ.',
            'logo_url.url' => 'URL logo không hợp lệ.',
            'category_id.required' => 'Vui lòng chọn chủ đề.',
            'category_id.exists' => 'Chủ đề không tồn tại.',
            'prestige.integer' => 'Độ uy tín phải là số từ 1 đến 5.',
            'prestige.min' => 'Độ uy tín phải từ 1 đến 5.',
            'prestige.max' => 'Độ uy tín phải từ 1 đến 5.',
        ];
    }
}
