<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PayrollDevRouteSafetyTest extends TestCase
{
    public function test_mutating_payroll_dev_routes_are_not_registered(): void
    {
        $uris=collect(Route::getRoutes())->map(fn($route)=>$route->uri())->all();

        self::assertNotContains('api/dev/payroll/generate',$uris);
        self::assertNotContains('api/dev/payroll/approve/{id}',$uris);
        self::assertNotContains('api/dev/payroll/pay/{id}',$uris);
        self::assertContains('api/payroll/generate',$uris);
        self::assertContains('api/payroll/{id}/approve',$uris);
        self::assertContains('api/payroll/{id}/pay',$uris);
    }
}
