<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Spa\SpaBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

final class SpaBookingTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    private const API = '/api/spa/bookings';

    private const APPOINTMENTS = ['spa.appointments.view', 'spa.appointments.manage'];

    private const CUSTOMERS = ['spa.customers.view', 'spa.customers.manage'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['spa.catalog_enabled' => true, 'spa.booking_enabled' => true]);
    }

    public function test_permissions_control_api_and_ui_capabilities_without_using_role_names(): void
    {
        $this->getJson(self::API.'/capabilities')->assertUnauthorized();
        $this->login(['spa.appointments.view']);
        $this->getJson(self::API.'/capabilities')->assertOk()
            ->assertJsonPath('viewAppointments', true)
            ->assertJsonPath('viewCustomers', false)
            ->assertJsonPath('createAppointment', false)
            ->assertJsonPath('rescheduleAppointment', false)
            ->assertJsonPath('cancelAppointment', false);
        $this->getJson(self::API.'/appointments?date=2026-11-20')->assertOk();
        $this->getJson(self::API.'/customers')->assertForbidden();
        $this->postJson(self::API.'/appointments', [])->assertForbidden();
        $this->postJson(self::API.'/appointments/'.Str::uuid().'/cancel', [])->assertForbidden();
        $this->login([...self::APPOINTMENTS, ...self::CUSTOMERS], 'LOCATION');
        $this->getJson(self::API.'/capabilities')->assertOk()->assertJsonPath('viewAppointments', false);
        $this->getJson(self::API.'/appointments?date=2026-11-20')->assertForbidden();
        $this->postJson(self::API.'/customers/lookup', ['phone' => '0901234567'])->assertForbidden();
    }

    public function test_disabled_booking_and_incomplete_migration_accept_no_data(): void
    {
        $this->login([...self::APPOINTMENTS, ...self::CUSTOMERS]);
        config(['spa.booking_enabled' => false]);
        $this->getJson(self::API.'/capabilities')->assertOk()->assertJsonPath('enabled', false);
        $this->postJson(self::API.'/customers', $this->customer())->assertConflict();
        $this->assertDatabaseCount('spa_customers', 0);
        config(['spa.booking_enabled' => true]);
        DB::connection()->getSchemaBuilder()->drop('spa_appointment_events');
        $this->getJson(self::API.'/appointments?date=2026-11-20')->assertConflict();
        $this->postJson(self::API.'/customers', $this->customer())->assertConflict();
    }

    public function test_creates_inline_and_preserves_original_price_and_duration_through_reschedule_and_cancel(): void
    {
        $creator = $this->login([...self::APPOINTMENTS, ...self::CUSTOMERS]);
        $service = $this->service($creator);
        $id = (string) Str::uuid();
        $input = $this->booking($id, $service);
        $this->getJson(self::API.'/services')->assertOk()->assertJsonPath('data.0.id', $service);
        $this->postJson(self::API.'/appointments', $input)->assertCreated()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.end_time', '10:00')
            ->assertJsonPath('data.events.0.actor_name', $creator->name)
            ->assertJsonMissingPath('data.customer.phone');
        $this->postJson(self::API.'/appointments', $input)->assertCreated();
        $this->assertDatabaseCount('spa_customers', 1);
        $this->assertDatabaseCount('spa_appointments', 1);
        $this->assertDatabaseCount('spa_appointment_events', 1);
        $this->postJson(self::API.'/customers/lookup', ['phone' => '+84 901 234 567'])->assertOk()
            ->assertJsonPath('data.phone', '0901234567');

        DB::table('spa_catalog_items')->where('id', $service)->update(['name' => 'Giá mới', 'price' => 900000, 'duration_minutes' => 30]);
        $editor = $this->login(self::APPOINTMENTS);
        $this->getJson(self::API.'/appointments?date=2026-11-20')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->patchJson(self::API.'/appointments/'.$id.'/reschedule', ['date' => '2026-11-21', 'start_time' => '09:15', 'version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.end_time', '10:15')
            ->assertJsonPath('data.service.price', 250000)->assertJsonPath('data.service.duration_minutes', 60)
            ->assertJsonPath('data.events.1.actor_name', $editor->name);
        $this->postJson(self::API.'/appointments/'.$id.'/cancel', ['reason' => 'Khách bận', 'version' => 1])->assertConflict();
        $this->postJson(self::API.'/appointments/'.$id.'/cancel', ['reason' => 'Khách bận', 'version' => 2])->assertOk();
        $this->getJson(self::API.'/appointments/'.$id)->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.events.2.reason', 'Khách bận')->assertJsonCount(3, 'data.events');
        $this->getJson(self::API.'/appointments?date=2026-11-20')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_booking_rolls_back_invalid_inline_customer_and_rejects_same_customer_overlap(): void
    {
        $user = $this->login([...self::APPOINTMENTS, ...self::CUSTOMERS]);
        $id = (string) Str::uuid();
        $this->postJson(self::API.'/appointments', $this->booking($id, (string) Str::uuid()))->assertUnprocessable()->assertJsonValidationErrors('service_id');
        $this->assertDatabaseCount('spa_customers', 0);
        $service = $this->service($user);
        $payload = $this->booking($id, $service);
        $saved = $this->postJson(self::API.'/appointments', $payload)->assertCreated();
        $this->postJson(self::API.'/customers', [...$this->customer(), 'phone' => '84 901 234 567'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        unset($payload['customer']);
        $payload['customer_id'] = $saved->json('data.customer.id');
        $payload['id'] = (string) Str::uuid();
        $this->postJson(self::API.'/appointments', [...$payload, 'start_time' => '09:30'])->assertUnprocessable()->assertJsonValidationErrors('start_time');
        $this->postJson(self::API.'/appointments', [...$payload, 'start_time' => '10:00'])->assertCreated();
        $this->assertDatabaseCount('spa_appointments', 2);
    }

    public function test_inline_customer_creation_requires_customer_write_grant_but_can_book_existing_customer(): void
    {
        $owner = $this->login([...self::APPOINTMENTS, ...self::CUSTOMERS]);
        $service = $this->service($owner);
        $customer = $this->postJson(self::API.'/customers', $this->customer())->assertCreated();
        $this->login([...self::APPOINTMENTS, 'spa.customers.view']);
        $this->getJson(self::API.'/capabilities')->assertJsonPath('createAppointment', true)->assertJsonPath('createCustomer', false);
        $input = $this->booking((string) Str::uuid(), $service);
        $this->postJson(self::API.'/appointments', $input)->assertForbidden();
        unset($input['customer']);
        $this->postJson(self::API.'/appointments', [...$input, 'customer_id' => $customer->json('data.id')])->assertCreated();
    }

    public function test_customer_version_prevents_overwrite_and_foreign_records_are_opaque(): void
    {
        $actor = $this->login([...self::APPOINTMENTS, ...self::CUSTOMERS]);
        $customer = $this->postJson(self::API.'/customers', $this->customer())->assertCreated()->json('data');
        $path = self::API.'/customers/'.$customer['id'];
        $edit = [...$this->customer(), 'name' => 'Khách đã cập nhật', 'version' => 1];
        $this->patchJson($path, $edit)->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($path, $edit)->assertConflict();

        $foreign = $this->createMembership(User::factory()->create(), organizationCode: 'OTHER_SPA')['organization_id'];
        $foreignCustomer = app(SpaBookingService::class)->createCustomer($foreign, $actor->id, [...$this->customer(), 'phone' => '0901111111']);
        $this->getJson(self::API.'/customers')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(self::API.'/customers/lookup', ['phone' => $foreignCustomer['phone']])->assertOk()->assertJsonPath('data', null);
        $this->patchJson(self::API.'/customers/'.$foreignCustomer['id'], $edit)->assertNotFound();
    }

    private function login(array $permissions, string $scope = 'ORGANIZATION'): User
    {
        $actor = User::factory()->create();
        $membership = $this->createMembership($actor);
        $role = $this->createRole();
        foreach ($permissions as $permission) {
            $this->grantPermission($role, $this->createPermission($permission), $scope);
        }
        $this->attachRole($membership['membership_id'], $role, true);
        $this->app['auth']->forgetGuards();
        $this->actingAs($actor, 'web');

        return $actor;
    }

    private function service(User $actor): string
    {
        $id = (string) Str::uuid();
        DB::table('spa_catalog_items')->insert([
            'id' => $id, 'organization_id' => $actor->organizationMemberships()->firstOrFail()->organization_id,
            'kind' => 'service', 'name' => 'Chăm sóc da', 'identity_hash' => hash('sha256', $id), 'price' => 250000,
            'duration_minutes' => 60, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function customer(): array
    {
        return ['name' => 'Khách thử Spa', 'phone' => '0901 234 567', 'note' => ''];
    }

    private function booking(string $id, string $service): array
    {
        return ['id' => $id, 'service_id' => $service, 'customer' => $this->customer(), 'date' => '2026-11-20', 'start_time' => '09:00'];
    }
}
