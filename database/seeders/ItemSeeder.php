<?php

namespace Database\Seeders;

use App\Models\SourceApi\ApiItem;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'Electronics', 'Accessories', 'Office', 'Home', 'Gaming',
            'Audio', 'Video', 'Storage', 'Networking', 'Mobile'
        ];

        $productTypes = [
            'Laptop', 'Mouse', 'Keyboard', 'Monitor', 'Tablet', 'Phone',
            'Speaker', 'Headphones', 'Webcam', 'Microphone', 'Cable',
            'Adapter', 'Hub', 'Dock', 'Stand', 'Pad', 'Cover', 'Case',
            'Charger', 'Battery', 'Drive', 'Router', 'Switch', 'Camera',
            'Light', 'Fan', 'Cleaner', 'Organizer', 'Holder', 'Mount'
        ];

        $adjectives = [
            'Professional', 'Premium', 'Budget', 'Compact', 'Portable',
            'Wireless', 'Wired', 'Bluetooth', 'USB-C', 'Gaming',
            'Ergonomic', 'Mechanical', 'Optical', 'LED', 'Smart',
            'High-Performance', 'Ultra-Fast', 'Heavy-Duty', 'Slim',
            'Adjustable', 'Foldable', 'Universal', 'Magnetic', 'Solar-Powered'
        ];

        $items = [];

        for ($i = 1; $i <= 400; $i++) {
            $category = $categories[array_rand($categories)];
            $type = $productTypes[array_rand($productTypes)];
            $adjective = $adjectives[array_rand($adjectives)];

            $name = "{$adjective} {$type}";
            $description = "{$category} - {$name} - Item #{$i}";
            $price = round(rand(5, 2000) + (rand(0, 99) / 100), 2);

            $items[] = [
                'name' => $name,
                'description' => $description,
                'price' => $price,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Insert in batches of 100 for better performance
            if (count($items) === 100) {
                ApiItem::insert($items);
                $items = [];
            }
        }

        // Insert any remaining items
        if (!empty($items)) {
            ApiItem::insert($items);
        }
    }
}
