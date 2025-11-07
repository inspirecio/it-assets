<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run()
    {
        Supplier::truncate();

        // Manufacturers as suppliers
        Supplier::factory()->count(1)->microsoft()->create();
        Supplier::factory()->count(1)->apple()->create();
        Supplier::factory()->count(1)->dell()->create();
        Supplier::factory()->count(1)->lenovo()->create();
        Supplier::factory()->count(1)->asus()->create();
        Supplier::factory()->count(1)->hp()->create();

        // Retailers
        Supplier::factory()->count(1)->bestBuy()->create();
        Supplier::factory()->count(1)->target()->create();
        Supplier::factory()->count(1)->microCenter()->create();
    }
}
