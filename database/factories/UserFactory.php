<?php

namespace Kompo\Auth\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kompo\Auth\Models\User;

/**
 * @method static \Illuminate\Database\Eloquent\Factories\Factory<User> new()
 * @method static \Illuminate\Database\Eloquent\Factories\Factory<User> create(array $attributes = [])
 */
class UserFactory extends Factory
{
    protected $withoutTeamRole = false;

    public function definition()
    {
        $this->model = User::class;

        return [
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'email_verified_at' => now(),
            'password' => bcrypt('password'), // password
        ];
    }

    public function configure()
    {
        if ($this->withoutTeamRole) {
            return $this;
        }

        return $this->afterCreating(function (User $user) {
            $user->current_team_role_id = TeamRoleFactory::new()->create(['user_id' => $user->id])->id;
            $user->save();
        });
    }

    public function withoutTeamRole()
    {
        $this->withoutTeamRole = true;

        return $this;
    }
}
