<?php

namespace Database\Seeders;

use App\Models\Food;
use App\Models\Order;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class WebhookTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. إعداد سياق الذكاء الاصطناعي (AI Context)
        Setting::updateOrCreate(
            ['name' => 'ai_context'],
            [
                'value' => 'أنت موظف خدمة عملاء ذكي وودود لمطعم "برجر لايت". مهمتك مساعدة العملاء والإجابة عن استفساراتهم بخصوص قائمة الطعام والأسعار بأسلوب لطيف ومختصر باللغة العربية فقط.',
            ]
        );

        // 2. إنشاء باقة اشتراك (Package)
        $package = Package::updateOrCreate(
            ['id' => 1],
            [
                'name' => ['ar' => 'باقة تجريبية غير محدودة', 'en' => 'Unlimited Test Package'],
                'msg_number' => 10000,
                'price' => 200.00,
                'months' => 12,
            ]
        );

        // 3. إنشاء أو تحديث حساب المطعم (Restaurant User) المرتبط برقم ميتا
        $phoneNumberId = config('services.meta.phone_number_id') ?: '1296872370175605';
        $wabaId = config('services.meta.waba_id') ?: '3732693236881976';
        $token = config('services.meta.system_user_token');

        $restaurant = User::updateOrCreate(
            ['email' => 'restaurant@burgerlite.com'],
            [
                'name' => 'مطعم برجر لايت',
                'restuarant_name' => 'مطعم برجر لايت',
                'phone' => '201206610346',
                'password' => Hash::make('password123'),
                'role' => 'user',
            ]
        );

        $whatsItem = WhatsItem::updateOrCreate(
            ['phone_number_id' => $phoneNumberId],
            [
                'user_id' => $restaurant->id,
                'phone' => '201206610346',
                'waba_id' => $wabaId,
                'access_token' => $token,
                'phone_status' => 'active',
                'phone_verified_at' => now(),
                'android_link' => 'https://play.google.com/store/apps/details?id=com.burgerlite',
                'ios_link' => 'https://apps.apple.com/app/id123456789',
                'msg_number' => 10000,
            ]
        );

        // 4. إنشاء اشتراك نشط للمطعم (Active Order) لكي يسمح السيستم بإرسال الرسائل
        Order::updateOrCreate(
            ['user_id' => $restaurant->id],
            [
                'package_id' => $package->id,
                'whats_item_id' => $whatsItem->id,
                'price' => 200.00,
                'final_price' => 200.00,
                'total_discount' => 0.00,
                'total_tax' => 0.00,
                'from' => now()->subDays(10)->toDateString(),
                'to' => now()->addYear()->toDateString(),
                'msgs' => 10000,
            ]
        );

        // 5. إضافة وجبات في قاعدة البيانات الثانية (food table) ليبحث فيها الذكاء الاصطناعي
        $foods = [
            [
                'name_ar' => 'برجر لحم كلاسيك',
                'description_ar' => 'شريحة لحم بقري مشوية على الفحم مع جبنة شيدر وصوص المايونيز المدخن والخس المقرمش.',
                'price' => 130.00,
                'status' => 1,
                'is_out_of_stock' => 0,
            ],
            [
                'name_ar' => 'برجر دجاج كرسبي',
                'description_ar' => 'صدر دجاج مقرمش مقلي مع صوص الرانش اللذيذ وشرائح الطماطم والخس.',
                'price' => 115.00,
                'status' => 1,
                'is_out_of_stock' => 0,
            ],
            [
                'name_ar' => 'بطاطس مقلية مع الجبنة',
                'description_ar' => 'أصابع بطاطس ذهبية ومقرمشة مغطاة بصوص جبنة الشيدر الذائبة.',
                'price' => 45.00,
                'status' => 1,
                'is_out_of_stock' => 0,
            ],
            [
                'name_ar' => 'كومبو برجر السعادة',
                'description_ar' => 'ساندوتش برجر لحم دبل مع بطاطس مقلية ومشروب غازي كانز.',
                'price' => 175.00,
                'status' => 1,
                'is_out_of_stock' => 0,
            ],
            [
                'name_ar' => 'بيبسي كانز',
                'description_ar' => 'مشروب غازي منعش 330 مل.',
                'price' => 20.00,
                'status' => 1,
                'is_out_of_stock' => 0,
            ],
        ];

        $this->command?->info("Webhook test data seeded successfully for phone_number_id: {$phoneNumberId}");
    }
}
