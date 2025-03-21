<?php

namespace Kompo\Auth\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Models\Teams\TeamRole;

class TeamRoleFactory extends Factory
{
    public function definition()
    {
        $this->model = TeamRole::class;

        return [
            // 'user_id' => UserFactory::new()->create()->id,
            'team_id' => TeamFactory::new()->create()->id,
        ];
    }
}
