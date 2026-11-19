<?php

namespace App\Http\Controllers\Spa;

use App\Http\Controllers\Controller;
use App\Services\Spa\SpaBookingAccess;
use App\Services\Spa\SpaBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SpaBookingController extends Controller
{
    public function __construct(
        private readonly SpaBookingAccess $access,
        private readonly SpaBookingService $bookings,
    ) {}

    public function capabilities(Request $request): JsonResponse
    {
        return $this->respond($this->access->capabilities($request->user()));
    }

    public function customers(Request $request): JsonResponse
    {
        $organization = $this->access->organizationFor($request->user(), 'viewCustomers');
        $input = $request->validate($this->pageRules());

        return $this->respond($this->bookings->customers($organization, $input['search'] ?? null, (int) ($input['page'] ?? 1)));
    }

    public function lookup(Request $request): JsonResponse
    {
        $organization = $this->access->organizationFor($request->user(), 'viewCustomers');
        $input = $request->validate(['phone' => ['required', 'string', 'max:40']]);

        return $this->respond(['data' => $this->bookings->findCustomerByPhone($organization, $input['phone'])]);
    }

    public function createCustomer(Request $request): JsonResponse
    {
        $organization = $this->access->organizationFor($request->user(), 'createCustomer');
        $input = $request->validate($this->customerRules());

        return $this->respond(['data' => $this->bookings->createCustomer($organization, $request->user()->id, $input)], 201);
    }

    public function updateCustomer(Request $request, string $id): JsonResponse
    {
        $organization = $this->access->organizationFor($request->user(), 'editCustomer');
        $input = $request->validate([...$this->customerRules(), 'version' => ['required', 'integer', 'min:1']]);

        return $this->respond(['data' => $this->bookings->updateCustomer($organization, $request->user()->id, $id, $input)]);
    }

    public function services(Request $request): JsonResponse
    {
        $organization = $this->access->organizationFor($request->user(), 'createAppointment');
        $input = $request->validate($this->pageRules());

        return $this->respond($this->bookings->services($organization, $input['search'] ?? null, (int) ($input['page'] ?? 1)));
    }

    public function appointments(Request $request): JsonResponse
    {
        $organization = $this->access->organizationFor($request->user(), 'viewAppointments');
        $input = $request->validate([
            'date' => $this->dateRules(),
            'page' => $this->pageRules()['page'],
        ]);

        return $this->respond($this->bookings->appointments($organization, $input['date'], (int) ($input['page'] ?? 1)));
    }

    public function appointment(Request $request, string $id): JsonResponse
    {
        $organization = $this->access->organizationFor($request->user(), 'viewAppointments');

        return $this->respond(['data' => $this->bookings->appointment($organization, $id)]);
    }

    public function createAppointment(Request $request): JsonResponse
    {
        $user = $request->user();
        $actions = $request->has('customer') ? ['createAppointment', 'createCustomer'] : ['createAppointment'];
        $organization = $this->access->organizationFor($user, ...$actions);
        $input = $request->validate([
            'id' => ['required', 'uuid'],
            'service_id' => ['required', 'uuid'],
            'customer_id' => ['required_without:customer', 'prohibits:customer', 'uuid'],
            'customer' => ['required_without:customer_id', 'prohibits:customer_id', 'array:name,phone,note'],
            'customer.name' => ['required_with:customer', 'string', 'max:100'],
            'customer.phone' => ['required_with:customer', 'string', 'max:40'],
            'customer.note' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->scheduleRules(),
        ]);

        return $this->respond(['data' => $this->bookings->createAppointment($organization, $user->id, $user->name, $input)], 201);
    }

    public function reschedule(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $organization = $this->access->organizationFor($user, 'rescheduleAppointment');
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], ...$this->scheduleRules()]);

        return $this->respond(['data' => $this->bookings->reschedule($organization, $user->id, $user->name, $id, $input)]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $organization = $this->access->organizationFor($user, 'cancelAppointment');
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']]);

        return $this->respond(['data' => $this->bookings->cancel($organization, $user->id, $user->name, $id, $input)]);
    }

    private function customerRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function pageRules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']];
    }

    private function scheduleRules(): array
    {
        return ['date' => $this->dateRules(), 'start_time' => ['required', 'date_format:H:i']];
    }

    private function dateRules(): array
    {
        return ['required', 'date_format:Y-m-d', 'regex:/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/'];
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
