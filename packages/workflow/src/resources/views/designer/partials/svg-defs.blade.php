{{-- Shared arrowhead marker referenced by every connection's main-path
     (see the `.drawflow .connection .main-path { marker-end: ... }` rule in
     partials/styles.blade.php). Needs to exist exactly once on the page. --}}
<svg width="0" height="0" style="position: absolute">
    <defs>
        <marker id="wf-arrowhead" markerWidth="10" markerHeight="6" refX="9" refY="3" orient="auto">
            <path d="M0,0 L10,3 L0,6 L2.5,3 Z" fill="#6c757d"></path>
        </marker>
    </defs>
</svg>
