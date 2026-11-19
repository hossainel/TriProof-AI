<?php

namespace Database\Seeders;

use App\Models\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FactorySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['code' => 'DHK04', 'name_en' => 'Dhaka Apparel Unit 04', 'name_bn' => 'ঢাকা পোশাক ইউনিট ০৪', 'location' => 'Dhaka'],
            ['code' => 'BD01', 'name_en' => 'Gazipur Knitwear Line 1', 'name_bn' => 'গাজীপুর নিটওয়্যার লাইন ১', 'location' => 'Gazipur'],
            ['code' => 'BD02', 'name_en' => 'Chattogram Export Zone 2', 'name_bn' => 'চট্টগ্রাম রপ্তানি জোন ২', 'location' => 'Chattogram'],
            ['code' => 'BD03', 'name_en' => 'Narayanganj Denim Plant 3', 'name_bn' => 'নারায়ণগঞ্জ ডেনিম প্ল্যান্ট ৩', 'location' => 'Narayanganj'],
        ];

        foreach ($defaults as $factory) {
            Factory::firstOrCreate(['code' => $factory['code']], $factory + [
                'contact_email' => null,
                'is_active' => true,
            ]);
        }

        $existingCodes = collect()
            ->merge(DB::table('users')->whereNotNull('factory_id')->distinct()->pluck('factory_id'))
            ->merge(DB::table('purchase_orders')->distinct()->pluck('factory_id'))
            ->merge(DB::table('serial_registry')->distinct()->pluck('factory_id'))
            ->filter()
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->unique()
            ->values();

        foreach ($existingCodes as $code) {
            Factory::firstOrCreate(['code' => $code], [
                'name_en' => 'Factory '.$code,
                'name_bn' => null,
                'location' => null,
                'contact_email' => null,
                'is_active' => true,
            ]);
        }
    }
}
