<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kompo\Auth\Models\Monitoring\CommunicationTemplateGroup;
use Kompo\Tests\Models\User;

class DeleteOldCommunicationTemplates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kompo:delete-old-communication-templates';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        CommunicationTemplateGroup::deleteOldVoids();
    }
}
