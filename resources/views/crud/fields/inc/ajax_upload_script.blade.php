{{-- Shared Alpine component + styles for ajax_upload and ajax_multi_upload (loaded once per page). --}}
@push('crud_fields_styles')
    <style>
        .ajax-upload-files { margin-bottom: .5rem; }
        .ajax-upload-file { display: flex; align-items: center; gap: .5rem; padding: .35rem .5rem; margin-bottom: .25rem; border: 1px solid rgba(0,40,100,.12); border-radius: 3px; background: #fff; }
        .ajax-upload-file.is-dragging { opacity: .5; }
        .ajax-upload-handle { cursor: grab; color: #869ab8; }
        .ajax-upload-thumb { width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 3px; background: #f1f4f8; overflow: hidden; flex: 0 0 36px; font-size: 1.25rem; color: #506690; }
        .ajax-upload-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .ajax-upload-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ajax-upload-name .progress { height: 4px; margin-top: .25rem; }
        .ajax-upload-zone { display: flex; align-items: center; gap: .75rem; padding: .75rem; border: 1px dashed rgba(0,40,100,.25); border-radius: 3px; background: rgba(0,0,0,.02); }
        .ajax-upload-zone.is-over { border-color: var(--primary); background: var(--primary-light-25, rgba(0,0,0,.04)); }
    </style>
@endpush

@push('crud_fields_scripts')
    <script>
        if (! window.bpAjaxUploadDefined) {
            window.bpAjaxUploadDefined = true;

            Alpine.data('bpAjaxUpload', function (config) {
                var nextId = 0;

                return {
                    files: Array.isArray(config.files) ? config.files : [],
                    uploads: [],
                    multiple: !! config.multiple,
                    dragging: null,
                    dragOverZone: false,

                    isImage: function (path) {
                        return /\.(jpe?g|png|gif|webp|svg|avif)$/i.test(path || '');
                    },

                    addFiles: function (fileList) {
                        var list = Array.prototype.slice.call(fileList || []);
                        if (! list.length) return;
                        if (! this.multiple) {
                            list = list.slice(0, 1);
                        }
                        list.forEach(this.upload.bind(this));
                    },

                    upload: function (file) {
                        var self = this;
                        this.uploads.push({ id: ++nextId, name: file.name, progress: 0, error: null });
                        // work through the reactive proxy Alpine stored, so progress/error updates render
                        var entry = this.uploads[this.uploads.length - 1];

                        if (config.maxSizeKb && file.size > config.maxSizeKb * 1024) {
                            entry.error = config.messages.tooLarge;
                            return;
                        }

                        var form = new FormData();
                        form.append('file', file);
                        form.append('disk', config.disk || '');
                        form.append('path', config.path || '');
                        form.append('max_size', config.maxSizeKb || '');

                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', config.uploadUrl);
                        xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').content);
                        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        xhr.setRequestHeader('Accept', 'application/json');
                        xhr.upload.onprogress = function (e) {
                            if (e.lengthComputable) entry.progress = Math.round(e.loaded / e.total * 100);
                        };
                        xhr.onload = function () {
                            var body = null;
                            try { body = JSON.parse(xhr.responseText); } catch (e) {}
                            if (xhr.status >= 200 && xhr.status < 300 && body && body.path) {
                                // single mode: the new file replaces the current one only once it is stored
                                if (! self.multiple) self.files = [];
                                self.files.push({ path: body.path, url: body.url, name: body.name });
                                self.dismiss(entry.id);
                                return;
                            }
                            entry.error = self.errorMessage(xhr.status, body);
                        };
                        xhr.onerror = function () { entry.error = config.messages.failed; };
                        xhr.send(form);
                    },

                    /** Friendly text for a failed upload: validation messages first, then known HTTP statuses. */
                    errorMessage: function (status, body) {
                        if (body && body.errors) {
                            var first = Object.values(body.errors)[0];
                            if (first && first.length) return first[0];
                        }
                        if (status === 413) return config.messages.tooLarge;
                        if (status === 419 || status === 401) return config.messages.sessionExpired;
                        if (body && body.message) return body.message;
                        return config.messages.failed + (status ? ' (' + status + ')' : '');
                    },

                    dismiss: function (id) {
                        this.uploads = this.uploads.filter(function (u) { return u.id !== id; });
                    },

                    remove: function (index) {
                        this.files.splice(index, 1);
                    },

                    startDrag: function (event, index) {
                        this.dragging = index;
                        event.dataTransfer.effectAllowed = 'move';
                    },

                    dragOver: function (index) {
                        if (this.dragging === null || this.dragging === index) return;
                        var moved = this.files.splice(this.dragging, 1)[0];
                        this.files.splice(index, 0, moved);
                        this.dragging = index;
                    }
                };
            });

            // both fields share one init hook: lift x-ignore and mount the component
            window.bpFieldInitAjaxUploadElement = function (element) {
                var container = element.find('.ajax-upload')[0];
                if (! container || container._x_dataStack) return;
                delete container._x_ignore;
                container.removeAttribute('x-ignore');
                Alpine.initTree(container);
            };
        }
    </script>
@endpush
