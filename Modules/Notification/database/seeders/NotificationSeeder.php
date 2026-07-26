<?php

namespace Modules\Notification\Database\Seeders;

use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = ['order_created', 'order_confirmed', 'order_picked_up', 'order_ready', 'order_delivered'];

        for ($i = 1; $i <= 20; $i++) {
            \Modules\Notification\Models\Notification::create([
                'user_id' => (($i - 1) % 10) + 1,
                'user_type' => 'client',
                'type' => $types[$i % count($types)],
                'title' => ['ar' => 'إشعار '.$i, 'en' => 'Notification '.$i],
                'message' => ['ar' => 'رسالة الإشعار '.$i, 'en' => 'Notification message '.$i],
                'image' => null,
                'is_read' => $i % 3 == 0,
                'read_at' => $i % 3 == 0 ? now() : null,
            ]);
        }
    }
}
