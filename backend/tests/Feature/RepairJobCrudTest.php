<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\RepairJob;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepairJobCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Mechanic $mechanic;
    protected Customer $customer;
    protected Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users and entities
        $this->admin = User::create([
            'Username' => 'admin',
            'Password' => bcrypt('password'),
            'Role' => 'Admin',
            'FullName' => 'Admin User',
            'Email' => 'admin@test.com',
            'Status' => 'Active',
        ]);

        $this->mechanic = Mechanic::create([
            'FullName' => 'John Mechanic',
            'Phone' => '0712345678',
            'Specialty' => 'General Maintenance',
            'Status' => 'Active',
        ]);

        $this->customer = Customer::create([
            'FullName' => 'Test Customer',
            'Phone' => '0787654321',
            'Email' => 'customer@test.com',
            'Address' => '123 Main St',
            'Status' => 'Active',
        ]);

        $this->vehicle = Vehicle::create([
            'CustomerID' => $this->customer->CustomerID,
            'PlateNumber' => 'PLATE001',
            'Manufacturer' => 'Toyota',
            'Model' => 'Corolla',
            'Year' => 2020,
            'ChassisNumber' => 'CHASSIS001',
            'EngineNumber' => 'ENGINE001',
            'FuelType' => 'Petrol',
            'Transmission' => 'Manual',
            'Mileage' => 50000,
        ]);
    }

    public function test_create_repair_job_with_description()
     {
        $this->actingAs($this->admin);

        $response = $this->postJson('/api/jobs', [
            'vehicle_id' => $this->vehicle->VehicleID,
            'mechanic_id' => $this->mechanic->MechanicID,
            'description' => 'Front brake pads replacement needed',
            'status' => 'Pending',
            'start_date' => '2026-08-18',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.Description', 'Front brake pads replacement needed');

        $this->assertDatabaseHas('repairjobs', [
            'Description' => 'Front brake pads replacement needed',
            'Status' => 'Pending',
            'VehicleID' => $this->vehicle->VehicleID,
        ]);
    }

    public function test_read_repair_job()
    {
        $job = RepairJob::create([
            'VehicleID' => $this->vehicle->VehicleID,
            'MechanicID' => $this->mechanic->MechanicID,
            'UserID' => $this->admin->UserID,
            'Description' => 'Oil change and filter replacement',
            'StartDate' => '2026-08-18',
            'Status' => 'In Progress',
        ]);

        $this->actingAs($this->admin);

        $response = $this->getJson("/api/jobs/{$job->JobID}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.Description', 'Oil change and filter replacement');
        $response->assertJsonPath('data.Status', 'In Progress');
    }

    public function test_update_repair_job_description()
    {
        $job = RepairJob::create([
            'VehicleID' => $this->vehicle->VehicleID,
            'MechanicID' => $this->mechanic->MechanicID,
            'UserID' => $this->admin->UserID,
            'Description' => 'Original description',
            'StartDate' => '2026-08-18',
            'Status' => 'Pending',
        ]);

        $this->actingAs($this->admin);

        $response = $this->putJson("/api/jobs/{$job->JobID}", [
            'description' => 'Updated description - engine noise investigation',
            'status' => 'Diagnosed',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.Description', 'Updated description - engine noise investigation');
        $response->assertJsonPath('data.Status', 'Diagnosed');

        $this->assertDatabaseHas('repairjobs', [
            'JobID' => $job->JobID,
            'Description' => 'Updated description - engine noise investigation',
            'Status' => 'Diagnosed',
        ]);
    }

    public function test_delete_repair_job()
    {
        $job = RepairJob::create([
            'VehicleID' => $this->vehicle->VehicleID,
            'MechanicID' => $this->mechanic->MechanicID,
            'UserID' => $this->admin->UserID,
            'Description' => 'Test job to delete',
            'StartDate' => '2026-08-18',
            'Status' => 'Pending',
        ]);

        $jobId = $job->JobID;

        $this->actingAs($this->admin);

        $response = $this->deleteJson("/api/jobs/{$jobId}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseMissing('repairjobs', ['JobID' => $jobId]);
    }

    public function test_create_customer_with_vehicle_in_same_request()
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/api/customers', [
            'full_name' => 'Jane Driver',
            'phone' => '0701234567',
            'email' => 'jane@test.com',
            'address' => '789 Main Road',
            'vehicle' => [
                'plate_number' => 'KBA-225K',
                'manufacturer' => 'Honda',
                'model' => 'Civic',
                'year' => '2022',
                'chassis_number' => 'CHASSIS-NEW-001',
                'engine_number' => 'ENGINE-NEW-001',
                'fuel_type' => 'Petrol',
                'transmission' => 'Automatic',
                'mileage' => 15000,
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.vehicle.PlateNumber', 'KBA-225K');

        $customerId = $response->json('data.CustomerID');

        $this->assertDatabaseHas('customers', ['FullName' => 'Jane Driver', 'Phone' => '0701234567']);
        $this->assertDatabaseHas('vehicles', ['CustomerID' => $customerId, 'PlateNumber' => 'KBA-225K']);
    }

    public function test_list_jobs_includes_description()
    {
        RepairJob::create([
            'VehicleID' => $this->vehicle->VehicleID,
            'MechanicID' => $this->mechanic->MechanicID,
            'UserID' => $this->admin->UserID,
            'Description' => 'First job description',
            'StartDate' => '2026-08-18',
            'Status' => 'Pending',
        ]);

        RepairJob::create([
            'VehicleID' => $this->vehicle->VehicleID,
            'MechanicID' => $this->mechanic->MechanicID,
            'UserID' => $this->admin->UserID,
            'Description' => 'Second job description',
            'StartDate' => '2026-08-18',
            'Status' => 'In Progress',
        ]);

        $this->actingAs($this->admin);

        $response = $this->getJson('/api/jobs');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.Description', 'Second job description');
        $response->assertJsonPath('data.1.Description', 'First job description');
    }

    public function test_repair_jobs_table_has_description_column()
    {
        $this->assertTrue(Schema::hasColumn('repairjobs', 'Description'));
    }
}
