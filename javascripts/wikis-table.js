$(document).ready(function() {
  //dirty hack for full width for wiki table
  $('.page > .row-fluid.row').addClass('full-width')

  var $table = $('#wikis-table');
  var $config = $('#upgrade-wikis-config');
  var apiUrl = $table.data('api-url');
  var upgradeUrl = $config.data('upgrade-url');
  var deleteUrl = $config.data('delete-url');
  var csrfToken = $config.data('csrf-token');
  var i18n = $config.data();
  var tableI18n = {
    see: $table.data('i18n-see'),
    edit: $table.data('i18n-edit'),
    backup: $table.data('i18n-backup'),
    del: $table.data('i18n-delete'),
    confirmDelete: $table.data('i18n-confirm-delete')
  };

  // Track selected wikis across pages: { folder: title }
  var selectedWikis = {};

  function esc(str) {
    return $('<span>').text(str || '').html();
  }

  // Column definitions — server-side handles sorting; columns 0,4,5,6 are not sortable.
  // For columns with data:null, DataTables passes null as the first arg; use the third arg (row).
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
          + ' title="' + esc(tableI18n.backup) + '" href="">'
          + '<i class="fas fa-file-archive"></i></a> ';
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

  function updateUpgradeBtn() {
    var count = Object.keys(selectedWikis).length;
    var $btn = $('#btn-upgrade-selected');
    var $badge = $('#upgrade-selected-count');
    $btn.prop('disabled', count === 0);
    if (count > 0) {
      $badge.text(count).show();
    } else {
      $badge.hide();
    }
  }

  function updateDeleteBtn() {
    var count = Object.keys(selectedWikis).length;
    var $btn = $('#btn-delete-selected');
    var $badge = $('#delete-selected-count');
    $btn.prop('disabled', count === 0);
    if (count > 0) {
      $badge.text(count).show();
    } else {
      $badge.hide();
    }
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
    updateUpgradeBtn();
    updateDeleteBtn();
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
    updateUpgradeBtn();
    updateDeleteBtn();
  });

  // Open modal — Bootstrap 3 ignores data-toggle on disabled buttons
  $('#btn-upgrade-selected').on('click', function() {
    if (!$(this).prop('disabled')) {
      $('#upgrade-selected-modal').modal('show');
    }
  });

  // Populate list and start upgrades once the modal is visible
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

  // ── Delete selected ──────────────────────────────────────────────────────

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
          updateUpgradeBtn();
          updateDeleteBtn();
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
        $item.find('.delete-output pre').text('HTTP error: ' + error);
        $item.find('.delete-output').show();
        $('#btn-close-delete-modal').prop('disabled', false);
      }
    });
  }

  // ── Upgrade sequential ────────────────────────────────────────────────────

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
      data: { wiki: wiki.folder },
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
        $item.find('.upgrade-output pre').text('HTTP error: ' + error);
        $item.find('.upgrade-output').show();
        $('#btn-close-upgrade-modal').prop('disabled', false);
      }
    });
  }
});