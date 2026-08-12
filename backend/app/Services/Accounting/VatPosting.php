<?php
namespace App\Services\Accounting;
class VatPosting { public function post(array $data): PostingResult { return PostingResult::success('لا يوجد ترحيل مستقل مطلوب لهذه العملية.'); } }
