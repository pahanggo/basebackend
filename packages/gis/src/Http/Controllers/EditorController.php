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
    public function index(Request $request, ?string $slug = null): View
    {
        return view('gis::editor', [
            'bootstrap' => $this->bootstrap($request, $slug),
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
    protected function bootstrap(Request $request, ?string $slug = null): array
    {
        $map = $this->openMap($request, $slug);

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
    protected function openMap(Request $request, ?string $slug = null): ?Map
    {
        $userId = (int) $request->user()->getKey();

        if ($slug !== null) {
            $map = Map::query()->visibleTo($userId)->where('slug', $slug)->first();

            if ($map !== null) {
                return $map;
            }

            // A slug that names nothing this user may open falls through to
            // their own most recent map rather than 404ing. The alternative is
            // a dead end for a link shared by someone with wider access, and
            // the map browser is one click away either way.
        }

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
            'cancel' => __('Cancel'),
            'leave' => __('Leave'),
            'confirmLeave' => __('Leave the map and go back to the dashboard?'),
            'leaveUnsaved' => __('Some changes have not been saved yet. Leaving now would lose them.'),
            'remove' => __('Remove'),
            'coordinateFormat' => __('Click to change the coordinate format'),
            'unsyncedTitle' => __('Unsaved changes'),
            'syncPausedTitle' => __('Changes are no longer being saved'),
            'syncPaused' => __('This map was changed elsewhere, so saving has stopped to avoid overwriting it. Reload the page to continue; changes made since will be lost.'),
            'unsyncedChanges' => __('There are unsaved changes that could not be sent. Switching maps now would lose them.'),

            // The layer tree.
            'filterLayers' => __('Filter layers'),
            'expand' => __('Expand'),
            'collapse' => __('Collapse'),
            'visible' => __('Visible'),
            'locked' => __('Locked'),
            'shared' => __('Shared'),
            'readOnly' => __('Read-only'),
            'layerActions' => __('Layer actions'),
            'rename' => __('Rename'),
            'renameMap' => __('Rename this map'),
            'renameFailed' => __('The map could not be renamed.'),
            'lock' => __('Lock'),
            'unlock' => __('Unlock'),
            'opacity' => __('Opacity'),
            'zoomRange' => __('Zoom range'),
            'minZoom' => __('Minimum zoom'),
            'maxZoom' => __('Maximum zoom'),
            'zoomToLayer' => __('Zoom to layer'),
            'addLayer' => __('Add a layer'),
            'newLayer' => __('New empty layer'),
            'untitledLayer' => __('Untitled layer'),
            'untitledGroup' => __('Untitled group'),
            'groupSelected' => __('Group selected layers'),
            'newGroup' => __('New group'),
            'ungroup' => __('Ungroup'),
            'removeFromMap' => __('Remove from this map'),
            'confirmRemove' => __('Remove ":name" from this map? The layer itself is kept.'),

            // The map control panel.
            'mapControls' => __('Map controls'),
            'basemap' => __('Basemap'),
            'goTo' => __('Go to coordinate'),
            'goToPlaceholder' => __('Latitude, longitude'),
            'coordinateInvalid' => __('That is not a coordinate this can read.'),
            'isolate' => __('Isolate selected'),
            'isolateHint' => __('Show only the selected layers'),
            'fillColour' => __('Fill'),
            'lineColour' => __('Line'),
            'toggleLayers' => __('Show or hide the layers panel'),

            // Sublayers: splitting a layer by one of its attributes.
            'split' => __('Split into sublayers'),
            'resplit' => __('Change how this is split'),
            'stopSplitting' => __('Stop splitting this layer'),
            'sublayerActions' => __('Sublayer actions'),
            'otherClass' => __('Everything else'),
            'noAttributes' => __('This layer has no attributes to split by.'),
            'noValues' => __('No features carry a value for that attribute.'),
            'tooManyValues' => __('That attribute has more than :max different values, which is too many to split by.'),
            'valuesFailed' => __('The values could not be read. Try again.'),
            'close' => __('Close'),

            // Image overlays.
            'addImageOverlay' => __('Add an image overlay'),
            'adjustOverlay' => __('Adjust corners'),
            'overlayLocked' => __('Unlock the layer to adjust it'),
            'overlayName' => __('Name this overlay'),
            'overlayFailed' => __('The image could not be uploaded.'),

            // Drawing tools and their readout.
            'point' => __('Point'),
            'line' => __('Line'),
            'polygon' => __('Polygon'),
            'rectangle' => __('Rectangle'),
            'circle' => __('Circle'),
            'freehand' => __('Freehand'),
            'segment' => __('Segment'),
            'totalLength' => __('Total'),
            'area' => __('Area'),
            'radius' => __('Radius'),
            'width' => __('Width'),
            'height' => __('Height'),
            'rotation' => __('Rotation'),
            'bearing' => __('Bearing'),
            'distance' => __('Distance'),
            'longitude' => __('Longitude'),
            'latitude' => __('Latitude'),
            'place' => __('Place'),
            'drawNeedsLayer' => __('Select a layer you can edit before drawing.'),
            'featureNotEditable' => __('That feature is on a layer you cannot edit.'),

            // Resolving a version conflict.
            'conflictTitle' => __('Someone else changed this too'),
            'conflictHelp' => __('Your changes are held and nothing has been lost. Choose whose version to keep for each row.'),
            'conflictVersions' => __('you had version :yours, the server is at :theirs'),
            'keepMine' => __('Keep mine'),
            'keepTheirs' => __('Keep theirs'),
            'keepAllTheirs' => __('Discard all of mine'),
            'applyResolution' => __('Apply'),

            // Constructive geometry on a selected feature.
            'buffer' => __('Buffer'),
            'simplify' => __('Simplify'),
            'convexHull' => __('Convex hull'),
            'makeValid' => __('Repair'),
            'bufferPrompt' => __('Buffer by how many metres? Negative shrinks.'),
            'simplifyPrompt' => __('Simplify to what tolerance, in metres?'),
            'operationEmpty' => __('That left nothing behind.'),
            'operationFailed' => __('The operation could not be completed.'),
            'legend' => __('Legend'),

            // The attribute table.
            'attributes' => __('Attributes'),
            'filter' => __('Filter'),
            'tableLoading' => __('Loading…'),
            'tableCount' => __(':shown of :loaded features in view'),
            'tableNeedsVisible' => __('Make the layer visible before opening its attributes.'),

            // The query panel.
            'query' => __('Query'),
            'queryLayer' => __('Layer'),
            'queryWithinView' => __('Limit to the current view'),
            'relation' => __('Relation'),
            'relation_intersects' => __('intersects'),
            'relation_within' => __('is within'),
            'relation_contains' => __('contains'),
            'relation_crosses' => __('crosses'),
            'relation_touches' => __('touches'),
            'relation_disjoint' => __('is away from'),
            'bufferMetres' => __('Buffer (metres)'),
            'addCondition' => __('Add a condition'),
            'runQuery' => __('Run'),
            'queryRunning' => __('Searching…'),
            'queryFound' => __(':count found, from :examined in the area'),
            'queryFailed' => __('The query could not be run.'),
            'value' => __('Value'),
            'remove' => __('Remove'),

            // Measurement.
            'measure' => __('Measure'),
            'measure_distance' => __('Distance'),
            'measure_area' => __('Area'),
            'measure_radius' => __('Radius'),
            'measure_diameter' => __('Diameter'),
            'measure_bearing' => __('Bearing'),
            'measure_info' => __('Feature info'),
            'measure_show' => __('Show measurements'),
            'measure_empty' => __('Nothing measured yet. Pick a tool, then click on the map.'),
            'measure_readonly' => __('You may measure here, but not save a measurement.'),
            'measure_delete_title' => __('Delete this measurement?'),
            'measure_delete_text' => __('It will be removed from the map for everyone who can see it.'),
            'save' => __('Save'),
            'zoomToFeature' => __('Zoom to'),
        ];
    }
}
