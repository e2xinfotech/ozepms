<?php

namespace App\Http\Controllers\WebApi\Admin;

use App\Domain\Users\UserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeUserStatusRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\PlatformUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Platform accounts: E2X staff and, read-mostly, every property user. */
class UsersController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function show(Request $request, string $user): JsonResponse
    {
        return response()->json(['user' => (new PlatformUserResource($this->find($user)))->resolve($request)]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->createPlatformUser(
            $request->safe()->only(['name', 'email', 'job_title', 'phone_e164', 'locale']),
            $request->validated('roles'),
            $request->user(),
        );

        return response()->json([
            'message' => __('users.invited'),
            'user' => (new PlatformUserResource($user->refresh()))->resolve($request),
        ], 201);
    }

    /** A property owner, created before any property exists. */
    public function storeOwner(\App\Http\Requests\Admin\StoreOwnerRequest $request): JsonResponse
    {
        $user = $this->users->createOwner($request->safe()->only(['name', 'email', 'job_title', 'phone_e164', 'locale']), $request->user());

        return response()->json([
            'message' => __('users.owner_created'),
            'user' => (new PlatformUserResource($user->refresh()))->resolve($request),
        ], 201);
    }

    public function update(UpdateUserRequest $request, string $user): JsonResponse
    {
        $model = $this->users->updatePlatformUser(
            $this->find($user),
            $request->safe()->only(['name', 'job_title', 'phone_e164', 'locale']),
            $request->has('roles') ? (array) $request->validated('roles') : null,
            $request->user(),
        );

        return response()->json(['message' => __('users.updated'), 'user' => (new PlatformUserResource($model))->resolve($request)]);
    }

    public function status(ChangeUserStatusRequest $request, string $user): JsonResponse
    {
        $model = $this->find($user);
        $this->users->setAccountStatus($model, (string) $request->validated('status'), $request->user());

        return response()->json(['message' => __('users.status_changed'), 'status' => $model->status]);
    }

    public function passwordLink(string $user): JsonResponse
    {
        $model = $this->find($user);
        $this->users->sendPasswordLink($model, request()->user());

        return response()->json(['message' => __('users.link_sent', ['email' => $model->email])]);
    }

    private function find(string $publicId): User
    {
        $user = User::query()->where('public_id', $publicId)->firstOrFail();
        // Super Admin accounts do not exist for anyone below that level.
        abort_if(in_array($user->id, app(\App\Domain\Users\PlatformHierarchy::class)->hiddenUserIds(request()->user()), true), 404);

        return $user;
    }
}
