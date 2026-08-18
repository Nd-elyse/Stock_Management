// Test the toSnakeCase conversion
function toSnakeCase(value) {
  if (Array.isArray(value)) return value.map(toSnakeCase);
  if (value && typeof value === 'object' && !(value instanceof File) && !(value instanceof Blob)) {
    return Object.fromEntries(
      Object.entries(value).map(([k, v]) => [
        k
          .replace(/([a-z0-9])([A-Z])/g, '$1_$2')           // FooBar -> Foo_Bar
          .replace(/([A-Z]+)([A-Z][a-z])/g, '$1_$2')       // HTTPServer -> HTTP_Server
          .replace(/ID$/i, 'id')                             // FooID -> Fooid (then lowercase) -> fooid
          .toLowerCase(),
        toSnakeCase(v),
      ])
    );
  }
  return value;
}

const testJob = {
  JobID: null,
  VehicleID: 1,
  MechanicID: 2,
  UserID: 3,
  Description: 'Test job',
  Status: 'Pending',
  StartDate: '2026-08-18',
  EndDate: null,
};

console.log('Original:', testJob);
const converted = toSnakeCase(testJob);
console.log('Converted:', converted);

// Expected:
// {
//   job_id: null,
//   vehicle_id: 1,
//   mechanic_id: 2,
//   user_id: 3,
//   description: 'Test job',
//   status: 'Pending',
//   start_date: '2026-08-18',
//   end_date: null,
// }

console.log('\nVerification:');
console.log('job_id:', converted.job_id === null ? '✓' : '✗ Got: ' + converted.job_id);
console.log('vehicle_id:', converted.vehicle_id === 1 ? '✓' : '✗ Got: ' + converted.vehicle_id);
console.log('mechanic_id:', converted.mechanic_id === 2 ? '✓' : '✗ Got: ' + converted.mechanic_id);
console.log('user_id:', converted.user_id === 3 ? '✓' : '✗ Got: ' + converted.user_id);
console.log('description:', converted.description === 'Test job' ? '✓' : '✗ Got: ' + converted.description);
console.log('status:', converted.status === 'Pending' ? '✓' : '✗ Got: ' + converted.status);
console.log('start_date:', converted.start_date === '2026-08-18' ? '✓' : '✗ Got: ' + converted.start_date);
console.log('end_date:', converted.end_date === null ? '✓' : '✗ Got: ' + converted.end_date);
