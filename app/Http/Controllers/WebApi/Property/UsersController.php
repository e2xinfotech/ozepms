<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Users\UserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\StoreUserRequest;
use App\Http\Requests\Property\UpdateUserRequest;
use App\Http\Resources\PropertyUserResource;
use App\Models\PropertyUser;
use App\Models\Role;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Users of the current property. A user is addressed by their public id and is
 * only found through a membership of this property, so other tenants' users answer 404.
 */
class UsersController extends Controller
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly UserService $users,
    ) {}

    public function show(Request $request, mixed $property, string $user): JsonResponse
    {
        return response()->json(['user' => (new PropertyUserResource($this->membership($user)))->resolve($request)]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->safe()->only(['name', 'email', 'job_title', 'phone_e164', 'locale']);
        $existing = \App\Models\User::query()->where('email', strtolower(trim((string) $data['email'])))->exists();

        $membership = $this->users->addToProperty(
            $this->context->property(),
            $data,
            $this->role((string) $request->validated('role')),
            $request->user(),
        );

        return response()->json([
            'message' => $existing ? __('users.added') : __('users.invited'),
            'user' => (new PropertyUserResource($membership->load(['user', 'role'])))->resolve($request),
        ], 201);
    }

    public function update(UpdateUserRequest $request, mixed $property, string $user): JsonResponse
    {
        $membership = $this->membership($user);
        $role = $request->has('role') ? $this->role((string) $request->validated('role')) : null;

        $membership = $this->users->updateMembership(
            $membership,
            $request->safe()->only(['name', 'job_title', 'phone_e164']),
            $role,
            $request->validated('status'),
            $request->user(),
        );

        return response()->json([
            'message' => __('users.updated'),
            'user' => (new PropertyUserResource($membership->load(['user', 'role'])))->resolve($request),
        ]);
    }

    public function destroy(Request $request, mixed $property, string $user): JsonResponse
    {
        $this->users->removeFromProperty($this->membership($user), $request->user());

        return response()->json(['message' => __('users.removed')]);
    }

    public function passwordLink(Request $request, mixed $property, string $user): JsonResponse
    {
        $membership = $this->membership($user);
        $this->users->sendPasswordLink($membership->user);

        return response()->json(['message' => __('users.link_sent', ['email' => $membership->user->email])]);
    }

    private function membership(string $publicId): PropertyUser
    {
        return PropertyUser::query()
            ->whereHas('user', fn ($q) => $q->where('public_id', $publicId))
            ->with(['user', 'role'])
            ->firstOrFail();
    }

    private function role(string $code): Role
    {
        $role = Role::query()->availableTo($this->context->id())->where('code', $code)
            ->orderByDesc('property_id')->first();
        if (! $role) {
            throw ValidationException::withMessages(['role' => __('validation.exists', ['attribute' => __('users.role')])]);
        }

        return $role;
    }
}
