<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backpack Crud Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used by the CRUD interface.
    | You are free to change them to anything
    | you want to customize your views to better match your application.
    |
    */

    // Forms
    'save_action_save_and_new'         => 'Simpan dan cipta baru',
    'save_action_save_and_edit'        => 'Simpan dan kemaskini',
    'save_action_save_and_back'        => 'Simpan dan kembali',
    'save_action_save_and_preview'     => 'Simpan dan previu',
    'save_action_changed_notification' => 'Tetapan simpan telah dikemaskini.',

    // Create form
    'add'                 => 'Tambah',
    'back_to_all'         => 'Kembali kepada senarai ',
    'cancel'              => 'Batal',
    'add_a_new'           => 'Tambah rekod ',

    // Edit form
    'edit'                 => 'Kemaskini',
    'save'                 => 'Simpan',

    // Translatable models
    'edit_translations' => 'Translation',
    'language'          => 'Language',

    // CRUD table view
    'all'                       => 'Semua ',
    'in_the_database'           => 'dalam simpanan',
    'list'                      => 'Senarai',
    'reset'                     => 'Reset',
    'actions'                   => 'Tindakan',
    'preview'                   => 'Lihat',
    'delete'                    => 'Hapus',
    'admin'                     => 'Admin',
    'details_row'               => 'This is the details row. Modify as you please.',
    'details_row_loading_error' => 'There was an error loading the details. Please retry.',
    'clone'                     => 'Clone',
    'clone_success'             => '<strong>Entry cloned</strong><br>A new entry has been added, with the same information as this one.',
    'clone_failure'             => '<strong>Cloning failed</strong><br>The new entry could not be created. Please try again.',

    // Confirmation messages and bubbles
    'delete_confirm'                              => 'Adakah anda pasti ingin menghapuskan rekod ini?',
    'delete_confirmation_title'                   => 'Rekod terhapus',
    'delete_confirmation_message'                 => 'Rekod telah dihapuskan.',
    'delete_confirmation_not_title'               => 'TIDAK terhapus',
    'delete_confirmation_not_message'             => "Ralat semasa menghapuskan rekod.",
    'delete_confirmation_not_deleted_title'       => 'Tidak terhapus',
    'delete_confirmation_not_deleted_message'     => 'Tiada tindakan. Rekod anda selamat.',

    // Bulk actions
    'bulk_no_entries_selected_title'   => 'No entries selected',
    'bulk_no_entries_selected_message' => 'Please select one or more items to perform a bulk action on them.',

    // Bulk delete
    'bulk_delete_are_you_sure'   => 'Are you sure you want to delete these :number entries?',
    'bulk_delete_sucess_title'   => 'Entries deleted',
    'bulk_delete_sucess_message' => ' items have been deleted',
    'bulk_delete_error_title'    => 'Delete failed',
    'bulk_delete_error_message'  => 'One or more items could not be deleted',

    // Bulk clone
    'bulk_clone_are_you_sure'   => 'Are you sure you want to clone these :number entries?',
    'bulk_clone_sucess_title'   => 'Entries cloned',
    'bulk_clone_sucess_message' => ' items have been cloned.',
    'bulk_clone_error_title'    => 'Cloning failed',
    'bulk_clone_error_message'  => 'One or more entries could not be created. Please try again.',

    // Ajax errors
    'ajax_error_title' => 'Ralat',
    'ajax_error_text'  => 'Ralat semasa membuat carian. Sila refresh browser anda.',

    // DataTables translation
    'emptyTable'     => 'Tiada rekod',
    'info'           => 'Memaparkan _START_ hingga _END_ daripada _TOTAL_ rekod',
    'infoEmpty'      => 'Tiada rekod',
    'infoFiltered'   => '(filtered from _MAX_ total entries)',
    'infoPostFix'    => '.',
    'thousands'      => ',',
    'lengthMenu'     => '_MENU_ rekod per halaman',
    'loadingRecords' => 'Loading...',
    'processing'     => 'Processing...',
    'search'         => 'Carian',
    'zeroRecords'    => 'Tiada rekod dijumpai',
    'paginate'       => [
        'first'    => 'Pertama',
        'last'     => 'Terakhir',
        'next'     => 'Seterusnya',
        'previous' => 'Sebelumnya',
    ],
    'aria' => [
        'sortAscending'  => ': activate to sort column ascending',
        'sortDescending' => ': activate to sort column descending',
    ],
    'export' => [
        'export'            => 'Export',
        'copy'              => 'Copy',
        'excel'             => 'Excel',
        'csv'               => 'CSV',
        'pdf'               => 'PDF',
        'print'             => 'Print',
        'column_visibility' => 'Column visibility',
    ],

    // global crud - errors
    'unauthorized_access' => 'Unauthorized access - you do not have the necessary permissions to see this page.',
    'please_fix'          => 'Sila perbetulkan maklumat ini:',

    // global crud - success / error notification bubbles
    'insert_success' => 'Data tercipta.',
    'update_success' => 'Data terkemaskini.',

    // CRUD reorder view
    'reorder'                      => 'Reorder',
    'reorder_text'                 => 'Use drag&drop to reorder.',
    'reorder_success_title'        => 'Done',
    'reorder_success_message'      => 'Your order has been saved.',
    'reorder_error_title'          => 'Error',
    'reorder_error_message'        => 'Your order has not been saved.',

    // CRUD yes/no
    'yes' => 'Ya',
    'no'  => 'Tidak',

    // CRUD filters navbar view
    'filters'        => 'Saringan',
    'toggle_filters' => 'Ubah saringan',
    'remove_filters' => 'Buang saringan',
    'apply' => 'Papar',

    //filters language strings
    'today' => 'Hari Ini',
    'yesterday' => 'Semalam',
    'last_7_days' => '7 Hari Lepas',
    'last_30_days' => '30 Hari Lepas',
    'this_month' => 'Bulan Ini',
    'last_month' => 'Bulan Lepas',
    'custom_range' => 'Julat',
    'weekLabel' => 'M',

    // Fields
    'browse_uploads'            => 'Browse uploads',
    'select_all'                => 'Select All',
    'select_files'              => 'Select files',
    'select_file'               => 'Select file',
    'clear'                     => 'Padam',
    'page_link'                 => 'Page link',
    'page_link_placeholder'     => 'http://example.com/your-desired-page',
    'internal_link'             => 'Internal link',
    'internal_link_placeholder' => 'Internal slug. Ex: \'admin/page\' (no quotes) for \':url\'',
    'external_link'             => 'External link',
    'choose_file'               => 'Choose file',
    'new_item'                  => 'Rekod Baru',
    'select_entry'              => 'Select an entry',
    'select_entries'            => 'Select entries',

    //Table field
    'table_cant_add'    => 'Cannot add new :entity',
    'table_max_reached' => 'Maximum number of :max reached',

    // File manager
    'file_manager' => 'File Manager',

    // InlineCreateOperation
    'related_entry_created_success' => 'Related entry has been created and selected.',
    'related_entry_created_error' => 'Could not create related entry.',

    // returned when no translations found in select inputs
    'empty_translations' => '(empty)',
];
