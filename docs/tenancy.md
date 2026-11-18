# Multi-tenancy — developer guide

How tenant isolation works and the rules every module must follow. Design rationale: [architecture.md §4](architecture.md#4-multi-tenancy), [ADR 0002](adr/0002-shared-database-tenant-id.md).

**Principle: fail closed.** Touching tenant data without a tenant in context throws. Nothing ever silently returns or writes another tenant's data.

## 1. Pieces

| Piece | Location | Role |
|---|---|---|
| `Tenant` model, `tenants` table | `app/Models/Tenant.php` | The company. UUIDv7 id, DNS-safe `slug`, `status` (`active`/`suspended`). Not tenant-scoped. |
| `TenantContext` | `app/Tenancy/TenantContext.php` | Current tenant for this request / job / command. Scoped binding (reset between queued jobs). |
| Resolvers | `app/Tenancy/Resolvers/` | `single` (on-prem, single domain) or `subdomain` (SaaS), chosen by `TENANCY_RESOLVER`. |
| `tenant` middleware | `app/Http/Middleware/ResolveTenant.php` | Resolves → 404 if none, 403 if suspended → sets context. Runs before route-model binding. |
| `BelongsToTenant` trait | `app/Tenancy/Concerns/BelongsToTenant.php` | Makes a model tenant-owned (scoped reads, guarded writes). |
| Schema macros | `app/Providers/TenancyServiceProvider.php` | `tenantId()`, `tenantScopedKey()`, `tenantForeignUuid()`. |
| Queue propagation | `app/Providers/TenancyServiceProvider.php` | Every queued job carries and restores its tenant. |
| `TenantFileStorage` | `app/Tenancy/TenantFileStorage.php` | Files under `tenants/{id}/` with path-traversal protection. |
| `TenantCache` | `app/Tenancy/TenantCache.php` | Cache keys `tenant:{id}:…`. |
| `CreateTenant` / `tenant:create` | `app/Tenancy/Actions/`, `app/Console/Commands/` | The only way to create tenants (validated slug, reserved names blocked). |

## 2. Configuration

| Variable | Values | Notes |
|---|---|---|
| `TENANCY_RESOLVER` | `single` (default) / `subdomain` | |
| `TENANCY_BASE_DOMAIN` | e.g. `hrworkforce.com` | Required for `subdomain`; `acme.hrworkforce.com` → tenant `acme`. |
| `TENANCY_SINGLE_TENANT` | slug, optional | `single` with no pin requires exactly one tenant, else 404. |

Reserved slugs (`www`, `api`, `admin`, …) live in `config/tenancy.php`.

```bash
php artisan tenant:create acme "Acme Ltd"
```

## 3. Writing a tenant-owned module

### Migration

```php
Schema::create('departments', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->tenantId();                      // tenant_id + FK to tenants (restrict on delete)
    $table->string('name');
    $table->timestamps();

    $table->tenantScopedKey();               // UNIQUE(tenant_id, id) — lets others reference it safely
    $table->unique(['tenant_id', 'name']);   // business uniqueness is ALWAYS per tenant
});

Schema::create('employees', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->tenantId();
    $table->tenantForeignUuid('department_id', 'departments')->nullable();
    // FK (tenant_id, department_id) -> departments(tenant_id, id):
    // the database itself rejects an employee pointing at another tenant's department.
    $table->string('email');
    $table->timestamps();

    $table->tenantScopedKey();
    $table->unique(['tenant_id', 'email']);
});
```

Rules:
- Every tenant-owned table: `tenantId()` + `tenantScopedKey()`.
- Every reference to another tenant-owned table: `tenantForeignUuid()`, never a plain `foreignUuid()`.
- Every unique business key includes `tenant_id`.
- Composite FKs use `RESTRICT`. Do not use `SET NULL` on them (it would also null `tenant_id`).
- One logical schema change per migration (MySQL DDL is not transactional).

### Model

```php
class Department extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['name'];   // never put tenant_id in user-facing input
}
```

### Routes

```php
Route::middleware(['web', 'tenant'])->group(function () {
    Route::get('/departments/{department}', ...);   // binding is already tenant-filtered → foreign id = 404
});
```

### Validation

`exists:`/`unique:` rules bypass Eloquent scopes. Never use the string form for tenant tables; use `App\Tenancy\Validation\TenantRule`:

```php
TenantRule::exists('departments'),
TenantRule::unique('departments', 'name')->ignore($department),
```

Reference module: `app/Http/Controllers/Api/DepartmentController.php`, `app/Http/Requests/DepartmentRequest.php`, `tests/Feature/Departments/DepartmentApiTest.php`.

## 4. What is enforced, and where

| Operation | Without tenant context | With tenant A in context |
|---|---|---|
| Eloquent read / count / aggregate | `NoTenantContextException` | Only A's rows |
| Route-model binding | (middleware 404s first) | B's id → 404 |
| `create()` | `NoTenantContextException` | `tenant_id` = A; explicit B → `TenantMismatchException` |
| `save()` / `delete()` on a model | `NoTenantContextException` | B's model → `TenantMismatchException`; changing `tenant_id` → exception |
| Mass `update()` / `delete()` via query | `NoTenantContextException` | Only A's rows |
| Raw insert referencing B's row | Database FK error | Database FK error |
| Deleting a tenant that has data | Database FK error | Database FK error |
| Files / cache | `NoTenantContextException` | `tenants/{A}/…`, `tenant:{A}:…` |
| Queued job | Runs with no tenant | Runs as A; fails if A is suspended or gone |

## 5. Things that are NOT protected automatically

Review these in every PR:

1. **Query builder / raw SQL** — `DB::table(...)`, `DB::select(...)` bypass the scope. Do not use them for tenant data, or add `where('tenant_id', …)` explicitly.
2. **`withoutGlobalScope(TenantScope::class)` / `withoutGlobalScopes()`** — platform code only; must be justified in review.
3. **Validation rules** — see §3.
4. **Plain `Cache`/`Storage` facades** — platform-wide data only; tenant data goes through `TenantCache` / `TenantFileStorage`.
5. **Jobs dispatched outside a tenant** — they run with no tenant, so any tenant-scoped query inside fails closed.

## 6. Acting on behalf of a tenant (commands, scheduled tasks, provisioning)

```php
$context->run($tenant, function () {
    // everything here — queries, files, cache, dispatched jobs — is $tenant's
});

// Scheduled work: iterate explicitly
Tenant::query()->active()->each(fn (Tenant $t) => $context->run($t, fn () => ...));
```

`run()` restores the previous tenant afterwards, including on exceptions. It also handles `fn () => Job::dispatch()`: Laravel queues a job only when the `PendingDispatch` object is released, which would otherwise happen *after* the context is restored.

## 7. Testing

- Tests run on the real engine in `hr_workforce_testing` (locally MariaDB; CI MySQL 8.4). Use `Tests\Concerns\RefreshTestDatabase` (it refuses to wipe any database not ending in `_testing`).
- `Tests\Concerns\CreatesTenants` provides `createTenant()` and `asTenant()`.
- **Every tenant-owned model needs an isolation test** mirroring `tests/Feature/Tenancy/TenantIsolationTest.php`: fail-closed reads/writes, cross-tenant read/find/update/delete, mass update/delete, per-tenant unique keys, DB-level cross-tenant FK rejection, and route-model binding.
