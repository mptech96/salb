<?php
namespace App\Http\Controllers\Api;
final class PurchaseOrderController extends CommercialDocumentController { protected function type(): string{return'PURCHASE_ORDER';} }
