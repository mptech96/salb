<?php
namespace Tests\Unit;
use App\Support\ApiExceptionMessage;
use PHPUnit\Framework\TestCase;
final class ApiExceptionPrivacyTest extends TestCase
{
    public function test_string_only_posting_failure_cannot_disclose_sql_or_bindings():void
    {
        foreach(['SQLSTATE[45000]: QA_PRIVATE_IBAN (Database: sulbprod, SQL: insert into vouchers...)','Connection: mysql, Database: sulbprod, SQL: update private_table ...']as$message){$safe=ApiExceptionMessage::for(new \RuntimeException($message));foreach(['SQLSTATE','sulbprod','QA_PRIVATE_IBAN','private_table']as$private)self::assertStringNotContainsString($private,$safe);}
    }
    public function test_wrapped_pdo_keeps_safe_retry_code_and_business_error_is_unchanged():void
    {
        $pdo=new \PDOException('QA_PRIVATE_IBAN');$pdo->errorInfo=['HY000',1205,'QA_PRIVATE_IBAN'];$safe=ApiExceptionMessage::for(new \RuntimeException('wrapped',0,$pdo));self::assertStringContainsString('1205',$safe);self::assertStringNotContainsString('QA_PRIVATE_IBAN',$safe);self::assertSame('UOM_ADJUSTMENT_SCOPE',ApiExceptionMessage::for(new \RuntimeException('UOM_ADJUSTMENT_SCOPE')));
    }
}
