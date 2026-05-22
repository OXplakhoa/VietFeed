<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100|unique:categories,name',
            'slug' => 'required|string|max:100|unique:categories,slug|regex:/^[a-z0-9-]+$/',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'Tên chủ đề là bắt buộc.',
            'name.unique'      => 'Tên chủ đề đã tồn tại.',
            'slug.required'    => 'Slug là bắt buộc.',
            'slug.unique'      => 'Slug đã tồn tại.',
            'slug.regex'       => 'Slug chỉ được chứa chữ thường, số và dấu gạch ngang.',
        ];
    }
}
