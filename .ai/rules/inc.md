---
paths:
  - 'resources/views/base/inc/**'
---

# Inc

## App shell: full-height sidebar owns brand and collapse toggle
The sidebar (inc/sidebar.blade.php) spans the full viewport height on >=992px and carries the brand plus the .sidebar-collapse-toggle button; the header only shows a brand/hamburger below lg. Collapse-to-icons is body.sidebar-minimized, persisted in localStorage('sidebar-minimized') and restored in the before_scripts push. The header's left margin is coupled to the sidebar width in the "App shell" section at the end of resources/scss/_custom.scss, so any change to sidebar width must be made there too. Run `npm run build` after SCSS edits; compiled assets are committed.
