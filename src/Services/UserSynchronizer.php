<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nagaland\IamClient\DTOs\IamUser;
use Nagaland\IamClient\Events\UserSynchronized;

final readonly class UserSynchronizer
{
    /**
     * @param  class-string<Model&Authenticatable>  $userModel
     */
    public function __construct(private string $userModel) {}

    public function sync(IamUser $iamUser): Authenticatable
    {
        /** @var Model&Authenticatable $user */
        $user = $this->userModel::query()
            ->where('iam_user_id', $iamUser->id)
            ->orWhere('email', $iamUser->email)
            ->firstOrNew();

        $values = [
            'iam_user_id' => $iamUser->id,
            'name' => $iamUser->name,
            'email' => $iamUser->email,
        ];

        if (! $user->exists) {
            $values['password'] = Hash::make(Str::random(64));
        }

        $user->forceFill($values)->save();

        event(new UserSynchronized($user, $iamUser));

        return $user;
    }
}
