<?php

namespace Modules\Admin\Http\Resources\Concerns;

use Illuminate\Http\Request;

trait DetectsDetailView
{
    /**
     * Whether this resource is being rendered for a single-item "show" response
     * (both ar/en included) rather than a list/index response (single locale only).
     *
     * Checks the controller action name (`show`), not the route's ->name(), since
     * most admin routes here are defined without an explicit name at all — a
     * route-name check silently always resolves to false for those and the
     * resource falls back to single-locale output even for a real detail view.
     */
    protected function isDetailView(Request $request): bool
    {
        return $request->route() && $request->route()->getActionMethod() === 'show';
    }
}
