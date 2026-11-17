<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('category')?->id;

        return [
            'name' => "required|string|max:100|unique:mongodb.categories,name,{$id},_id",
            'slug' => "required|string|max:100|unique:mongodb.categories,slug,{$id},_id|regex:/^[a-z0-9-]+$/",
            'is_active' => 'boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tên chủ đề là bắt buộc.',
            'name.unique' => 'Tên chủ đề đã tồn tại.',
            'slug.required' => 'Slug là bắt buộc.',
            'slug.unique' => 'Slug đã tồn tại.',
            'slug.regex' => 'Slug chỉ được chứa chữ thường, số và dấu gạch ngang.',
        ];
    }
}
