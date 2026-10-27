<?php
namespace App\Support;

/** Financial failure responses must not disclose SQL, bindings or connection details. */
final class ApiExceptionMessage
{
    public static function for(\Throwable $failure): string
    {
        $databaseFailure=false;$knownCode=0;
        for($cause=$failure;$cause!==null;$cause=$cause->getPrevious()) {
            // Some legacy posting adapters retain only the error message, losing
            // the PDO cause. Redact a recognizable SQL diagnostic there as well.
            if(preg_match('/SQLSTATE\[[0-9A-Z]{5}\]|(?:Connection|Database|Host):[^\r\n]*\bSQL:/i',$cause->getMessage()))$databaseFailure=true;
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
