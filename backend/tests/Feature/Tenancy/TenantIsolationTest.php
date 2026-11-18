<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantContextException;
use App\Tenancy\Exceptions\TenantMismatchException;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\Fixtures\Models\Gadget;
use Tests\Fixtures\Models\Widget;
use Tests\TestCase;

/**
 * The reference cross-tenant isolation suite. Every tenant-owned module must
 * prove the same properties for its own models.
 */
class TenantIsolationTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private Tenant $globex;

    private Widget $acmeWidget;

    private Widget $globexWidget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');

        $this->acmeWidget = $this->asTenant($this->acme, fn () => Widget::create(['name' => 'Shared name']));
        $this->globexWidget = $this->asTenant($this->globex, fn () => Widget::create(['name' => 'Shared name']));
    }

    // --- fail closed -------------------------------------------------------

    public function test_reading_without_a_tenant_throws(): void
    {
        $this->expectException(NoTenantContextException::class);

        Widget::query()->get();
    }

    public function test_creating_without_a_tenant_throws(): void
    {
        $this->expectException(NoTenantContextException::class);

        Widget::create(['name' => 'Orphan']);
    }

    public function test_counting_and_aggregates_without_a_tenant_throw(): void
    {
        $this->expectException(NoTenantContextException::class);

        Widget::query()->count();
    }

    // --- reads -------------------------------------------------------------

    public function test_queries_only_return_the_current_tenants_rows(): void
    {
        $this->asTenant($this->acme, function () {
            $this->assertEquals([$this->acmeWidget->id], Widget::query()->pluck('id')->all());
            $this->assertSame(1, Widget::query()->count());
        });
    }

    public function test_another_tenants_row_cannot_be_found_by_id(): void
    {
        $this->asTenant($this->acme, function () {
            $this->assertNull(Widget::query()->find($this->globexWidget->id));
            $this->assertNull(Widget::query()->where('id', $this->globexWidget->id)->first());
        });
    }

    public function test_or_where_clauses_cannot_escape_the_tenant_filter(): void
    {
        $this->asTenant($this->acme, function () {
            $ids = Widget::query()
                ->where('name', 'nope')
                ->orWhere('id', $this->globexWidget->id)
                ->pluck('id');

            $this->assertCount(0, $ids);
        });
    }

    public function test_bypassing_the_scope_is_explicit(): void
    {
        $this->assertSame(2, Widget::withoutGlobalScope(TenantScope::class)->count());
    }

    // --- writes ------------------------------------------------------------

    public function test_create_fills_tenant_id_from_context(): void
    {
        $widget = $this->asTenant($this->acme, fn () => Widget::create(['name' => 'New']));

        $this->assertSame($this->acme->id, $widget->tenant_id);
    }

    public function test_create_for_another_tenant_is_rejected(): void
    {
        $this->expectException(TenantMismatchException::class);

        $this->asTenant($this->acme, fn () => Widget::create(['name' => 'Sneaky', 'tenant_id' => $this->globex->id]));
    }

    public function test_tenant_id_can_never_change(): void
    {
        $this->expectException(TenantMismatchException::class);

        $this->asTenant($this->acme, function () {
            $widget = Widget::query()->findOrFail($this->acmeWidget->id);
            $widget->tenant_id = $this->globex->id;
            $widget->save();
        });
    }

    public function test_a_model_of_another_tenant_cannot_be_updated_or_deleted(): void
    {
        $foreign = Widget::withoutGlobalScope(TenantScope::class)->findOrFail($this->globexWidget->id);

        $this->asTenant($this->acme, function () use ($foreign) {
            try {
                $foreign->update(['name' => 'Hijacked']);
                $this->fail('Update of a foreign tenant row was allowed.');
            } catch (TenantMismatchException) {
            }

            try {
                $foreign->delete();
                $this->fail('Delete of a foreign tenant row was allowed.');
            } catch (TenantMismatchException) {
            }
        });

        $this->assertSame('Shared name', Widget::withoutGlobalScope(TenantScope::class)->findOrFail($this->globexWidget->id)->name);
    }

    public function test_mass_update_and_delete_only_touch_the_current_tenant(): void
    {
        $this->asTenant($this->acme, function () {
            Widget::query()->update(['name' => 'Renamed']);
            Widget::query()->delete();
        });

        $remaining = Widget::withoutGlobalScope(TenantScope::class)->pluck('name', 'id');

        $this->assertEquals([$this->globexWidget->id => 'Shared name'], $remaining->all());
    }

    // --- database-level guarantees ----------------------------------------

    public function test_unique_keys_are_per_tenant(): void
    {
        // Same name in two tenants was allowed in setUp; a duplicate within one tenant is not.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->asTenant($this->acme, fn () => Widget::create(['name' => 'Shared name']));
    }

    public function test_relations_within_a_tenant_are_allowed(): void
    {
        $gadget = $this->asTenant($this->acme, fn () => Gadget::create(['widget_id' => $this->acmeWidget->id]));

        $this->assertSame($this->acme->id, $gadget->tenant_id);
    }

    public function test_the_database_rejects_cross_tenant_references_even_bypassing_eloquent(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint fails/i');

        // Raw insert: acme gadget pointing at globex's widget.
        DB::table('fixture_gadgets')->insert([
            'id' => (string) str()->uuid7(),
            'tenant_id' => $this->acme->id,
            'widget_id' => $this->globexWidget->id,
        ]);
    }

    public function test_a_tenant_with_data_cannot_be_deleted(): void
    {
        $this->expectException(QueryException::class);

        $this->acme->delete();
    }
}
