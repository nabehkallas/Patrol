<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/** A throwaway model to exercise the Auditable trait without a station database. */
class AuditedThing extends Model
{
    use Auditable;

    protected $table = 'audited_things';

    protected $guarded = [];
}

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The test database already loads the station migrations; build the log if it is missing.
        if (! Schema::hasTable('audit_logs')) {
            (require database_path('migrations/tenant/2026_10_08_000001_create_audit_logs_table.php'))->up();
        }
        Schema::create('audited_things', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('password')->nullable();
            $table->timestamps();
        });

        // AuditLog::record() only writes inside a station.
        tenancy()->initialized = true;
    }

    protected function tearDown(): void
    {
        tenancy()->initialized = false;

        parent::tearDown();
    }

    public function test_changes_are_recorded_with_old_and_new_values_but_never_secrets(): void
    {
        $thing = AuditedThing::create(['name' => 'Pump 1', 'password' => 'secret-hash']);
        $thing->update(['name' => 'Pump 2']);
        $thing->delete();

        $logs = AuditLog::orderBy('id')->get();

        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('action')->all());
        $this->assertSame('AuditedThing', $logs[0]->entity_type);
        $this->assertSame('[changed]', $logs[0]->new_values['password']);
        $this->assertStringNotContainsString('secret-hash', $logs->toJson());
        $this->assertSame(['name' => 'Pump 1'], $logs[1]->old_values);
        $this->assertSame(['name' => 'Pump 2'], $logs[1]->new_values);
        $this->assertSame('Pump 2', $logs[2]->old_values['name']);
    }

    public function test_entries_cannot_be_changed_or_deleted_by_the_app_or_raw_sql(): void
    {
        AuditLog::record('station.reset');
        $entry = AuditLog::firstOrFail();

        $this->assertThrows(fn () => $entry->update(['action' => 'tampered']), LogicException::class);
        $this->assertThrows(fn () => $entry->delete(), LogicException::class);
        $this->assertThrows(fn () => DB::table('audit_logs')->update(['action' => 'tampered']), QueryException::class);
        $this->assertThrows(fn () => DB::table('audit_logs')->delete(), QueryException::class);

        $this->assertSame('station.reset', AuditLog::firstOrFail()->action);
    }

    public function test_nothing_is_written_outside_a_station(): void
    {
        tenancy()->initialized = false;

        AuditLog::record('station.reset');

        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_audit_log_page_is_admin_only(): void
    {
        $this->assertContains('role:admin', Route::getRoutes()->getByName('admin.audit-log.index')->gatherMiddleware());
    }
}
