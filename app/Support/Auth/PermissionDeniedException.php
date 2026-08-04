<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authorization denial carrying the permission (and optional site) for the API 403 shape.
 * Implements render() so the Handler serves this before converting to AccessDeniedHttpException.
 */
final class PermissionDeniedException extends AuthorizationException
{
    public function __construct(
        public readonly Permission $permission,
        public readonly ?int $siteId = null,
    ) {
        parent::__construct('errors.forbidden');
    }

    /**
     * @return array{permission: string, site_id?: int}
     */
    public function data(): array
    {
        $data = ['permission' => $this->permission->value];

        if ($this->siteId !== null) {
            $data['site_id'] = $this->siteId;
        }

        return $data;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => 'errors.forbidden',
            'data' => $this->data(),
        ], 403);
    }
}
