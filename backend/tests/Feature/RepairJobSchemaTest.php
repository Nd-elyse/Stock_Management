<?php

namespace Tests\Feature;

use App\Models\RepairJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepairJobSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_jobs_table_has_description_column_and_accepts_description():
    {
        $this->assertTrue(Schema::hasColumn('repairjobs', 'Description'));

        $job = RepairJob::create([
            'VehicleID' => 1,
            'MechanicID' => null,
            'UserID' => 1,
            'Description' => 'Front brake worn on inspection',
            'StartDate' => '2024-01-01',
            'EndDate' => null,
            'Status' => 'Pending',
        ]);

        $this->assertSame('Front brake worn on inspection', $job->Description);
    }
}
