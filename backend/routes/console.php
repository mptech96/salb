<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

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
| 2. يحول حالتها إلى EXPIRED.
| 3. يوقف الشركة إذا لم يوجد لها اشتراك ACTIVE آخر غير منتهٍ.
|
*/

Artisan::command('subscriptions:expire', function () {
    $this->info('بدء فحص الاشتراكات المنتهية...');

    try {
        $result = DB::transaction(function () {
            $today = now()->toDateString();

            $expiredSubscriptions = DB::table('subscriptions')
                ->where('status', 'ACTIVE')
                ->whereDate('end_date', '<', $today)
                ->lockForUpdate()
                ->get();

            $expiredSubscriptionsCount = 0;
            $deactivatedCompaniesCount = 0;

            foreach ($expiredSubscriptions as $subscription) {
                DB::table('subscriptions')
                    ->where('id', $subscription->id)
                    ->update([
                        'status' => 'EXPIRED',
                        'updated_at' => now(),
                    ]);

                $expiredSubscriptionsCount++;

                $hasAnotherActiveSubscription = DB::table('subscriptions')
                    ->where('company_id', $subscription->company_id)
                    ->where('id', '<>', $subscription->id)
                    ->where('status', 'ACTIVE')
                    ->whereDate('end_date', '>=', $today)
                    ->exists();

                if (!$hasAnotherActiveSubscription) {
                    DB::table('companies')
                        ->where('id', $subscription->company_id)
                        ->update([
                            'is_active' => 0,
                            'updated_at' => now(),
                        ]);

                    $deactivatedCompaniesCount++;
                }
            }

            return [
                'expired_subscriptions' => $expiredSubscriptionsCount,
                'deactivated_companies' => $deactivatedCompaniesCount,
            ];
        });

        $this->newLine();

        $this->info(
            'تم تحويل الاشتراكات المنتهية: ' .
            $result['expired_subscriptions']
        );

        $this->info(
            'تم إيقاف الشركات: ' .
            $result['deactivated_companies']
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