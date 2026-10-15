<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SWS Upload Data is a new default module for validators. Accounts keep their
 * own module list, so existing validators get it added here (administrators
 * already open every module). Taking it away again is done in System Users.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->each(function (array $modules) {
            if (!in_array('sws', $modules, true)) {
                // Keep sidebar order: right after F11 when the account has it
                $at = array_search('f11', $modules, true);
                array_splice($modules, $at === false ? count($modules) : $at + 1, 0, ['sws']);
            }

            return $modules;
        });
    }

    public function down(): void
    {
        $this->each(fn (array $modules) => array_values(array_diff($modules, ['sws'])));
    }

    private function each(callable $change): void
    {
        DB::table('users')->where('level', 'VALIDATOR')->whereNotNull('modules')->orderBy('id')->each(function ($user) use ($change) {
            $modules = json_decode($user->modules, true);
            if (is_array($modules)) {
                DB::table('users')->where('id', $user->id)->update(['modules' => json_encode($change($modules))]);
            }
        });
    }
};
