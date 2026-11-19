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
            ['code' => 'DHK04', 'name_en' => 'Dhaka Apparel Unit 04', 'name_bn' => 'ঢাকা অ্যাপারেল ইউনিট ০৪', 'location' => 'Dhaka'],
            ['code' => 'BD01', 'name_en' => 'Bangladesh Unit 01', 'name_bn' => 'বাংলাদেশ ইউনিট ০১', 'location' => 'Dhaka'],
            ['code' => 'BD02', 'name_en' => 'Chattogram Unit 02', 'name_bn' => 'চট্টগ্রাম ইউনিট ০২', 'location' => 'Chattogram'],
            ['code' => 'BD03', 'name_en' => 'Gazipur Unit 03', 'name_bn' => 'গাজীপুর ইউনিট ০৩', 'location' => 'Gazipur'],
            ['code' => 'BD04', 'name_en' => 'Narayanganj Unit 04', 'name_bn' => 'নারায়ণগঞ্জ ইউনিট ০৪', 'location' => 'Narayanganj'],
            ['code' => 'BD05', 'name_en' => 'Khulna Unit 05', 'name_bn' => 'খুলনা ইউনিট ০৫', 'location' => 'Khulna'],
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
