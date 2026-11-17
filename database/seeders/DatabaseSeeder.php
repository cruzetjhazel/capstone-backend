<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(BicolPsgcSeeder::class);
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->raw(['name' => 'Test User'])
        );

        // AdminSeeder must run first — DemoSeeder attributes admin-side
        // actions (application approvals, payment verifications, report
        // notes) to whichever administrator it creates/normalizes.
        $this->call(AdminSeeder::class);
        $this->call(DemoSeeder::class);
        $this->call(PhotographerShowcaseSeeder::class);
        $this->call(CatalogShowcaseSeeder::class);
        $this->call(BookingStatesShowcaseSeeder::class);
        $this->call(PaymentStatesShowcaseSeeder::class); 
$this->call(ServiceTrackerShowcaseSeeder::class);  
        $this->call(ScenarioMatrixSeeder::class);
        $this->call(ApplicationStatesSeeder::class);
        $this->call(BookingLocationSeeder::class);
 
        $this->call(TestClientSeeder::class);
        $this->call(NotificationShowcaseSeeder::class); // must run last so it can link to real bookings
    }
}