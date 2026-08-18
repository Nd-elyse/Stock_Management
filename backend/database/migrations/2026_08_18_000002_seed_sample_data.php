<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\SparePart;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\RepairJob;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only seed if tables are empty (safe for repeated runs)
        if (Customer::count() === 0) {
            $this->seedSampleData();
        }
    }

    private function seedSampleData(): void
    {
        // Create sample customers
        $customers = [
            Customer::firstOrCreate(
                ['FullName' => 'John Smith'],
                ['Phone' => '0712345601', 'Email' => 'john@example.com', 'Address' => '123 Main Street', 'RegistrationDate' => now()->toDateString()]
            ),
            Customer::firstOrCreate(
                ['FullName' => 'Jane Doe'],
                ['Phone' => '0712345602', 'Email' => 'jane@example.com', 'Address' => '456 Oak Avenue', 'RegistrationDate' => now()->toDateString()]
            ),
            Customer::firstOrCreate(
                ['FullName' => 'Bob Johnson'],
                ['Phone' => '0712345603', 'Email' => 'bob@example.com', 'Address' => '789 Maple Road', 'RegistrationDate' => now()->toDateString()]
            ),
            Customer::firstOrCreate(
                ['FullName' => 'Alice Williams'],
                ['Phone' => '0712345604', 'Email' => 'alice@example.com', 'Address' => '321 Pine Lane', 'RegistrationDate' => now()->toDateString()]
            ),
            Customer::firstOrCreate(
                ['FullName' => 'Charlie Brown'],
                ['Phone' => '0712345605', 'Email' => 'charlie@example.com', 'Address' => '654 Elm Street', 'RegistrationDate' => now()->toDateString()]
            ),
        ];

        // Create sample mechanics
        $mechanics = [
            Mechanic::firstOrCreate(
                ['FullName' => 'Mike Wilson'],
                ['Phone' => '0712345701', 'Specialty' => 'Engine Repair', 'Status' => 'Active']
            ),
            Mechanic::firstOrCreate(
                ['FullName' => 'David Garcia'],
                ['Phone' => '0712345702', 'Specialty' => 'Electrical Systems', 'Status' => 'Active']
            ),
            Mechanic::firstOrCreate(
                ['FullName' => 'Sarah Martinez'],
                ['Phone' => '0712345703', 'Specialty' => 'Transmission & Gearbox', 'Status' => 'Active']
            ),
            Mechanic::firstOrCreate(
                ['FullName' => 'Tom Anderson'],
                ['Phone' => '0712345704', 'Specialty' => 'Brake Systems', 'Status' => 'Active']
            ),
        ];

        // Create sample suppliers
        $suppliers = [
            Supplier::firstOrCreate(
                ['CompanyName' => 'Premium Auto Parts'],
                ['ContactPerson' => 'Robert Lee', 'Phone' => '0712345801', 'Email' => 'sales@premiumauto.com', 'Address' => 'Industrial Park']
            ),
            Supplier::firstOrCreate(
                ['CompanyName' => 'Quality Spare Parts Ltd'],
                ['ContactPerson' => 'Lisa Chen', 'Phone' => '0712345802', 'Email' => 'info@qualityparts.com', 'Address' => 'Trade Center']
            ),
        ];

        // Create sample categories
        $categories = [
            Category::firstOrCreate(
                ['CategoryName' => 'Engine Parts'],
                ['Description' => 'Parts for engine repair and maintenance']
            ),
            Category::firstOrCreate(
                ['CategoryName' => 'Brake Components'],
                ['Description' => 'Brake pads, discs, and related parts']
            ),
            Category::firstOrCreate(
                ['CategoryName' => 'Electrical'],
                ['Description' => 'Batteries, alternators, and electrical components']
            ),
            Category::firstOrCreate(
                ['CategoryName' => 'Filters'],
                ['Description' => 'Oil filters, air filters, fuel filters']
            ),
        ];

        // Create sample spare parts (ensure at least 10)
        $parts = [
            SparePart::firstOrCreate(
                ['PartName' => 'Brake Pad Set - Front'],
                ['UnitPrice' => 25000, 'Quantity' => 15, 'ReorderLevel' => 5, 'CategoryID' => $categories[1]->CategoryID, 'SupplierID' => $suppliers[0]->SupplierID]
            ),
            SparePart::firstOrCreate(
                ['PartName' => 'Brake Pad Set - Rear'],
                ['UnitPrice' => 22000, 'Quantity' => 12, 'ReorderLevel' => 5, 'CategoryID' => $categories[1]->CategoryID, 'SupplierID' => $suppliers[0]->SupplierID]
            ),
            SparePart::firstOrCreate(
                ['PartName' => 'Oil Filter'],
                ['UnitPrice' => 8000, 'Quantity' => 30, 'ReorderLevel' => 10, 'CategoryID' => $categories[3]->CategoryID, 'SupplierID' => $suppliers[1]->SupplierID]
            ),
            SparePart::firstOrCreate(
                ['PartName' => 'Air Filter'],
                ['UnitPrice' => 6000, 'Quantity' => 25, 'ReorderLevel' => 8, 'CategoryID' => $categories[3]->CategoryID, 'SupplierID' => $suppliers[1]->SupplierID]
            ),
            SparePart::firstOrCreate(
                ['PartName' => 'Spark Plugs (Pack of 4)'],
                ['UnitPrice' => 12000, 'Quantity' => 20, 'ReorderLevel' => 5, 'CategoryID' => $categories[0]->CategoryID, 'SupplierID' => $suppliers[0]->SupplierID]
            ),
            SparePart::firstOrCreate(
                ['PartName' => 'Battery 12V 60Ah'],
                ['UnitPrice' => 65000, 'Quantity' => 8, 'ReorderLevel' => 2, 'CategoryID' => $categories[2]->CategoryID, 'SupplierID' => $suppliers[0]->SupplierID]
            ),
            SparePart::firstOrCreate(
                ['PartName' => 'Alternator'],
                ['UnitPrice' => 85000, 'Quantity' => 4, 'ReorderLevel' => 1, 'CategoryID' => $categories[2]->CategoryID, 'SupplierID' => $suppliers[1]->SupplierID]
            ),
            SparePart::firstOrCreate(
                ['PartName' => 'Brake Disc - Front'],
                ['UnitPrice' => 18000, 'Quantity' => 10, 'ReorderLevel' => 3, 'CategoryID' => $categories[1]->CategoryID, 'SupplierID' => $suppliers[0]->SupplierID]
            ),
            SparePart::firstOrCreate(
                ['PartName' => 'Water Pump'],
                ['UnitPrice' => 45000, 'Quantity' => 6, 'ReorderLevel' => 2, 'CategoryID' => $categories[0]->CategoryID, 'SupplierID' => $suppliers[1]->SupplierID]
            ),
            SparePart::firstOrCreate(
                ['PartName' => 'Fuel Pump'],
                ['UnitPrice' => 55000, 'Quantity' => 5, 'ReorderLevel' => 1, 'CategoryID' => $categories[0]->CategoryID, 'SupplierID' => $suppliers[0]->SupplierID]
            ),
        ];

        // Create sample vehicles
        $vehicles = [
            Vehicle::firstOrCreate(
                ['PlateNumber' => 'RW-2024-AB001'],
                [
                    'CustomerID' => $customers[0]->CustomerID,
                    'Manufacturer' => 'Toyota',
                    'Model' => 'Corolla',
                    'Year' => 2020,
                    'ChassisNumber' => 'JTDDR32K120123456',
                    'EngineNumber' => '2ZR-FE-987654',
                    'FuelType' => 'Petrol',
                    'Transmission' => 'Automatic',
                    'Mileage' => 45000
                ]
            ),
            Vehicle::firstOrCreate(
                ['PlateNumber' => 'RW-2023-CD002'],
                [
                    'CustomerID' => $customers[1]->CustomerID,
                    'Manufacturer' => 'Honda',
                    'Model' => 'Civic',
                    'Year' => 2019,
                    'ChassisNumber' => 'JHMCV56K230000789',
                    'EngineNumber' => 'K20Z2-567890',
                    'FuelType' => 'Petrol',
                    'Transmission' => 'Manual',
                    'Mileage' => 52000
                ]
            ),
            Vehicle::firstOrCreate(
                ['PlateNumber' => 'RW-2022-EF003'],
                [
                    'CustomerID' => $customers[2]->CustomerID,
                    'Manufacturer' => 'Nissan',
                    'Model' => 'Altima',
                    'Year' => 2021,
                    'ChassisNumber' => 'JN1CA67D330000123',
                    'EngineNumber' => 'QR25DE-123456',
                    'FuelType' => 'Petrol',
                    'Transmission' => 'Automatic',
                    'Mileage' => 32000
                ]
            ),
        ];

        // Create sample repair jobs - some completed (to test vehicles_serviced count)
        $user = User::where('Role', 'Admin')->first() ?? User::first();
        if ($user) {
            // Completed jobs (these count as "Vehicles Serviced")
            RepairJob::firstOrCreate(
                ['JobID' => null], // Force create new
                [
                    'VehicleID' => $vehicles[0]->VehicleID,
                    'MechanicID' => $mechanics[0]->MechanicID,
                    'UserID' => $user->UserID,
                    'Description' => 'Routine maintenance - oil change and filter replacement',
                    'StartDate' => now()->subDays(30)->toDateString(),
                    'EndDate' => now()->subDays(28)->toDateString(),
                    'Status' => 'Completed'
                ]
            );

            RepairJob::firstOrCreate(
                ['JobID' => null],
                [
                    'VehicleID' => $vehicles[1]->VehicleID,
                    'MechanicID' => $mechanics[1]->MechanicID,
                    'UserID' => $user->UserID,
                    'Description' => 'Brake pad replacement and brake fluid check',
                    'StartDate' => now()->subDays(20)->toDateString(),
                    'EndDate' => now()->subDays(18)->toDateString(),
                    'Status' => 'Delivered'
                ]
            );

            RepairJob::firstOrCreate(
                ['JobID' => null],
                [
                    'VehicleID' => $vehicles[2]->VehicleID,
                    'MechanicID' => $mechanics[2]->MechanicID,
                    'UserID' => $user->UserID,
                    'Description' => 'Engine diagnostic and spark plug replacement',
                    'StartDate' => now()->subDays(10)->toDateString(),
                    'EndDate' => now()->subDays(8)->toDateString(),
                    'Status' => 'Ready'
                ]
            );

            // Pending job (not counted in vehicles_serviced)
            RepairJob::firstOrCreate(
                ['JobID' => null],
                [
                    'VehicleID' => $vehicles[0]->VehicleID,
                    'MechanicID' => $mechanics[3]->MechanicID,
                    'UserID' => $user->UserID,
                    'Description' => 'Electrical system check and battery test',
                    'StartDate' => now()->toDateString(),
                    'EndDate' => null,
                    'Status' => 'Pending'
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This is a data seeder, not a schema migration, so we don't drop tables on rollback
    }
};
