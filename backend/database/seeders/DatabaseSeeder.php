<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Mechanic;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed one login per role so the app is usable immediately after
     * `php artisan migrate --seed`. All seeded accounts use the same
     * password below - change it after first login.
     */
    public function run(): void
    {
        $password = Hash::make('Password123!');

        User::firstOrCreate(
            ['Username' => 'admin'],
            [
                'Password' => $password,
                'Role' => 'Admin',
                'FullName' => 'System Administrator',
                'Email' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'Phone' => '0788000001',
                'Status' => 'Inactive',
            ]
        );

        User::firstOrCreate(
            ['Username' => 'reception'],
            [
                'Password' => $password,
                'Role' => 'Receptionist',
                'FullName' => 'Reception Desk',
                'Email' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'Phone' => '0788000002',
                'Status' => 'Inactive',
            ]
        );

        User::firstOrCreate(
            ['Username' => 'stock'],
            [
                'Password' => $password,
                'Role' => 'Stock Manager',
                'FullName' => 'Stock Manager',
                'Email' => 'elysend69@gmail.com',
                'Phone' => '0788000003',
                'Status' => 'Inactive',
            ]
        );

        $mechanic = Mechanic::firstOrCreate(
            ['FullName' => 'Eric Mwangi'],
            ['Phone' => '0788000004', 'Specialization' => 'General', 'Salary' => 350000]
        );
        User::firstOrCreate(
            ['Username' => 'mechanic'],
            [
                'Password' => $password,
                'Role' => 'Mechanic',
                'FullName' => $mechanic->FullName,
                'Email' => 'elysend69@gmail.com',
                'Phone' => $mechanic->Phone,
                'Status' => 'Inactive',
                'MechanicID' => $mechanic->MechanicID,
            ]
        );

        foreach (['Engine Parts', 'Electrical', 'Brakes & Suspension', 'Body & Paint', 'Fluids & Filters'] as $name) {
            Category::firstOrCreate(['CategoryName' => $name]);
        }

        Supplier::firstOrCreate(
            ['CompanyName' => 'Kigali Auto Parts Ltd'],
            ['Phone' => '0788000005', 'Email' => 'elysend69@gmail.com', 'Address' => 'Kigali, Rwanda']
        );
    }
}
