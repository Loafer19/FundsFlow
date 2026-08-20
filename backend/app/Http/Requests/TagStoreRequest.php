<?php

namespace App\Http\Requests;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TagStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>|\Illuminate\Contracts\Validation\ValidationRule|string>
     */
    public function rules(): array
    {
        $tag = $this->route('tag');

        $parentIdRules = [
            'nullable',
            'integer',
            Rule::exists('tags', 'id')->where('user_id', $this->user()->id),
        ];

        if ($tag instanceof Tag) {
            $parentIdRules[] = Rule::notIn([(int) $tag->id]);
        }

        return [
            'parent_id' => $parentIdRules,
            'title' => 'required|string|max:255',
            'emoji' => 'required|string|max:255',
            'calc_balance' => 'required|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tag = $this->route('tag');
            $parentId = $this->input('parent_id');

            if (! $tag instanceof Tag || $parentId === null || $parentId === '') {
                return;
            }

            $parentId = (int) $parentId;
            $visited = [];
            $currentId = $parentId;

            while ($currentId !== null) {
                if ($currentId === (int) $tag->id) {
                    $validator->errors()->add('parent_id', 'A tag cannot be nested under itself or its descendants.');

                    return;
                }

                if (isset($visited[$currentId])) {
                    return;
                }

                $visited[$currentId] = true;
                $currentId = Tag::query()
                    ->where('user_id', $this->user()->id)
                    ->whereKey($currentId)
                    ->value('parent_id');
            }
        });
    }
}
