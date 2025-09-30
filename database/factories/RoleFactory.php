<?php

namespace Kompo\Auth\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kompo\Auth\Models\Teams\Roles\Role;

class RoleFactory extends Factory
{
    public function definition()
    {
        $this->model = Role::class;

        return [
            'id' => \Str::uuid()->toString(),
            'name' => $this->faker->unique()->jobTitle(),
            'description' => $this->faker->sentence(),
        ];
    }
}
