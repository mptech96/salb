<?php
namespace App\Support;

/** Financial failure responses must not disclose SQL, bindings or connection details. */
final class ApiExceptionMessage
{
    public static function for(\Throwable $failure): string
    {
        $databaseFailure=false;$knownCode=0;
        for($cause=$failure;$cause!==null;$cause=$cause->getPrevious()) {
            if($cause instanceof \PDOException) {
                $databaseFailure=true;
                $code=(int)($cause->errorInfo[1]??0);
                if(in_array($code,[1205,1213],true))$knownCode=$code;
            }
        }
        if($databaseFailure)return 'تعذرت عملية قاعدة البيانات. تحقق من حالة المستند قبل إعادة المحاولة.'
            .($knownCode?' ('.$knownCode.')':'');
        return $failure->getMessage();
    }
}
