---
paths:
  - 'resources/views/crud/buttons/**'
---

# Buttons

## Line action buttons are icon-only with tooltips
Row action buttons (show, update, delete, clone, assume-user) render an icon plus a .sr-only label and carry data-toggle="tooltip" title="..." for the hover label. Tooltips are (re)initialised on every DataTables draw in crud/inc/datatables_logic.blade.php (stale .tooltip elements are removed first). New line buttons should follow the same markup; top-of-list buttons (create, reorder, bulk) keep their text labels.
