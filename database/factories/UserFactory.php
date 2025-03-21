<?php

namespace Kompo\Auth\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\User;

class UserFactory extends Factory
{
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
        return $this->afterCreating(function (User $user) {
            $user->current_team_role_id = TeamRoleFactory::new()->create(['user_id' => $user->id])->id;
            $user->save();
        });
    }
}
