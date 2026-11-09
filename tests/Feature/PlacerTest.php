<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Services\SchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlacerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-07 10:30', 'America/Belem'));
        Carbon::setTestNow(CarbonImmutable::now());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function slot(int $hour = 14): string
    {
        return '2026-11-08T'.sprintf('%02d', $hour).':00';
    }

    private function book(Space $space, ?User $user = null, int $hour = 14): Reservation
    {
        return app(SchedulingService::class)->reserve($user ?? User::factory()->create(), $space->id, $this->slot($hour));
    }

    public function test_registration_never_grants_admin_and_hashes_password(): void
    {
        $this->post('/register', ['name' => 'Cliente', 'email' => ' CLIENTE@EXAMPLE.COM ', 'phone' => '(91) 99999-9999', 'password' => 'password123', 'password_confirmation' => 'password123', 'is_admin' => true])->assertRedirect('/');
        $user = User::where('email', 'cliente@example.com')->firstOrFail();
        $this->assertFalse($user->is_admin);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->post('/login', ['email' => 'CLIENTE@EXAMPLE.COM', 'password' => 'password123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/login', ['email' => 'wrong@example.com', 'password' => 'wrong']);
        } $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Muitas tentativas', session('errors')->first('email'));
    }

    public function test_guest_and_client_permissions(): void
    {
        $this->post('/reservas')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_private_reservation_and_cancel_are_protected(): void
    {
        $reservation = $this->book(Space::factory()->create());
        $this->actingAs(User::factory()->create())->get(route('reservations.show', $reservation))->assertForbidden();
        $this->post(route('reservations.cancel', $reservation), ['confirm' => 1])->assertForbidden();
    }

    public function test_server_price_and_owner_are_used_and_confirmation_renders(): void
    {
        $space = Space::factory()->create(['identifier' => '001']);
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post('/reservas', ['space_id' => $space->id, 'starts_at' => $this->slot(), 'total_price' => '0.01', 'status' => 'cancelled', 'user_id' => 999]);
        $reservation = Reservation::where('user_id', $user->id)->firstOrFail();
        $response->assertRedirect(route('reservations.show', $reservation));
        $this->assertSame('100.00', $reservation->total_price);
        $this->assertSame('confirmed', $reservation->status);
        $this->get(route('reservations.show', $reservation))->assertOk()->assertSee('Reserva #'.$reservation->id);
        $this->get('/minhas-reservas')->assertOk()->assertSee($space->title);
        $space->update(['hourly_price' => '250.00']);
        $this->assertSame('100.00', $reservation->fresh()->total_price);
    }

    public function test_overlap_rejected_and_adjacent_allowed(): void
    {
        $space = Space::factory()->create();
        $this->book($space);
        $this->book($space, null, 15);
        $this->expectException(ValidationException::class);
        $this->book($space);
    }

    public function test_cancellation_releases_slot_and_preserves_history(): void
    {
        $space = Space::factory()->create();
        $reservation = $this->book($space);
        $this->actingAs($reservation->user)->post(route('reservations.cancel', $reservation), ['confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $reservation->fresh()->status);
        $this->assertNotNull($reservation->fresh()->cancelled_at);
        $this->book($space);
        $this->assertSame(2, $space->reservations()->count());
    }

    public function test_cancellation_requires_confirmation(): void
    {
        $reservation = $this->book(Space::factory()->create());
        $this->actingAs($reservation->user)->post(route('reservations.cancel', $reservation))->assertSessionHasErrors('confirm');
        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    public function test_past_reservation_can_only_be_cancelled_by_admin(): void
    {
        $reservation = Reservation::factory()->create(['starts_at' => now()->subHours(2), 'ends_at' => now()->subHour()]);
        $this->actingAs($reservation->user)->post(route('reservations.cancel', $reservation), ['confirm' => 1])->assertSessionHasErrors();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('reservations.cancel', $reservation), ['confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $reservation->fresh()->status);
    }

    public function test_invalid_slots_rejected(): void
    {
        $space = Space::factory()->create();
        $this->actingAs(User::factory()->create());
        foreach (['2026-11-07T10:00', '2026-11-08T07:00', '2026-11-08T22:00', '2026-11-08T14:30', '2027-01-01T14:00', 'garbage'] as $slot) {
            $this->post('/reservas', ['space_id' => $space->id, 'starts_at' => $slot])->assertSessionHasErrors('starts_at');
        }
        $this->assertSame(0, $space->reservations()->count());
    }

    public function test_zero_days_allows_only_today(): void
    {
        $space = Space::factory()->create();
        $space->category->update(['max_advance_days' => 0]);
        $this->actingAs(User::factory()->create());
        $this->post('/reservas', ['space_id' => $space->id, 'starts_at' => '2026-11-07T11:00'])->assertSessionHasNoErrors();
        $this->post('/reservas', ['space_id' => $space->id, 'starts_at' => $this->slot()])->assertSessionHasErrors('starts_at');
    }

    public function test_inactive_space_or_category_rejected_and_hidden(): void
    {
        $space = Space::factory()->create(['is_active' => false]);
        $this->get('/')->assertDontSee($space->title);
        $this->actingAs(User::factory()->create())->post('/reservas', ['space_id' => $space->id, 'starts_at' => $this->slot()])->assertSessionHasErrors('space_id');
        $space->update(['is_active' => true]);
        $space->category->update(['is_active' => false]);
        $this->post('/reservas', ['space_id' => $space->id, 'starts_at' => $this->slot()])->assertSessionHasErrors('space_id');
        $this->get(route('spaces.show', $space))->assertNotFound();
    }

    public function test_blocks_conflicts_and_removal(): void
    {
        $space = Space::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $schedule = app(SchedulingService::class);
        $block = $schedule->block($admin, $space->id, $this->slot(), $this->slot(16), 'Manutenção');
        $this->actingAs(User::factory()->create())->post('/reservas', ['space_id' => $space->id, 'starts_at' => $this->slot()])->assertSessionHasErrors('starts_at');
        $this->actingAs($admin)->post('/admin/bloqueios', ['space_id' => $space->id, 'starts_at' => $this->slot(15), 'ends_at' => $this->slot(17)])->assertSessionHasErrors('starts_at');
        $schedule->unblock($block);
        $this->book($space);
        $this->post('/admin/bloqueios', ['space_id' => $space->id, 'starts_at' => $this->slot(), 'ends_at' => $this->slot(15)])->assertSessionHasErrors('starts_at');
    }

    public function test_reschedule_revalidates_and_recalculates_only_when_changed(): void
    {
        $space = Space::factory()->create();
        $reservation = $this->book($space);
        $space->update(['hourly_price' => '150.00']);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $this->put(route('admin.reservations.update', $reservation), ['space_id' => $space->id, 'starts_at' => $this->slot()])->assertSessionHasNoErrors();
        $this->assertSame('100.00', $reservation->fresh()->total_price);
        $this->put(route('admin.reservations.update', $reservation), ['space_id' => $space->id, 'starts_at' => $this->slot(15)])->assertSessionHasNoErrors();
        $this->assertSame('150.00', $reservation->fresh()->total_price);
        $this->book($space, null, 16);
        $this->put(route('admin.reservations.update', $reservation), ['space_id' => $space->id, 'starts_at' => $this->slot(16)])->assertSessionHasErrors('starts_at');
        $this->assertSame(18, $reservation->fresh()->starts_at->hour);
    }

    public function test_admin_forms_render_and_save_and_uniqueness(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::factory()->create(['name' => 'Quadra']);
        $space = Space::factory()->create(['category_id' => $category->id, 'identifier' => '001']);
        $reservation = $this->book($space);
        $this->assertSame('Quadra 001', $space->title);
        $this->actingAs($admin);
        foreach (['/admin', '/admin/categorias', '/admin/categorias/criar', '/admin/categorias/'.$category->id.'/editar', '/admin/espacos', '/admin/espacos/criar', '/admin/espacos/'.$space->id.'/editar', '/admin/reservas', '/admin/reservas/'.$reservation->id, '/admin/reservas/'.$reservation->id.'/editar', '/admin/bloqueios'] as $url) {
            $this->get($url)->assertOk();
        }
        $data = ['category_id' => $category->id, 'identifier' => '001', 'capacity' => 30, 'hourly_price' => '80.50', 'is_active' => 1];
        $this->post('/admin/espacos', $data)->assertSessionHasErrors('identifier');
        $data['category_id'] = Category::factory()->create()->id;
        $this->post('/admin/espacos', $data)->assertSessionHasNoErrors();
        $this->put(route('admin.spaces.update', $space), ['category_id' => $category->id, 'identifier' => '001', 'capacity' => 20, 'hourly_price' => '100', 'is_active' => 0])->assertSessionHas('success');
        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertStringContainsString('preservada', session('success'));
        $this->post('/admin/categorias', ['name' => 'Nova categoria', 'max_advance_days' => 365, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->post('/admin/categorias', ['name' => 'Inválida', 'max_advance_days' => 366, 'is_active' => 1])->assertSessionHasErrors('max_advance_days');
    }

    public function test_availability_matches_reservations_and_blocks(): void
    {
        $space = Space::factory()->create();
        $this->book($space);
        $admin = User::factory()->create(['is_admin' => true]);
        app(SchedulingService::class)->block($admin, $space->id, $this->slot(16), $this->slot(17), null);
        $this->get(route('spaces.show', $space).'?date=2026-11-08')->assertOk()->assertSee('Ocupado')->assertSee('Bloqueado')->assertSee('Livre');
        $slots = app(SchedulingService::class)->slots($space, '2026-11-08');
        $this->assertSame('Ocupado', $slots[6]['label']);
        $this->assertSame('Livre', $slots[7]['label']);
        $this->assertSame('Bloqueado', $slots[8]['label']);
    }

    public function test_public_pages_render_and_invalid_date_is_handled(): void
    {
        $space = Space::factory()->create();
        foreach (['/', '/login', '/register', route('spaces.show', $space)] as $url) {
            $this->get($url)->assertOk();
        } $this->get(route('spaces.show', $space).'?date=invalid')->assertSessionHasErrors('date');
    }
}
