<?php
namespace Tests\Unit;

use App\Support\ApiExceptionMessage;
use Illuminate\Database\QueryException;

final class ApiExceptionMessageTest extends \PHPUnit\Framework\TestCase
{
    public function test_wrapped_database_failure_does_not_disclose_sql_bindings_or_connection(): void
    {
        $pdo=new \PDOException('driver-private-detail');$pdo->errorInfo=['HY000',1205,'driver-private-detail'];
        $query=new QueryException('private_connection','UPDATE private_customer SET secret=?',['private_binding'],$pdo);
        $wrapped=new \RuntimeException($query->getMessage(),0,$query);
        $message=ApiExceptionMessage::for($wrapped);
        self::assertStringContainsString('1205',$message);
        foreach(['private_connection','private_customer','private_binding','driver-private-detail','SQLSTATE','UPDATE'] as $secret)self::assertStringNotContainsString($secret,$message);
    }
    public function test_existing_business_validation_message_is_preserved(): void
    {
        self::assertSame('الصنف خارج الشركة',ApiExceptionMessage::for(new \RuntimeException('الصنف خارج الشركة')));
    }

    public function test_nested_query_wrapper_without_error_info_keeps_inner_concurrency_code(): void
    {
        $pdo=new \PDOException('driver-private-detail');$pdo->errorInfo=['40001',1213,'driver-private-detail'];
        $inner=new QueryException('private_connection','UPDATE private_customer SET secret=?',['private_binding'],$pdo);
        $middle=new \RuntimeException($inner->getMessage(),0,$inner);
        $outer=new QueryException('private_connection','outer_private_query',[],$middle);
        $message=ApiExceptionMessage::for($outer);
        self::assertStringContainsString('1213',$message);
        self::assertStringNotContainsString('private',$message);
        self::assertStringNotContainsString('SQLSTATE',$message);
    }
}
