<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:all,owing,clear',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $query = Customer::query()
            ->withCount('sales')
            ->withSum('sales as total_credit_sales', 'credit_amount')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($customerQuery) use ($search) {
                $customerQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->input('status') === 'owing') {
            $query->where('outstanding_balance', '>', 0);
        } elseif ($request->input('status') === 'clear') {
            $query->where('outstanding_balance', '<=', 0);
        }

        return response()->json([
            'customers' => $query->paginate((int) $request->input('per_page', 25)),
            'summary' => [
                'customers_count' => Customer::count(),
                'owing_count' => Customer::where('outstanding_balance', '>', 0)->count(),
                'outstanding_balance' => (float) Customer::sum('outstanding_balance'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only admins can create customers.'], 403);
        }

        $validator = $this->customerValidator($request);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $customer = Customer::create($validator->validated());

        ActivityLog::record($request, 'customer_created', "Created customer {$customer->name}", [
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'after' => $customer->only(['name', 'phone', 'email', 'credit_limit']),
        ]);

        return response()->json([
            'message' => 'Customer created successfully',
            'customer' => $customer,
        ], 201);
    }

    public function show(Customer $customer)
    {
        $customer->load([
            'sales' => fn ($query) => $query->with('cashier:id,name,email,role')->latest()->limit(30),
            'payments' => fn ($query) => $query->with('receivedBy:id,name,email,role', 'sale:id')->latest()->limit(30),
        ]);

        return response()->json(['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer)
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only admins can update customers.'], 403);
        }

        $validator = $this->customerValidator($request, $customer->id);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $before = $customer->only(['name', 'phone', 'email', 'address', 'credit_limit', 'notes']);
        $customer->update($validator->validated());

        ActivityLog::record($request, 'customer_updated', "Updated customer {$customer->name}", [
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'before' => $before,
            'after' => $customer->only(['name', 'phone', 'email', 'address', 'credit_limit', 'notes']),
        ]);

        return response()->json([
            'message' => 'Customer updated successfully',
            'customer' => $customer,
        ]);
    }

    public function payment(Request $request, Customer $customer)
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Only admins can record customer payments.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,mobile_money,card',
            'sale_id' => 'nullable|exists:sales,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $customer, $validator) {
                $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $amount = round((float) $validator->validated()['amount'], 2);

                if ($amount > (float) $customer->outstanding_balance) {
                    throw ValidationException::withMessages([
                        'amount' => 'Payment cannot be more than the customer outstanding balance.',
                    ]);
                }

                $payment = CustomerPayment::create([
                    'customer_id' => $customer->id,
                    'sale_id' => $request->input('sale_id'),
                    'amount' => $amount,
                    'payment_method' => $request->payment_method,
                    'notes' => $request->notes,
                    'received_by' => $request->user()->id,
                ]);

                $customer->update([
                    'outstanding_balance' => round((float) $customer->outstanding_balance - $amount, 2),
                ]);

                ActivityLog::record($request, 'customer_payment_recorded', "Recorded payment for {$customer->name}", [
                    'subject_type' => Customer::class,
                    'subject_id' => $customer->id,
                    'after' => [
                        'payment_id' => $payment->id,
                        'amount' => $amount,
                        'outstanding_balance' => $customer->outstanding_balance,
                    ],
                ]);

                return [
                    'customer' => $customer->fresh(),
                    'payment' => $payment->load('receivedBy:id,name,email,role'),
                ];
            });
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first(),
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Payment recorded successfully',
            ...$result,
        ]);
    }

    private function customerValidator(Request $request, ?int $customerId = null)
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50|unique:customers,phone'.($customerId ? ','.$customerId : ''),
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'credit_limit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);
    }
}
