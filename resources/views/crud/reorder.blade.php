@extends(backpack_view('blank'))

@php
    $defaultBreadcrumbs = [
        trans('backpack::crud.admin') => url(config('backpack.base.route_prefix'), 'dashboard'),
        $crud->entity_name_plural => url($crud->route),
        trans('backpack::crud.reorder') => false,
    ];

    // if breadcrumbs aren't defined in the CrudController, use the default breadcrumbs
    $breadcrumbs = $breadcrumbs ?? $defaultBreadcrumbs;
@endphp

@section('header')
    <div class="container-fluid">
        <h2>
            <span class="text-capitalize">{!! $crud->getHeading() ?? $crud->entity_name_plural !!}</span>
            <small>{!! $crud->getSubheading() ?? trans('backpack::crud.reorder') . ' ' . $crud->entity_name_plural !!}.</small>

            @if ($crud->hasAccess('list'))
                <small><a href="{{ url($crud->route) }}" class="d-print-none font-sm"><i class="la la-angle-double-left"></i>
                        {{ trans('backpack::crud.back_to_all') }} <span>{{ $crud->entity_name_plural }}</span></a></small>
            @endif
        </h2>
    </div>
@endsection

@section('content')
    @php
        // Flatten the tree into display order: depth 1 = root, children directly after their parent.
        $keyName = $crud->getModel()->getKeyName();
        $labelAttribute = $crud->get('reorder.label');
        $allEntries = collect($entries->all())->sortBy('lft')->values();
        $byParent = $allEntries->groupBy(fn ($entry) => (int) ($entry->parent_id ?? 0));
        $rows = [];
        $flatten = function ($parentId, $depth) use (&$flatten, &$rows, $byParent, $keyName, $labelAttribute) {
            foreach ($byParent->get($parentId, collect()) as $entry) {
                $rows[] = [
                    'id' => $entry->getKey(),
                    'label' => \Illuminate\Support\Str::limit((string) object_get($entry, $labelAttribute), 120),
                    'depth' => $depth,
                ];
                $flatten((int) $entry->getKey(), $depth + 1);
            }
        };
        $flatten(0, 1);
        // Entries whose parent is missing from the result set would otherwise vanish; append them as roots.
        $seen = collect($rows)->pluck('id');
        foreach ($allEntries as $entry) {
            if (! $seen->contains($entry->getKey())) {
                $rows[] = ['id' => $entry->getKey(), 'label' => \Illuminate\Support\Str::limit((string) object_get($entry, $labelAttribute), 120), 'depth' => 1];
            }
        }
    @endphp

    <div class="row mt-4">
        <div class="{{ $crud->getReorderContentClass() }}"
             x-data="reorderTree({
                 items: @js($rows),
                 maxLevel: {{ (int) ($crud->get('reorder.max_level') ?? 3) }},
                 url: @js(url(Request::path())),
                 messages: @js([
                     'successTitle' => trans('backpack::crud.reorder_success_title'),
                     'successMessage' => trans('backpack::crud.reorder_success_message'),
                     'errorTitle' => trans('backpack::crud.reorder_error_title'),
                     'errorMessage' => trans('backpack::crud.reorder_error_message'),
                 ]),
             })">
            <div class="card p-4">
                <p>{{ trans('backpack::crud.reorder_text') }}</p>

                <ol class="reorder-list mt-0">
                    <template x-for="(item, index) in items" :key="item.id">
                        <li class="reorder-item"
                            :class="{ 'is-dragging': dragging !== null && index >= dragging && index < dragging + blockSize(dragging), 'is-over': over === index }"
                            :style="{ marginLeft: ((item.depth - 1) * 25) + 'px' }"
                            :draggable="dragging === index"
                            @dragstart="startDrag($event, index)"
                            @dragover.prevent="dragOver($event, index)"
                            @dragend="endDrag()">
                            <div class="reorder-row">
                                <span class="reorder-handle" @mousedown="armDrag(index)" title="{{ trans('backpack::crud.reorder') }}"><i class="la la-arrows-alt"></i></span>
                                <span class="reorder-label" x-text="item.label"></span>
                                <span class="reorder-actions btn-group btn-group-sm">
                                    <button type="button" class="btn btn-light" :disabled="!canOutdent(index)" @click="outdent(index)" title="Outdent"><i class="la la-angle-left"></i></button>
                                    <button type="button" class="btn btn-light" :disabled="!canIndent(index)" @click="indent(index)" title="Indent"><i class="la la-angle-right"></i></button>
                                    <button type="button" class="btn btn-light" :disabled="index === 0" @click="moveUp(index)" title="Move up"><i class="la la-angle-up"></i></button>
                                    <button type="button" class="btn btn-light" :disabled="index + blockSize(index) >= items.length" @click="moveDown(index)" title="Move down"><i class="la la-angle-down"></i></button>
                                </span>
                            </div>
                        </li>
                    </template>
                </ol>

            </div><!-- /.card -->

            <button type="button" class="btn btn-success" :disabled="saving" @click="save()">
                <i class="la la-save"></i> {{ trans('backpack::crud.save') }}
            </button>
        </div>
    </div>
@endsection


@section('after_styles')
    <style>
        .reorder-list {
            margin: 1.5em 0 0;
            padding: 0;
            list-style: none;
        }

        .reorder-item {
            margin: 5px 0 0;
            transition: margin-left .12s ease-out;
        }

        .reorder-row {
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            background-color: #f4f4f4;
            color: #444;
        }

        .reorder-handle {
            cursor: grab;
            color: #869ab8;
            padding: 0 .25rem;
        }

        .reorder-label {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .reorder-item.is-dragging .reorder-row {
            opacity: .5;
        }

        .reorder-item.is-over .reorder-row {
            outline: 1px dashed #4183C4;
        }
    </style>
@endsection

@section('after_scripts')
    <script>
        document.addEventListener('alpine:init', function () {
            Alpine.data('reorderTree', function (config) {
                return {
                    items: config.items,
                    maxLevel: config.maxLevel,
                    dragging: null,
                    over: null,
                    dragStartX: 0,
                    dragStartDepth: 1,
                    saving: false,

                    /** Number of rows in the block starting at index: the item plus all its descendants. */
                    blockSize: function (index) {
                        var size = 1;
                        while (index + size < this.items.length && this.items[index + size].depth > this.items[index].depth) {
                            size++;
                        }
                        return size;
                    },

                    /** Deepest depth inside a block, relative to the block's root. */
                    blockRelativeDepth: function (index) {
                        var deepest = 0;
                        for (var i = index; i < index + this.blockSize(index); i++) {
                            deepest = Math.max(deepest, this.items[i].depth - this.items[index].depth);
                        }
                        return deepest;
                    },

                    maxDepthFor: function (index) {
                        var previous = index > 0 ? this.items[index - 1].depth + 1 : 1;
                        return Math.min(previous, this.maxLevel - this.blockRelativeDepth(index));
                    },

                    setDepth: function (index, depth) {
                        depth = Math.max(1, Math.min(depth, this.maxDepthFor(index)));
                        var delta = depth - this.items[index].depth;
                        if (delta === 0) {
                            return;
                        }
                        for (var i = index; i < index + this.blockSize(index); i++) {
                            this.items[i].depth += delta;
                        }
                    },

                    /** After a move, no row may sit more than one level below the row above it. */
                    normalize: function () {
                        for (var i = 0; i < this.items.length; i++) {
                            var max = i === 0 ? 1 : this.items[i - 1].depth + 1;
                            this.items[i].depth = Math.max(1, Math.min(this.items[i].depth, max, this.maxLevel));
                        }
                    },

                    canIndent: function (index) { return this.items[index].depth < this.maxDepthFor(index); },
                    canOutdent: function (index) { return this.items[index].depth > 1; },
                    indent: function (index) { this.setDepth(index, this.items[index].depth + 1); this.normalize(); },
                    outdent: function (index) { this.setDepth(index, this.items[index].depth - 1); this.normalize(); },

                    /** Move the block at `from` so it starts at position `to` (index in the list without the block). */
                    moveBlock: function (from, to) {
                        var block = this.items.splice(from, this.blockSize(from));
                        this.items.splice(to, 0, ...block);
                        this.normalize();
                        return to;
                    },

                    moveUp: function (index) {
                        if (index === 0) return;
                        var target = index - 1;
                        while (target > 0 && this.items[target].depth > this.items[index].depth) target--;
                        this.moveBlock(index, target);
                    },

                    moveDown: function (index) {
                        var next = index + this.blockSize(index);
                        if (next >= this.items.length) return;
                        this.moveBlock(index, next + this.blockSize(next) - this.blockSize(index));
                    },

                    armDrag: function (index) { this.dragging = index; },

                    startDrag: function (event, index) {
                        this.dragging = index;
                        this.dragStartX = event.clientX;
                        this.dragStartDepth = this.items[index].depth;
                        event.dataTransfer.effectAllowed = 'move';
                    },

                    dragOver: function (event, index) {
                        if (this.dragging === null) return;
                        var size = this.blockSize(this.dragging);

                        // reorder: hovering a row outside the dragged block moves the block before/after it
                        if (index < this.dragging || index >= this.dragging + size) {
                            var rect = event.currentTarget.getBoundingClientRect();
                            var after = event.clientY > rect.top + rect.height / 2;
                            var target = index + (after ? 1 : 0);
                            if (target > this.dragging) target -= size;
                            if (target !== this.dragging) {
                                this.dragging = this.moveBlock(this.dragging, target);
                            }
                        }

                        // nest: dragging sideways changes depth in 25px steps
                        this.over = this.dragging;
                        var delta = Math.round((event.clientX - this.dragStartX) / 25);
                        this.setDepth(this.dragging, this.dragStartDepth + delta);
                        this.normalize();
                    },

                    endDrag: function () { this.dragging = null; this.over = null; },

                    /** Nested-set payload in the shape saveReorder() expects (roots at depth 1, left starts at 2). */
                    toTree: function () {
                        var tree = [], stack = [], counter = 2;
                        var close = function (node) { node.right = counter++; };
                        this.items.forEach(function (item) {
                            while (stack.length && stack[stack.length - 1].depth >= item.depth) {
                                close(stack.pop());
                            }
                            var node = {
                                item_id: item.id,
                                parent_id: stack.length ? stack[stack.length - 1].item_id : null,
                                depth: item.depth,
                                left: counter++,
                                right: null
                            };
                            tree.push(node);
                            stack.push(node);
                        });
                        while (stack.length) close(stack.pop());
                        return tree;
                    },

                    save: function () {
                        var self = this;
                        this.saving = true;
                        fetch(config.url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({ tree: this.toTree() })
                        }).then(function (response) {
                            if (! response.ok) throw new Error(response.statusText);
                            new Noty({ type: 'success', text: '<strong>' + config.messages.successTitle + '</strong><br>' + config.messages.successMessage }).show();
                        }).catch(function () {
                            new Noty({ type: 'error', text: '<strong>' + config.messages.errorTitle + '</strong><br>' + config.messages.errorMessage }).show();
                        }).finally(function () {
                            self.saving = false;
                        });
                    }
                };
            });
        });
    </script>
@endsection
