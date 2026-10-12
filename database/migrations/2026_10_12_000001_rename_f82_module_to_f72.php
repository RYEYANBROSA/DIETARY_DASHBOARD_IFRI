<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The F82 module is now called F72 - carry each account's access over to the new key. */
return new class extends Migration
{
    public function up(): void
    {
        $this->swap('f82', 'f72');
    }

    public function down(): void
    {
        $this->swap('f72', 'f82');
    }

    private function swap(string $from, string $to): void
    {
        DB::table('users')->whereNotNull('modules')->orderBy('id')->each(function ($user) use ($from, $to) {
            $modules = json_decode($user->modules, true);
            if (!is_array($modules) || !in_array($from, $modules, true)) {
                return;
            }

            $modules = array_values(array_unique(array_map(fn ($m) => $m === $from ? $to : $m, $modules)));
            DB::table('users')->where('id', $user->id)->update(['modules' => json_encode($modules)]);
        });
    }
};
