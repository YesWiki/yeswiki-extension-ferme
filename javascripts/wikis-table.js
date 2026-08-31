$(document).ready(function() {
  //dirty hack for full width for wiki table
  $('.page > .row-fluid.row').addClass('full-width')

  var $table = $('#wikis-table');
  var $config = $('#upgrade-wikis-config');
  var apiUrl = $table.data('api-url');
  var upgradeUrl = $config.data('upgrade-url');
  var deleteUrl = $config.data('delete-url');
  var searchUrl = $config.data('search-url');
  var adminAddUrl = $config.data('admin-add-url');
  var adminRemoveUrl = $config.data('admin-remove-url');
  var csrfToken = $config.data('csrf-token');
  var i18n = $config.data();
  var tableI18n = {
    see: $table.data('i18n-see'),
    edit: $table.data('i18n-edit'),
    backup: $table.data('i18n-backup'),
    del: $table.data('i18n-delete'),
    confirmDelete: $table.data('i18n-confirm-delete')
  };

  var selectedWikis = {};

  function esc(str) {
    return $('<span>').text(str || '').html();
  }

  var columns = [
    {
      data: null,
      defaultContent: '',
      orderable: false,
      render: function(data, type, row) {
        if (!row.folder) { return ''; }
        return '<div class="checkbox"><label>'
          + '<input type="checkbox" class="wiki-checkbox"'
          + ' value="' + esc(row.folder) + '"'
          + ' data-title="' + esc(row.title) + '"'
          + ' data-id-fiche="' + esc(row.id_fiche) + '">'
          + '<span></span></label></div>';
      }
    },
    {
      data: null,
      defaultContent: '',
      orderable: true,
      render: function(data, type, row) {
        var html = '<strong class="wiki-title"><a href="' + esc(row.url) + '">' + esc(row.title) + '</a></strong>';
        if (row.description) {
          html += '<div class="wiki-desc">' + esc(row.description) + '</div>';
        }
        if (row.error) {
          html += row.error; // already safe HTML from server
        }
        return html;
      }
    },
    {
      data: null,
      defaultContent: '',
      orderable: true,
      render: function(data, type, row) {
        var html = esc(row.referent);
        if (row.mail) {
          html += '<br><a href="mailto:' + esc(row.mail) + '">' + esc(row.mail) + '</a>';
        }
        return html;
      }
    },
    {
      data: null,
      defaultContent: '',
      orderable: true,
      render: function(data, type, row) {
        if (!row.dashboard_link) { return esc(row.last_modification); }
        return '<a class="modalbox" href="' + esc(row.dashboard_link) + '">' + esc(row.last_modification) + '</a>';
      }
    },
    { data: 'admin', orderable: false, render: function(data) { return data || ''; } },
    { data: 'version', orderable: false, render: function(data) { return data || ''; } },
    {
      data: null,
      defaultContent: '',
      orderable: false,
      render: function(data, type, row) {
        return '<a class="btn btn-default btn-xs" data-toggle="tooltip" data-placement="bottom"'
          + ' title="' + esc(tableI18n.see) + '" href="' + esc(row.view_url) + '">'
          + '<i class="fa fa-eye"></i></a> '
          + '<a class="btn btn-default btn-xs" data-toggle="tooltip" data-placement="bottom"'
          + ' title="' + esc(tableI18n.edit) + '" href="' + esc(row.edit_url) + '">'
          + '<i class="fa fa-pencil-alt"></i></a> '
          + '<a class="btn btn-default btn-xs" data-toggle="tooltip" data-placement="bottom"'
          + ' title="' + esc(tableI18n.backup) + '" href="">';
        //+ '<i class="fas fa-file-archive"></i></a> ';
      }
    }
  ];

  var dtBase = typeof DATATABLE_OPTIONS !== 'undefined' ? DATATABLE_OPTIONS : {};
  var wikisTable = $table.DataTable($.extend({}, dtBase, {
    serverSide: true,
    processing: true,
    paging: true,
    pageLength: 100,
    ajax: {
      url: apiUrl,
      type: 'POST'
    },
    columns: columns,
    dom: (dtBase.dom ? dtBase.dom : "<'row'<'col-sm-6'l><'col-sm-6'f>><'row'<'col-sm-12'tr>><'row'<'col-sm-6'i><'col-sm-6'<'pull-right'B>>>")
      + "<'row'<'col-sm-12'p>>"
  }));

  // Keep the processing overlay centred over the table (not the whole viewport)
  wikisTable.on('processing.dt', function(e, settings, processing) {
    if (!processing) { return; }
    var $container = $table.closest('.table-responsive');
    var offset = $container.offset();
    var top = offset.top - $(window).scrollTop() + $container.outerHeight() / 2;
    var left = offset.left - $(window).scrollLeft() + $container.outerWidth() / 2;
    $('#wikis-table_processing').css({ top: top, left: left });
  });

  // After each draw, restore checkbox state for visible rows and sync select-all
  wikisTable.on('draw', function() {
    $table.find('.wiki-checkbox').each(function() {
      $(this).prop('checked', !!(selectedWikis[$(this).val()]));
    });
    updateSelectAllState();
  });

  function updateSelectAllState() {
    var $boxes = $table.find('.wiki-checkbox');
    var total = $boxes.length;
    var checked = $boxes.filter(':checked').length;
    $('#select-all-wikis')
      .prop('indeterminate', checked > 0 && checked < total)
      .prop('checked', total > 0 && checked === total);
  }

  // Every bulk button is driven by the same selection, so they all update together
  var bulkButtons = [
    { btn: '#btn-upgrade-selected', badge: '#upgrade-selected-count' },
    { btn: '#btn-delete-selected', badge: '#delete-selected-count' },
    { btn: '#btn-admin-add-selected', badge: '#admin-add-selected-count' },
    { btn: '#btn-admin-remove-selected', badge: '#admin-remove-selected-count' }
  ];

  function updateBulkBtns() {
    var count = Object.keys(selectedWikis).length;
    bulkButtons.forEach(function(b) {
      $(b.btn).prop('disabled', count === 0);
      if (count > 0) {
        $(b.badge).text(count).show();
      } else {
        $(b.badge).hide();
      }
    });
  }

  // Select/deselect all wikis on the current page
  $('#select-all-wikis').on('change', function() {
    var checked = $(this).is(':checked');
    $table.find('.wiki-checkbox').each(function() {
      $(this).prop('checked', checked);
      var folder = $(this).val();
      var title = $(this).data('title');
      var idFiche = $(this).data('id-fiche');
      if (checked) {
        selectedWikis[folder] = { title: title, idFiche: idFiche };
      } else {
        delete selectedWikis[folder];
      }
    });
    updateBulkBtns();
  });

  // Individual checkboxes — delegated for DataTables re-render safety
  $(document).on('change', '#wikis-table .wiki-checkbox', function() {
    var folder = $(this).val();
    var title = $(this).data('title');
    var idFiche = $(this).data('id-fiche');
    if ($(this).is(':checked')) {
      selectedWikis[folder] = { title: title, idFiche: idFiche };
    } else {
      delete selectedWikis[folder];
    }
    updateSelectAllState();
    updateBulkBtns();
  });

  $('#btn-upgrade-selected').on('click', function() {
    if (!$(this).prop('disabled')) {
      $('#upgrade-selected-modal').modal('show');
    }
  });

  $('#upgrade-selected-modal').on('shown.bs.modal', function() {
    var wikis = Object.keys(selectedWikis).map(function(folder) {
      return { folder: folder, title: selectedWikis[folder].title };
    });

    var $list = $('#upgrade-wikis-list').empty();
    wikis.forEach(function(wiki) {
      var $item = $('<div class="list-group-item">').attr('id', 'upgrade-item-' + wiki.folder);
      var $header = $('<div>').css('display', 'flex').css('align-items', 'center').css('gap', '8px');
      $header.append($('<i class="fas fa-clock upgrade-icon text-muted">'));
      $header.append($('<strong class="upgrade-wiki-name flex-grow-1">').text(wiki.title));
      $header.append($('<span class="badge upgrade-badge">').text(i18n.pending).css('margin-left', 'auto'));
      $item.append($header);
      $item.append(
        $('<div class="upgrade-output" style="display:none; margin-top:8px;">')
          .append($('<pre style="max-height:200px; overflow-y:auto; margin:0;">'))
      );
      $list.append($item);
    });

    upgradeSequential(wikis, 0);
  });

  $('#btn-delete-selected').on('click', function() {
    if ($(this).prop('disabled')) { return; }
    var wikis = Object.keys(selectedWikis).map(function(folder) {
      return { folder: folder, title: selectedWikis[folder].title, idFiche: selectedWikis[folder].idFiche };
    });
    // Populate confirmation preview
    var $preview = $('#delete-wikis-preview').empty();
    wikis.forEach(function(wiki) {
      $preview.append($('<div class="list-group-item">').text(wiki.title));
    });
    // Reset modal to confirmation stage
    $('#delete-confirm-stage').show();
    $('#delete-progress-stage').hide();
    $('#delete-footer-confirm').show();
    $('#delete-footer-progress').hide();
    $('#btn-close-delete-modal').prop('disabled', true);
    $('#delete-selected-modal').modal('show');
  });

  $('#btn-start-delete').on('click', function() {
    var wikis = Object.keys(selectedWikis).map(function(folder) {
      return { folder: folder, title: selectedWikis[folder].title, idFiche: selectedWikis[folder].idFiche };
    });
    // Switch to progress stage
    $('#delete-confirm-stage').hide();
    $('#delete-footer-confirm').hide();
    var $list = $('#delete-wikis-list').empty();
    wikis.forEach(function(wiki) {
      var $item = $('<div class="list-group-item">').attr('id', 'delete-item-' + wiki.folder);
      var $header = $('<div>').css('display', 'flex').css('align-items', 'center').css('gap', '8px');
      $header.append($('<i class="fas fa-clock delete-icon text-muted">'));
      $header.append($('<strong class="flex-grow-1">').text(wiki.title));
      $header.append($('<span class="badge delete-badge">').text(i18n.pending).css('margin-left', 'auto'));
      $item.append($header);
      $item.append(
        $('<div class="delete-output" style="display:none; margin-top:8px;">')
          .append($('<pre style="max-height:100px; overflow-y:auto; margin:0;">'))
      );
      $list.append($item);
    });
    $('#delete-progress-stage').show();
    $('#delete-footer-progress').show();
    deleteSequential(wikis, 0);
  });

  function deleteSequential(wikis, index) {
    if (index >= wikis.length) {
      $('#btn-close-delete-modal').prop('disabled', false);
      wikisTable.ajax.reload(null, false); // refresh table without resetting pagination
      return;
    }

    var wiki = wikis[index];
    var $item = $('#delete-item-' + wiki.folder);
    $item.find('.delete-icon').attr('class', 'fas fa-spinner fa-spin delete-icon text-info');
    $item.find('.delete-badge').text(i18n.deleting).css('background-color', '#5bc0de');

    $.ajax({
      url: deleteUrl,
      method: 'POST',
      data: { id_fiche: wiki.idFiche, 'csrf-token': csrfToken },
      dataType: 'json',
      success: function(response) {
        if (response.success) {
          $item.find('.delete-icon').attr('class', 'fas fa-check delete-icon text-success');
          $item.find('.delete-badge').text(i18n.deleteSuccess).css('background-color', '#5cb85c');
          delete selectedWikis[wiki.folder];
          updateBulkBtns();
          deleteSequential(wikis, index + 1);
        } else {
          $item.find('.delete-icon').attr('class', 'fas fa-times delete-icon text-danger');
          $item.find('.delete-badge').text(i18n.deleteError).css('background-color', '#d9534f');
          if (response.error) {
            $item.find('.delete-output pre').text(response.error);
            $item.find('.delete-output').show();
          }
          $('#btn-close-delete-modal').prop('disabled', false);
        }
      },
      error: function(xhr, status, error) {
        $item.find('.delete-icon').attr('class', 'fas fa-times delete-icon text-danger');
        $item.find('.delete-badge').text(i18n.deleteError).css('background-color', '#d9534f');
        // a rejected token comes back as 403 with the reason in the body
        $item.find('.delete-output pre').text((xhr.responseJSON && xhr.responseJSON.error) || ('HTTP error: ' + error));
        $item.find('.delete-output').show();
        $('#btn-close-delete-modal').prop('disabled', false);
      }
    });
  }

  // A `wiki` parameter would be swallowed by the YesWiki router, so the folder goes in the body
  function adminAjax(folder, action) {
    return $.ajax({
      url: action === 'remove' ? adminRemoveUrl : adminAddUrl,
      method: 'POST',
      data: { folder: folder, 'csrf-token': csrfToken },
      dataType: 'json'
    });
  }

  // Admin add/remove — delegated for DataTables re-render safety
  $(document).on('click', '#wikis-table .admin-action-btn', function() {
    var $btn = $(this);
    var action = $btn.data('admin-action');
    var wiki = $btn.data('admin-wiki');

    if (!(action === 'remove' ? adminRemoveUrl : adminAddUrl)) {
      console.warn('[ferme] admin action URL not configured (data-admin-' + action + '-url missing)');
      return;
    }

    $btn.prop('disabled', true).prepend('<i class="fas fa-spinner fa-spin" style="margin-right:4px;"></i>');

    adminAjax(wiki, action)
      .done(function(response) {
        if (response && response.success) {
          wikisTable.ajax.reload(null, false);
        } else {
          $btn.prop('disabled', false).find('.fa-spinner').remove();
          $btn.attr('title', (response && response.error) || i18n.adminError);
        }
      })
      .fail(function(xhr) {
        $btn.prop('disabled', false).find('.fa-spinner').remove();
        $btn.attr('title', (xhr.responseJSON && xhr.responseJSON.error) || i18n.adminError);
      });
  });

  // Bulk admin add/remove over the current selection
  $('#btn-admin-add-selected').on('click', function() { openAdminModal('add'); });
  $('#btn-admin-remove-selected').on('click', function() { openAdminModal('remove'); });

  function openAdminModal(action) {
    if ($(action === 'add' ? '#btn-admin-add-selected' : '#btn-admin-remove-selected').prop('disabled')) {
      return;
    }

    var wikis = Object.keys(selectedWikis).map(function(folder) {
      return { folder: folder, title: selectedWikis[folder].title };
    });

    $('#admin-selected-modal-label-text').text(action === 'add' ? i18n.adminAddSelected : i18n.adminRemoveSelected);
    $('#admin-selected-modal-icon').attr('class', action === 'add' ? 'fas fa-user-plus' : 'fas fa-user-minus');
    $('#admin-selected-intro').text(action === 'add' ? i18n.adminAddIntro : i18n.adminRemoveIntro);
    $('#btn-close-admin-modal').prop('disabled', true);

    var $list = $('#admin-wikis-list').empty();
    wikis.forEach(function(wiki) {
      var $item = $('<div class="list-group-item">').attr('id', 'admin-item-' + wiki.folder);
      var $header = $('<div>').css('display', 'flex').css('align-items', 'center').css('gap', '8px');
      $header.append($('<i class="fas fa-clock admin-icon text-muted">'));
      $header.append($('<strong class="flex-grow-1">').text(wiki.title || wiki.folder));
      $header.append($('<span class="badge admin-badge">').text(i18n.pending).css('margin-left', 'auto'));
      $item.append($header);
      $item.append(
        $('<div class="admin-output" style="display:none; margin-top:8px;">')
          .append($('<pre style="max-height:100px; overflow-y:auto; margin:0;">'))
      );
      $list.append($item);
    });

    $('#admin-selected-modal').modal('show');
    adminSequential(wikis, 0, action);
  }

  // One wiki at a time: each call talks to another database, and a failure on one
  // wiki must not hide the outcome of the others.
  function adminSequential(wikis, index, action) {
    if (index >= wikis.length) {
      $('#btn-close-admin-modal').prop('disabled', false);
      wikisTable.ajax.reload(null, false);
      return;
    }

    var wiki = wikis[index];
    var $item = $('#admin-item-' + wiki.folder);
    $item.find('.admin-icon').attr('class', 'fas fa-spinner fa-spin admin-icon text-info');
    $item.find('.admin-badge').text(i18n.inProgress).css('background-color', '#5bc0de');

    function failed(message) {
      $item.find('.admin-icon').attr('class', 'fas fa-times admin-icon text-danger');
      $item.find('.admin-badge').text(i18n.adminError).css('background-color', '#d9534f');
      if (message) {
        $item.find('.admin-output pre').text(message);
        $item.find('.admin-output').show();
      }
    }

    adminAjax(wiki.folder, action)
      .done(function(response) {
        if (response && response.success) {
          $item.find('.admin-icon').attr('class', 'fas fa-check admin-icon text-success');
          $item.find('.admin-badge')
            .text(action === 'add' ? i18n.adminAdded : i18n.adminRemoved)
            .css('background-color', '#5cb85c');
        } else {
          failed(response && response.error);
        }
      })
      .fail(function(xhr, status, error) {
        failed((xhr.responseJSON && xhr.responseJSON.error) || ('HTTP error: ' + error));
      })
      .always(function() {
        // keep going: the remaining wikis are independent of the one that just failed
        adminSequential(wikis, index + 1, action);
      });
  }

  $('#btn-search-wikis').on('click', function() {
    $('#search-loading-stage').show();
    $('#search-results-stage').hide();
    $('#btn-close-search-modal').prop('disabled', true);
    $('#search-wikis-modal').modal('show');
  });

  $('#search-wikis-modal').on('shown.bs.modal', function() {
    $.ajax({
      url: searchUrl,
      method: 'POST',
      data: { 'csrf-token': csrfToken },
      dataType: 'json',
      success: function(response) {
        var $list = $('#search-wikis-list').empty();
        var $summary = $('#search-summary');
        $summary
          .removeClass('alert-danger alert-success alert-info')
          .addClass(response.imported.length > 0 ? 'alert-success' : 'alert-info')
          .text(
            response.wikisOnServer + ' wiki(s) sur le serveur, ' +
            response.wikisInBazar + ' dans la ferme, ' +
            response.imported.length + ' importé(s).'
          );

        response.results.forEach(function(wiki) {
          var $item = $('<div class="list-group-item">');
          var $row = $('<div>').css({ display: 'flex', 'align-items': 'center', gap: '6px', 'flex-wrap': 'wrap' });

          var $name = $('<strong class="flex-grow-1">');
          if (wiki.url) {
            $name.append($('<a>').attr({ href: wiki.url, target: '_blank' }).text(wiki.folder));
          } else {
            $name.text(wiki.folder);
          }
          $row.append($name);

          if (wiki.existsInBazar) {
            $row.append($('<span class="badge">').text(i18n.searchAlreadyInBazar).css('background-color', '#5bc0de'));
          } else {
            $row.append($('<span class="badge">').text(i18n.searchImportedStatus).css('background-color', '#5cb85c'));
          }

          if (wiki.sqlOk) {
            $row.append($('<span class="badge">').text(i18n.searchSqlOk).css('background-color', '#5cb85c'));
            if (!wiki.tablesOk) {
              $row.append(
                $('<span class="badge">').css('background-color', '#f0ad4e')
                  .text(i18n.searchTablesMissing + ': ' + wiki.missingTables.join(', '))
              );
            }
          } else {
            var $sqlBadge = $('<span class="badge">').text(i18n.searchSqlError).css('background-color', '#d9534f');
            if (wiki.sqlError) { $sqlBadge.attr('title', wiki.sqlError); }
            $row.append($sqlBadge);
          }

          $row.append(
            $('<span class="badge">').css('background-color', wiki.httpOk ? '#5cb85c' : '#d9534f')
              .text(wiki.httpOk ? i18n.searchHttpOk : i18n.searchHttpError)
          );

          $item.append($row);
          $list.append($item);
        });

        $('#search-loading-stage').hide();
        $('#search-results-stage').show();
        $('#btn-close-search-modal').prop('disabled', false);

        if (response.imported.length > 0) {
          wikisTable.ajax.reload(null, false);
        }
      },
      error: function(xhr, status, error) {
        var message = (xhr.responseJSON && xhr.responseJSON.error) || error;
        $('#search-loading-stage').html(
          '<div class="alert alert-danger">' + esc(i18n.searchNetworkError) + ': ' + esc(message) + '</div>'
        );
        $('#btn-close-search-modal').prop('disabled', false);
      }
    });
  });

  $('#search-wikis-modal').on('hidden.bs.modal', function() {
    $('#search-loading-stage').show();
    $('#search-results-stage').hide();
    $('#search-wikis-list').empty();
    $('#btn-close-search-modal').prop('disabled', true);
  });

  function upgradeSequential(wikis, index) {
    if (index >= wikis.length) {
      $('#btn-close-upgrade-modal').prop('disabled', false);
      return;
    }

    var wiki = wikis[index];
    var $item = $('#upgrade-item-' + wiki.folder);

    $item.find('.upgrade-icon').attr('class', 'fas fa-spinner fa-spin upgrade-icon text-info');
    $item.find('.upgrade-badge').text(i18n.inProgress).css('background-color', '#5bc0de');

    $.ajax({
      url: upgradeUrl,
      method: 'POST',
      data: { folder: wiki.folder, 'csrf-token': csrfToken },
      dataType: 'json',
      success: function(response) {
        if (response.output) {
          $item.find('.upgrade-output pre').text(response.output);
          $item.find('.upgrade-output').show();
        }
        if (response.success) {
          $item.find('.upgrade-icon').attr('class', 'fas fa-check upgrade-icon text-success');
          $item.find('.upgrade-badge').text(i18n.success).css('background-color', '#5cb85c');
          upgradeSequential(wikis, index + 1);
        } else {
          $item.find('.upgrade-icon').attr('class', 'fas fa-times upgrade-icon text-danger');
          $item.find('.upgrade-badge').text(i18n.error).css('background-color', '#d9534f');
          if (response.error) {
            $item.find('.upgrade-output pre').append((response.output ? '\n\n' : '') + response.error);
            $item.find('.upgrade-output').show();
          }
          $('#btn-close-upgrade-modal').prop('disabled', false);
        }
      },
      error: function(xhr, status, error) {
        $item.find('.upgrade-icon').attr('class', 'fas fa-times upgrade-icon text-danger');
        $item.find('.upgrade-badge').text(i18n.error).css('background-color', '#d9534f');
        $item.find('.upgrade-output pre').text((xhr.responseJSON && xhr.responseJSON.error) || ('HTTP error: ' + error));
        $item.find('.upgrade-output').show();
        $('#btn-close-upgrade-modal').prop('disabled', false);
      }
    });
  }
});