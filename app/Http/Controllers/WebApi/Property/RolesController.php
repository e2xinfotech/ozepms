<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\RoleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\SaveRoleRequest;
use App\Models\Role;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** System roles (read-only) and the custom roles of the current property. */
class RolesController extends Controller
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly RoleService $roles,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'roles' => PermissionCatalogue::rolesFor($this->context->id()),
            'catalogue' => PermissionCatalogue::grouped('property'),
        ]);
    }

    public function store(SaveRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create(
            $this->context->property(),
            (string) $request->validated('name'),
            $request->validated('description'),
            (string) $request->validated('color'),
            $request->validated('permissions'),
        );

        return response()->json(['message' => __('roles.saved'), 'role' => PermissionCatalogue::role($role->load('permissions'), 0)], 201);
    }

    public function update(SaveRoleRequest $request, mixed $property, string $role): JsonResponse
    {
        $model = $this->roles->update(
            $this->find($role),
            (string) $request->validated('name'),
            $request->validated('description'),
            (string) $request->validated('color'),
            $request->validated('permissions'),
        );

        return response()->json(['message' => __('roles.saved'), 'role' => PermissionCatalogue::role($model->load('permissions'))]);
    }

    public function destroy(mixed $property, string $role): JsonResponse
    {
        $this->roles->delete($this->find($role));

        return response()->json(['message' => __('roles.deleted')]);
    }

    /** Custom roles of this property first, then system roles (which the service refuses to change). */
    private function find(string $code): Role
    {
        $role = Role::query()->availableTo($this->context->id())->where('code', $code)
            ->orderByDesc('property_id')->first();

        return $role ?? throw new NotFoundHttpException;
    }
}
