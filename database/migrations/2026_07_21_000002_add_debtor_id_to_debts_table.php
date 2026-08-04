<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->foreignId('debtor_id')->nullable()->after('direction')->constrained('debtors')->restrictOnDelete();
        });

        foreach (DB::table('debts')->select('name')->distinct()->get() as $row) {
            $debtorId = DB::table('debtors')->where('name', $row->name)->value('id');

            if ($debtorId === null) {
                $debtorId = DB::table('debtors')->insertGetId([
                    'name' => $row->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('debts')->where('name', $row->name)->update(['debtor_id' => $debtorId]);
        }

        Schema::table('debts', function (Blueprint $table) {
            $table->foreignId('debtor_id')->nullable(false)->change();
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->string('name')->nullable()->after('direction');
        });

        foreach (DB::table('debts')->select('id', 'debtor_id')->get() as $row) {
            $name = DB::table('debtors')->where('id', $row->debtor_id)->value('name');
            DB::table('debts')->where('id', $row->id)->update(['name' => $name]);
        }

        Schema::table('debts', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->dropConstrainedForeignId('debtor_id');
        });
    }
};
