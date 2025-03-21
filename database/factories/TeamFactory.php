<?php

namespace Kompo\Auth\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kompo\Auth\Facades\TeamModel;

class TeamFactory extends Factory
{
    public function definition()
    {
        $this->model = TeamModel::getClass();

        return [
            'team_name' => $this->faker->name,
        ];
    }
}
