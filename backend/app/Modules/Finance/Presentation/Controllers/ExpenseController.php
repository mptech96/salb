<?php

namespace App\Modules\Finance\Presentation\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Modules\Finance\Application\CreateExpense\CreateExpenseCommand;
use App\Modules\Finance\Application\CreateExpense\CreateExpenseHandler;

class ExpenseController extends Controller
{
    public function __construct(
        private CreateExpenseHandler $handler
    ) {
    }

    public function store(Request $request)
    {
        $request->validate([
            'expense_type_id' => 'required|integer',
            'expense_date'    => 'required|date',
            'scope_type'      => 'required|string',
            'amount'          => 'required|numeric|min:0.001'
        ]);

        $command = new CreateExpenseCommand(

            companyId: request()->header('X-Company-ID'),

            branchId: request()->header('X-Branch-ID'),

            userId: request()->header('X-User-ID'),

            expenseTypeId: $request->expense_type_id,

            expenseDate: $request->expense_date,

            scopeType: $request->scope_type,

            amount: $request->amount,

            paymentStatus: $request->payment_status ?? 'PAID',

            paymentMethod: $request->payment_method ?? 'CASH',

            expenseEffect: $request->expense_effect ?? 'COST',

            referenceId: $request->reference_id,

            shipmentId: $request->shipment_id,

            carId: $request->car_id,

            purchaseInvoiceId: $request->purchase_invoice_id,

            salesInvoiceId: $request->sales_invoice_id,

            driverId: $request->driver_id,

            workerId: $request->worker_id,

            notes: $request->notes
        );

        $result = $this->handler->handle($command);

        return response()->json([
            'status'  => $result->success,
            'message' => $result->message,
            'data'    => $result->data
        ], $result->statusCode);
    }
}