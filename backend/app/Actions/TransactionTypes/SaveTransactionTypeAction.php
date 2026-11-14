<?php

namespace App\Actions\TransactionTypes;

use App\Models\TransactionType;
use App\Models\User;
use App\Support\TransactionFields;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a transaction type. Field keys stay stable (TransactionFields::normalizeSchema):
 * a renamed field keeps its key and values, a removed one is kept hidden.
 */
class SaveTransactionTypeAction
{
    public const MAX_TYPES = 50;

    /**
     * @param array<string, mixed> $input
     */
    public function execute(User $user, array $input, ?TransactionType $type = null): TransactionType
    {
        if ($type !== null && (int) $type->user_id !== (int) $user->id) {
            abort(403);
        }

        $creating = $type === null;
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'emoji' => ['nullable', 'string', 'max:32'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'fields' => [$creating ? 'present' : 'sometimes', 'array', 'max:' . TransactionFields::MAX_FIELDS],
            'fields.*' => ['array'],
            'default_tag_ids' => ['nullable', 'array', 'max:10'],
            'default_tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
        ];
        $data = Validator::make($input, $rules, [
            'color.regex' => 'Pick a color like #f59e0b!',
            'default_tag_ids.*.exists' => 'Default tags must be your own tags!',
        ])->validate();

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Name is required!']);
            }

            $taken = $user->transactionTypes()
                ->when($type, fn ($query) => $query->whereKeyNot($type->id))
                ->get(['id', 'name'])
                ->contains(fn (TransactionType $other) => mb_strtolower($other->name) === mb_strtolower($name));

            if ($taken) {
                throw ValidationException::withMessages(['name' => "You already have a type called {$name}!"]);
            }
            $data['name'] = $name;
        }

        if ($creating && $user->transactionTypes()->count() >= self::MAX_TYPES) {
            throw ValidationException::withMessages(['name' => 'You can have at most ' . self::MAX_TYPES . ' types!']);
        }

        if (array_key_exists('fields', $data)) {
            $data['fields'] = TransactionFields::normalizeSchema($data['fields'], $type?->fields ?? []);
        }

        if (array_key_exists('default_tag_ids', $data)) {
            $data['default_tag_ids'] = array_values(array_unique(array_map('intval', $data['default_tag_ids'] ?? [])));
        }

        if (array_key_exists('emoji', $data)) {
            $data['emoji'] = trim((string) $data['emoji']) ?: null;
        }

        if ($creating) {
            return $user->transactionTypes()->create($data + ['default_tag_ids' => []]);
        }

        $type->fill($data)->save();

        return $type->fresh();
    }
}
