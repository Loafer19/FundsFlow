<?php

namespace App\Actions\Monobank;

use App\Exceptions\MonobankException;
use App\Models\MonobankConnection;
use App\Models\User;

class UpdateMonobankSettingsAction
{
    public function __construct(private readonly LinkMonobankJarGoalAction $link) {}

    /**
     * @param  array{notify?: bool, accounts?: list<array{id: int, enabled?: bool, tag_id?: int|null}>, jars?: list<array{id: int, track_as_goal?: bool}>}  $data
     */
    public function execute(User $user, array $data): MonobankConnection
    {
        $connection = $user->monobankConnection;

        if (!$connection) {
            throw new MonobankException('Monobank is not connected.', 404);
        }

        if (array_key_exists('notify', $data)) {
            $connection->notify = (bool) $data['notify'];
            $connection->save();
        }

        foreach ($data['accounts'] ?? [] as $row) {
            $account = $connection->accounts()->whereKey($row['id'])->first();

            if (!$account || $account->isJar()) {
                continue;
            }

            if (array_key_exists('enabled', $row)) {
                if ($row['enabled'] && !$account->isUah()) {
                    continue;
                }

                $account->enabled = (bool) $row['enabled'];
            }

            if (array_key_exists('tag_id', $row)) {
                $tagId = $row['tag_id'];

                if ($tagId !== null && !$user->tags()->whereKey($tagId)->exists()) {
                    $tagId = null;
                }

                $account->tag_id = $tagId;
            }

            $account->save();
        }

        foreach ($data['jars'] ?? [] as $row) {
            $jar = $connection->accounts()->whereKey($row['id'])->where('type', 'jar')->first();

            if (!$jar || !array_key_exists('track_as_goal', $row)) {
                continue;
            }

            $this->link->execute($user, $jar, (bool) $row['track_as_goal']);
        }

        return $connection->fresh(['accounts.tag']);
    }
}
