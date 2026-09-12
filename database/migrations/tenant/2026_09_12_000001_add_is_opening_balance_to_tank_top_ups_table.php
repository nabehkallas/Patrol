<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tank_top_ups', function (Blueprint $table) {
            $table->boolean('is_opening_balance')->default(false)->after('notes');
        });

        // Backfill: the only rows created before this column existed are the ones the
        // onboarding wizard wrote for a tank's starting level, identifiable by their fixed
        // notes text (see OnboardingController::storeTankLevels) -- flag them so the Earnings
        // report can start excluding them retroactively, not just for future onboardings.
        // That notes text went through __(), so a tenant onboarded in Arabic has the
        // translated string stored instead of the English key -- match both.
        DB::table('tank_top_ups')
            ->whereIn('notes', [
                'Opening balance (initial setup)',
                'الرصيد الافتتاحي (الإعداد الأولي)',
            ])
            ->update(['is_opening_balance' => true]);
    }

    public function down(): void
    {
        Schema::table('tank_top_ups', function (Blueprint $table) {
            $table->dropColumn('is_opening_balance');
        });
    }
};
