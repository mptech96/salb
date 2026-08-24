<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\Subscription\SubscriptionLifecycleService;

/*
|--------------------------------------------------------------------------
| Console Commands
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| معالجة الاشتراكات المنتهية
|--------------------------------------------------------------------------
|
| يقوم الأمر بالتالي:
| 1. يبحث عن الاشتراكات ACTIVE التي انتهى تاريخها.
| 2. يحول حالتها إلى EXPIRED عبر خدمة دورة الحياة المركزية.
| 3. لا يغير الحالة التشغيلية للشركة ولا يحذف أي بيانات.
|
*/

Artisan::command('subscriptions:expire', function (SubscriptionLifecycleService $lifecycle) {
    $this->info('بدء فحص الاشتراكات المنتهية...');

    try {
        $expiredSubscriptionsCount = $lifecycle->expireElapsedSubscriptions();

        $this->newLine();

        $this->info(
            'تم تحويل الاشتراكات المنتهية: ' .
            $expiredSubscriptionsCount
        );

        $this->newLine();
        $this->info('اكتملت عملية فحص الاشتراكات بنجاح.');

        return self::SUCCESS;
    } catch (\Throwable $exception) {
        report($exception);

        $this->error('فشلت عملية فحص الاشتراكات.');
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
})->purpose(
    'تحويل الاشتراكات المنتهية إلى EXPIRED وإيقاف الشركات غير المشتركة'
);

/*
|--------------------------------------------------------------------------
| Scheduler
|--------------------------------------------------------------------------
|
| يعمل الأمر يوميًا الساعة 12:05 بعد منتصف الليل.
| withoutOverlapping يمنع تشغيل نسختين من المهمة في الوقت نفسه.
|
*/

Schedule::command('subscriptions:expire')
    ->dailyAt('00:05')
    ->withoutOverlapping(30);
