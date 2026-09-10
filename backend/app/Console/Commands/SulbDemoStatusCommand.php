<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoCompanyInstaller;
use App\Services\Demo\DemoIntegrityService;
use Illuminate\Console\Command;

final class SulbDemoStatusCommand extends Command
{
    protected $signature='sulb:demo-status';
    protected $description='Read-only status and accounting integrity check for the SULB Demo tenant';
    public function handle(DemoCompanyInstaller $installer,DemoIntegrityService $integrity): int
    {try{$result=$installer->status();$check=$integrity->validate((int)$result['company_id'],(int)$result['financial_year_id']);}catch(\Throwable$e){$this->error($e->getMessage());return self::FAILURE;}$this->info('SULB Demo company #'.$result['company_id'].' — '.$result['financial_year']);foreach($result['counts']as$name=>$count)$this->line($name.': '.$count);$this->info('Accounting Integrity: '.$check['status']);return self::SUCCESS;}
}
