<?php

namespace Database\Seeders;

use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * Drop zones for Kempegowda International (BLR).
 *
 * Fares are rough 2026 daytime figures for planning only — they are not quotes
 * and nothing in the app books a cab. Verify before showing them to real users.
 */
class ZoneSeeder extends Seeder
{
    public function run(): void
    {
        $zones = [
            ['name' => 'Hebbal / Sahakar Nagar',  'slug' => 'hebbal',          'km' => 22, 'sedan' => 750,  'suv' => 1200, 'lat' => 13.0358, 'lng' => 77.5970],
            ['name' => 'Yelahanka',               'slug' => 'yelahanka',       'km' => 17, 'sedan' => 650,  'suv' => 1050, 'lat' => 13.1007, 'lng' => 77.5963],
            ['name' => 'Indiranagar',             'slug' => 'indiranagar',     'km' => 36, 'sedan' => 1150, 'suv' => 1850, 'lat' => 12.9784, 'lng' => 77.6408],
            ['name' => 'Koramangala',             'slug' => 'koramangala',     'km' => 40, 'sedan' => 1250, 'suv' => 2000, 'lat' => 12.9352, 'lng' => 77.6245],
            ['name' => 'HSR Layout',              'slug' => 'hsr-layout',      'km' => 43, 'sedan' => 1350, 'suv' => 2150, 'lat' => 12.9082, 'lng' => 77.6476],
            ['name' => 'Whitefield',              'slug' => 'whitefield',      'km' => 47, 'sedan' => 1450, 'suv' => 2300, 'lat' => 12.9698, 'lng' => 77.7500],
            ['name' => 'Marathahalli / ORR',      'slug' => 'marathahalli',    'km' => 40, 'sedan' => 1250, 'suv' => 2000, 'lat' => 12.9591, 'lng' => 77.6974],
            ['name' => 'MG Road / Central',       'slug' => 'mg-road',         'km' => 34, 'sedan' => 1100, 'suv' => 1750, 'lat' => 12.9750, 'lng' => 77.6060],
            ['name' => 'Jayanagar / JP Nagar',    'slug' => 'jayanagar',       'km' => 45, 'sedan' => 1400, 'suv' => 2250, 'lat' => 12.9250, 'lng' => 77.5938],
            ['name' => 'Electronic City',         'slug' => 'electronic-city', 'km' => 58, 'sedan' => 1750, 'suv' => 2800, 'lat' => 12.8452, 'lng' => 77.6602],
        ];

        foreach ($zones as $i => $zone) {
            Zone::updateOrCreate(
                ['slug' => $zone['slug']],
                [
                    'city' => 'Bengaluru',
                    'name' => $zone['name'],
                    'lat' => $zone['lat'],
                    'lng' => $zone['lng'],
                    'distance_km' => $zone['km'],
                    'sedan_fare' => $zone['sedan'],
                    'suv_fare' => $zone['suv'],
                    'sort_order' => $i,
                    'is_active' => true,
                ],
            );
        }
    }
}
