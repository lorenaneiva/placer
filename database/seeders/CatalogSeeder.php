<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Space;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            foreach ([
                ['name' => 'Quadra de vôlei', 'description' => 'Quadra para treinos e partidas de vôlei.', 'hourly_price' => '80.00', 'capacity' => 12],
                ['name' => 'Campo de futebol', 'description' => 'Espaço para partidas entre amigos.', 'hourly_price' => '150.00', 'capacity' => 22],
                ['name' => 'Piscina', 'description' => 'Piscina para atividades de lazer.', 'hourly_price' => '100.00', 'capacity' => 20],
                ['name' => 'Tatame', 'description' => 'Área para treinamento de artes marciais.', 'hourly_price' => '60.00', 'capacity' => 16],
                ['name' => 'Salão de festa', 'description' => 'Salão para encontros e comemorações.', 'hourly_price' => '200.00', 'capacity' => 80],
            ] as $example) {
                $category = Category::firstOrCreate(['name' => $example['name']], ['is_active' => true, 'max_advance_days' => 21]);
                if (! $category->is_active) {
                    continue;
                }

                Space::firstOrCreate(['category_id' => $category->id, 'identifier' => '001'], [
                    'description' => $example['description'],
                    'hourly_price' => $example['hourly_price'],
                    'capacity' => $example['capacity'],
                    'is_active' => true,
                ]);
            }
        });
    }
}
