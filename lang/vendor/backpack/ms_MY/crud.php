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
    'save_action_save_and_edit'        => 'Simpan dan kemas kini',
    'save_action_save_and_back'        => 'Simpan dan kembali',
    'save_action_save_and_preview'     => 'Simpan dan previu',
    'save_action_changed_notification' => 'Tetapan simpan telah dikemas kini.',

    // Create form
    'add'                 => 'Tambah Rekod',
    'back_to_all'         => 'Kembali kepada senarai ',
    'cancel'              => 'Batal',
    'add_a_new'           => 'Tambah rekod ',

    // Edit form
    'edit'                 => 'Kemas kini',
    'save'                 => 'Simpan',

    // Translatable models
    'edit_translations' => 'Terjemahan',
    'language'          => 'Bahasa',

    // CRUD table view
    'all'                       => 'Semua ',
    'in_the_database'           => 'dalam simpanan',
    'list'                      => 'Senarai',
    'reset'                     => 'Set semula',
    'actions'                   => 'Tindakan',
    'preview'                   => 'Lihat',
    'delete'                    => 'Hapus',
    'admin'                     => 'Pentadbir',
    'details_row'               => 'Ini ialah baris perincian. Ubah suai seperti yang anda mahu.',
    'details_row_loading_error' => 'Terdapat ralat semasa memuatkan perincian. Sila cuba lagi.',
    'clone'                     => 'Klon',
    'clone_success'             => '<strong>Pendaftaran diklon</strong><br>Pendaftaran baharu telah ditambah, dengan maklumat yang sama seperti ini.',
    'clone_failure'             => '<strong>Pengklonan gagal</strong><br>Pendaftaran baharu tidak dapat dicipta. Sila cuba lagi.',

    // Confirmation messages and bubbles
    'delete_confirm'                              => 'Adakah anda pasti ingin menghapuskan rekod ini?',
    'delete_confirmation_title'                   => 'Rekod terhapus',
    'delete_confirmation_message'                 => 'Rekod telah dihapuskan.',
    'delete_confirmation_not_title'               => 'TIDAK terhapus',
    'delete_confirmation_not_message'             => "Ralat semasa menghapuskan rekod.",
    'delete_confirmation_not_deleted_title'       => 'Tidak terhapus',
    'delete_confirmation_not_deleted_message'     => 'Tiada tindakan. Rekod anda selamat.',

    // Bulk actions
    'bulk_no_entries_selected_title'   => 'Tiada pendaftaran dipilih',
    'bulk_no_entries_selected_message' => 'Sila pilih satu atau lebih item untuk melakukan tindakan pukal ke atasnya.',

    // Bulk delete
    'bulk_delete_are_you_sure'   => 'Adakah anda pasti mahu memadam :number pendaftaran ini?',
    'bulk_delete_sucess_title'   => 'Pendaftaran dipadam',
    'bulk_delete_sucess_message' => ' item telah dipadam',
    'bulk_delete_error_title'    => 'Pemadaman gagal',
    'bulk_delete_error_message'  => 'Satu atau lebih item tidak dapat dipadam',

    // Bulk clone
    'bulk_clone_are_you_sure'   => 'Adakah anda pasti mahu mengklon :number pendaftaran ini?',
    'bulk_clone_sucess_title'   => 'Pendaftaran diklon',
    'bulk_clone_sucess_message' => ' item telah diklon.',
    'bulk_clone_error_title'    => 'Pengklonan gagal',
    'bulk_clone_error_message'  => 'Satu atau lebih pendaftaran tidak dapat dicipta. Sila cuba lagi.',

    // Ajax errors
    'ajax_error_title' => 'Ralat',
    'ajax_error_text'  => 'Ralat semasa membuat carian. Sila refresh browser anda.',

    // DataTables translation
    'emptyTable'     => 'Tiada rekod',
    'info'           => 'Memaparkan _START_ hingga _END_ daripada _TOTAL_ rekod',
    'infoEmpty'      => 'Tiada rekod',
    'infoFiltered'   => '(ditapis daripada _MAX_ jumlah pendaftaran)',
    'infoPostFix'    => '.',
    'thousands'      => ',',
    'lengthMenu'     => '_MENU_ setiap halaman',
    'loadingRecords' => 'Memuatkan...',
    'processing'     => 'Memproses...',
    'search'         => 'Carian',
    'zeroRecords'    => 'Tiada rekod dijumpai',
    'paginate'       => [
        'first'    => 'Pertama',
        'last'     => 'Terakhir',
        'next'     => 'Seterusnya',
        'previous' => 'Sebelumnya',
    ],
    'aria' => [
        'sortAscending'  => ': aktifkan untuk mengisih lajur menaik',
        'sortDescending' => ': aktifkan untuk mengisih lajur menurun',
    ],
    'export' => [
        'export'            => 'Muat Turun',
        'copy'              => 'Salin',
        'excel'             => 'Excel',
        'csv'               => 'CSV',
        'pdf'               => 'PDF',
        'print'             => 'Cetak',
        'column_visibility' => 'Lajur',
    ],

    // global crud - errors
    'unauthorized_access' => 'Akses tidak dibenarkan - anda tidak mempunyai kebenaran yang diperlukan untuk melihat halaman ini.',
    'please_fix'          => 'Sila perbetulkan maklumat ini:',

    // global crud - success / error notification bubbles
    'insert_success' => 'Data dicipta.',
    'update_success' => 'Data dikemas kini.',

    // CRUD reorder view
    'reorder'                      => 'Susun semula',
    'reorder_text'                 => 'Seret dan lepas untuk menyusun semula.',
    'reorder_success_title'        => 'Selesai',
    'reorder_success_message'      => 'Susunan anda telah disimpan.',
    'reorder_error_title'          => 'Ralat',
    'reorder_error_message'        => 'Susunan anda tidak disimpan.',

    // CRUD yes/no
    'yes' => 'Ya',
    'no'  => 'Tidak',

    // CRUD filters navbar view
    'filters'        => 'Tapis',
    'toggle_filters' => 'Ubah Tapisan',
    'remove_filters' => 'Reset',
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
    'browse_uploads'            => 'Lihat muat naik',
    'select_all'                => 'Pilih semua',
    'select_files'              => 'Pilih fail',
    'select_file'               => 'Pilih fail',
    'clear'                     => 'Padam',
    'page_link'                 => 'Pautan halaman',
    'page_link_placeholder'     => 'http://example.com/your-desired-page',
    'internal_link'             => 'Pautan dalaman',
    'internal_link_placeholder' => 'Slug dalaman. Cth: \'admin/page\' (tanpa tanda petik) untuk \':url\'',
    'external_link'             => 'Pautan luaran',
    'choose_file'               => 'Pilih fail',
    'new_item'                  => 'Rekod Baru',
    'select_entry'              => 'Pilih satu pendaftaran',
    'select_entries'            => 'Pilih pendaftaran',

    //Table field
    'table_cant_add'    => 'Tidak dapat menambah :entity baharu',
    'table_max_reached' => 'Had maksimum :max telah dicapai',
    'latlng_search_placeholder' => 'Cari lokasi...',
    'ajax_upload_choose_files' => 'Pilih fail',
    'ajax_upload_drop' => 'atau seret dan lepaskan fail di sini',
    'ajax_upload_too_large' => 'Fail melebihi had :max MB',
    'ajax_upload_failed' => 'Muat naik gagal',
    'ajax_upload_session_expired' => 'Sesi anda telah tamat. Sila muat semula halaman dan cuba lagi.',

    // File manager
    'file_manager' => 'Pengurus Fail',

    // InlineCreateOperation
    'related_entry_created_success' => 'Pendaftaran berkaitan telah dicipta dan dipilih.',
    'related_entry_created_error' => 'Tidak dapat mencipta pendaftaran berkaitan.',

    // returned when no translations found in select inputs
    'empty_translations' => '(kosong)',
];
