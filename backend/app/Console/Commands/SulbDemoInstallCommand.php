<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoCompanyInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class SulbDemoInstallCommand extends Command
{
    protected $signature='sulb:demo-install {--password=} {--reset-password=} {--production-confirm=}';
    protected $description='Install the isolated SULB demonstration tenant manually';

    public function handle(DemoCompanyInstaller $installer): int
    {
        if(!self::allowsEnvironment((string)app()->environment(),$this->option('production-confirm'))){$this->error('Refusing production Demo installation without --production-confirm=INSTALL_SULB_DEMO.');return self::FAILURE;}
        $generated=!$this->option('password');$password=(string)($this->option('password')?:Str::password(20));
        if(strlen($password)<12){$this->error('Demo password must contain at least 12 characters.');return self::FAILURE;}
        try{$result=$installer->install($password,$this->option('reset-password')!==null?(string)$this->option('reset-password'):null);}catch(\Throwable$e){$this->error('Demo installation failed: '.$e->getMessage());return self::FAILURE;}
        $this->newLine();$this->line('==================================');$this->info($result['existing']?'SULB DEMO ALREADY EXISTS':'SULB DEMO INSTALLATION COMPLETE');$this->line('==================================');
        $this->line('Company: '.$result['company_name']);$this->line('Company ID: '.$result['company_id']);$this->line('Login: '.$result['login']);$this->line('Branch: '.$result['branch']);$this->line('Financial Year: '.$result['financial_year']);
        foreach($result['counts']as$name=>$count)$this->line(str_replace('_',' ',ucwords($name,'_')).': '.$count);
        $this->line('Accounting Integrity: '.($result['accounting_integrity']['status']??($result['existing']?'NOT RE-RUN':'PASS')));
        if($generated&&!$result['existing']){$this->warn('Temporary password (shown once): '.$password);$this->warn('Change this password immediately after first login.');}
        $this->line('==================================');return self::SUCCESS;
    }
    public static function allowsEnvironment(string $environment,?string $confirmation): bool
    {return $environment!=='production'||$confirmation==='INSTALL_SULB_DEMO';}
}
