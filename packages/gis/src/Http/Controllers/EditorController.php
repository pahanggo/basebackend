<?php

namespace Gis\Http\Controllers;

use Gis\Http\Resources\MapBootstrap;
use Gis\Models\Map;
use Gis\Support\MapAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EditorController extends Controller
{
    /**
     * Render the full-viewport editor page.
     */
    public function index(Request $request): View
    {
        return view('gis::editor', [
            'bootstrap' => $this->bootstrap($request),
        ]);
    }

    /**
     * The single JSON blob the client reads its configuration from.
     *
     * Everything the editor needs before its first request lives here, so no
     * part of the client reads scattered `data-` attributes or holds English
     * literals of its own (specification sections 3 and 17).
     *
     * @return array<string, mixed>
     */
    protected function bootstrap(Request $request): array
    {
        $map = $this->openMap($request);

        return [
            'csrfToken' => csrf_token(),

            // Half of the idempotency key, and it must be stable for the life
            // of one tab: two tabs are two clients, and a reload that reused
            // the old id could collide with a sequence it did not issue.
            'clientId' => Str::random(12),
            // Root-relative, deliberately. The API is same-origin by design —
            // session cookie and CSRF, never a bearer token — so an absolute
            // URL adds nothing and can be wrong: behind a TLS-terminating
            // proxy the application generates `http://` unless it has been
            // told to trust the proxy, and a URL carried in this JSON blob is
            // one of the few a reverse proxy cannot rewrite on the way out.
            // A relative base takes the page's own scheme and host, always.
            'apiBase' => '/'.trim(config('gis.route.api_prefix'), '/'),

            // The whole bootstrap payload, inlined. The editor opens without a
            // round trip when the user has a map, and opens the map browser
            // when they do not — which is the only state where there is
            // nothing to render.
            'map' => $map === null
                ? null
                : MapBootstrap::make($map, MapAccess::resolve($map, (int) $request->user()->getKey())),

            'capabilities' => MapBootstrap::capabilities(),
            'canRestore' => $request->user()->can(config('gis.route.admin_permission')),
            'view' => config('gis.default_view'),
            'strings' => $this->strings(),
        ];
    }

    /**
     * Which map to open: the one asked for, or the most recently touched.
     *
     * Falling back to the most recent one means returning to the editor puts
     * the user back where they were, which is what they expect and what a
     * browser modal on every visit would undo.
     */
    protected function openMap(Request $request): ?Map
    {
        $userId = (int) $request->user()->getKey();

        if ($requested = $request->integer('map')) {
            $map = Map::query()->visibleTo($userId)->find($requested);

            if ($map !== null) {
                return $map;
            }
        }

        return Map::query()->visibleTo($userId)->orderByDesc('updated_at')->first();
    }

    /** @return array<string, string> */
    protected function strings(): array
    {
        return [
            'backToDashboard' => __('Back to dashboard'),
            'loading' => __('Loading'),
            'maps' => __('Maps'),
            'searchMaps' => __('Search maps'),
            'noMaps' => __('No maps yet'),
            'newMapName' => __('New map name'),
            'createMap' => __('Create map'),
            'confirmDelete' => __('Delete the map ":name"? It can be restored for 30 days.'),
            'showDeleted' => __('Show deleted'),
            'restore' => __('Restore'),
            'copy' => __('Copy'),
            'delete' => __('Delete'),
            'open' => __('Open'),
            'name' => __('Name'),
            'layers' => __('Layers'),
            'features' => __('Features'),
            'updated' => __('Updated'),
            'owner' => __('Owner'),
            'access' => __('Access'),
            'kind' => __('Kind'),
            'allKinds' => __('All kinds'),
            'vector' => __('Vector'),
            'tile' => __('Tiles'),
            'image' => __('Image'),
            'addFromLibrary' => __('Add from library'),
            'searchLayers' => __('Search layers'),
            'noLayers' => __('No layers available'),
            'alreadyAdded' => __('Already added'),
            'baseData' => __('Base data'),
            'addReadOnly' => __('Add read-only'),
            'addEditable' => __('Add editable'),
            'unsyncedChanges' => __('There are unsaved changes that could not be sent. Switching maps now would lose them.'),
        ];
    }
}
