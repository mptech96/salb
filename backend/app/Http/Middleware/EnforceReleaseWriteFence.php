<?php
namespace App\Http\Middleware;
use App\Support\ReleaseWriteFence;
use Closure;
use Illuminate\Http\Request;
final class EnforceReleaseWriteFence {
    public function handle(Request $request,Closure $next){ReleaseWriteFence::assertState(ReleaseWriteFence::active());return $next($request);}
}
