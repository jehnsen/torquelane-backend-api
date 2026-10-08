<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local and staging demo data (DemoSeeder refuses to run in production).
     * Model events stay on: the tenancy guards on tenant models are events.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
