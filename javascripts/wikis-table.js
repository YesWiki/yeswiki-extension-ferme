$(document).ready(function() {
  //dirty hack for full width for wiki table
  $('.page > .row-fluid.row').addClass('full-width')

  var $table = $('#wikis-table');
  var $config = $('#upgrade-wikis-config');
  var apiUrl = $table.data('api-url');
  var upgradeUrl = $config.data('upgrade-url');
  var upgradeExtensionsUrl = $config.data('upgrade-extensions-url');
  var recoverCustomUrl = $config.data('recover-custom-url');
  var refreshStatsUrl = $config.data('refresh-stats-url');
  var activityUrl = $config.data('activity-url');
  var deleteUrl = $config.data('delete-url');
  var searchUrl = $config.data('search-url');
  var adminAddUrl = $config.data('admin-add-url');
  var adminRemoveUrl = $config.data('admin-remove-url');
  var csrfToken = $config.data('csrf-token');
  var csrfTokenUrl = $config.data('csrf-token-url');
  var i18n = $config.data();
  var runModes = {};
  var runMode = null;
  var tableI18n = {
    see: $table.data('i18n-see'),
    edit: $table.data('i18n-edit'),
    backup: $table.data('i18n-backup'),
    del: $table.data('i18n-delete'),
    confirmDelete: $table.data('i18n-confirm-delete')
  };

  var selectedWikis = {};
  var activeFilter = '';
  var chips = [
    { key: 'toUpdate', label: 'chipToUpdate', kind: 'danger', icon: 'sync-alt' },
    { key: 'dormant', label: 'chipDormant', kind: 'default', icon: 'moon' },
    { key: 'heavyArchives', label: 'chipHeavyArchives', kind: 'warning', icon: 'box-archive' },
    { key: 'failed', label: 'chipFailed', kind: 'danger', icon: 'triangle-exclamation' },
    { key: 'unmeasured', label: 'chipUnmeasured', kind: 'default', icon: 'question' }
  ];

  function esc(str) {
    return $('<span>').text(str || '').html();
  }

  function isCsrfFailure(answer) {
    return !!(answer && answer.error && /csrf/i.test(String(answer.error)));
  }

  function refreshCsrfToken() {
    if (!csrfTokenUrl) { return $.Deferred().resolve().promise(); }

    return $.ajax({ url: csrfTokenUrl, method: 'POST', dataType: 'json' })
      .done(function(response) {
        if (response && response.token) { csrfToken = response.token; }
      });
  }

  /**
   * One POST per wiki, carrying the token. A token that went stale while the page
   * was open is fetched again and the call replayed once, and whatever happens the
   * promise resolves, so a queue never stops on one wiki.
   */
  function postWithToken(url, data, retried) {
    var answered = $.Deferred();

    function replay() {
      refreshCsrfToken().always(function() {
        postWithToken(url, data, true).done(function(answer) { answered.resolve(answer); });
      });
    }

    $.ajax({
      url: url,
      method: 'POST',
      data: $.extend({}, data, { 'csrf-token': csrfToken }),
      dataType: 'json'
    }).done(function(response) {
      if (!retried && isCsrfFailure(response)) { replay(); return; }
      answered.resolve(response || { success: false, error: 'empty answer' });
    }).fail(function(xhr, status, error) {
      var answer = xhr.responseJSON || { success: false, error: 'HTTP ' + xhr.status + ': ' + (error || status) };
      if (!retried && isCsrfFailure(answer)) { replay(); return; }
      answered.resolve(answer);
    });

    return answered.promise();
  }

  function summarise($list, ok, failed) {
    var text = String(i18n.i18nRunSummary || '%{ok} / %{failed}')
      .replace('%{ok}', ok)
      .replace('%{failed}', failed);
    $list.prepend($('<div class="list-group-item">').append($('<strong>').text(text)));
  }

  function unknown() {
    return '<span class="ferme-muted" title="' + esc(i18n.i18nNeverMeasured) + '">?</span>';
  }

  function figure(icon, value, label) {
    return '<span class="ferme-figure" title="' + esc(label) + '">'
      + '<i class="fas fa-' + icon + '"></i> ' + esc(String(value)) + '</span>';
  }

  function badge(kind, text, title) {
    return '<span class="label label-' + kind + '"' + (title ? ' title="' + esc(title) + '"' : '') + '>'
      + esc(text) + '</span>';
  }

  function versionBadge(row) {
    if (!row.version) { return ''; }
    var label = (row.version.name || '') + ' ' + (row.version.release || '');
    if (row.version.status === 'outdated' && row.version.update_url) {
      return '<a class="label label-danger" href="' + esc(row.version.update_url) + '">'
        + esc(label) + ' → ' + esc(row.version.source_version) + '</a>';
    }
    if (row.version.status === 'different') {
      return badge('warning', label, i18n.i18nVersionDifferent);
    }
    return badge('default', label);
  }

  function adminBadge(row) {
    if (!row.admin) { return ''; }
    return row.admin.present
      ? badge('success', row.admin.name, i18n.i18nAdminPresent)
      : badge('default', row.admin.name, i18n.i18nAdminAbsent);
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
      orderable: false,
      render: function(data, type, row) {
        var html = '<div class="ferme-identity">'
          + '<strong><a href="' + esc(row.url) + '">' + esc(row.title) + '</a></strong>'
          + '<small>' + esc(row.referent || '')
          + (row.mail ? ' · <a href="mailto:' + esc(row.mail) + '">' + esc(row.mail) + '</a>' : '')
          + '</small>'
          + '<small>' + versionBadge(row) + ' ' + adminBadge(row) + '</small>'
          + '</div>';
        if (row.custom_warning) { html += row.custom_warning; }
        if (row.error) { html += row.error; }
        return html;
      }
    },
    {
      data: null,
      defaultContent: '',
      orderable: false,
      render: function(data, type, row) {
        if (!row.stats) { return unknown(); }
        return '<div class="ferme-figures">'
          + figure('folder-open', row.stats.entries, i18n.totalEntries)
          + figure('file-alt', row.stats.pages, i18n.totalPages)
          + figure('list-alt', row.stats.forms, i18n.totalForms)
          + figure('user', row.stats.users, i18n.totalUsers)
          + '</div>';
      }
    },
    {
      data: null,
      defaultContent: '',
      orderable: false,
      render: function(data, type, row) {
        if (!row.stats) { return '<span class="ferme-muted">' + esc(i18n.i18nNeverMeasured) + '</span>'; }
        return '<div>' + row.stats.sparkline + '</div>'
          + '<small class="ferme-muted" title="' + esc(row.stats.last_activity || '') + '">'
          + esc(row.stats.last_activity_age) + '</small>';
      }
    },
    {
      data: null,
      defaultContent: '',
      orderable: false,
      render: function(data, type, row) {
        if (!row.stats) { return unknown(); }
        var html = '<div title="' + esc(row.stats.disk_detail) + '">' + esc(row.stats.disk) + '</div>'
          + '<small class="ferme-muted">' + esc(row.stats.files) + ' ' + esc(i18n.i18nFiles) + '</small>';
        if (row.stats.heavy_archives) {
          html += '<div><span class="label label-warning">' + esc(i18n.i18nArchives) + ' ' + esc(row.stats.private) + '</span></div>';
        }
        return html;
      }
    },
    {
      data: null,
      defaultContent: '',
      orderable: false,
      render: function(data, type, row) {
        var items = '<li><a href="' + esc(row.view_url) + '"><i class="fa fa-eye fa-fw"></i> ' + esc(tableI18n.see) + '</a></li>'
          + '<li><a href="' + esc(row.edit_url) + '"><i class="fa fa-pencil-alt fa-fw"></i> ' + esc(tableI18n.edit) + '</a></li>';
        if (row.version && row.version.update_url) {
          items += '<li><a href="' + esc(row.version.update_url) + '"><i class="fas fa-sync-alt fa-fw"></i> '
            + esc(i18n.i18nUpdateTo) + ' ' + esc(row.version.source_version) + '</a></li>';
        }
        if (row.admin) {
          items += '<li><a href="#" class="admin-action-btn" data-admin-action="' + (row.admin.present ? 'remove' : 'add') + '"'
            + ' data-admin-wiki="' + esc(row.admin.folder) + '">'
            + '<i class="fas fa-user-' + (row.admin.present ? 'minus' : 'plus') + ' fa-fw"></i> '
            + esc(row.admin.present ? i18n.i18nAdminRemove : i18n.i18nAdminAdd) + '</a></li>';
        }
        return '<div class="btn-group">'
          + '<button type="button" class="btn btn-default btn-xs ferme-detail-toggle" title="' + esc(i18n.i18nDetail) + '">'
          + '<i class="fas fa-chevron-down"></i></button>'
          + '<button type="button" class="btn btn-default btn-xs dropdown-toggle" data-toggle="dropdown">'
          + '<i class="fas fa-ellipsis-h"></i></button>'
          + '<ul class="dropdown-menu dropdown-menu-right">' + items + '</ul>'
          + '</div>';
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
      type: 'POST',
      data: function(data) {
        var sort = String($('#ferme-sort').val() || 'title|asc').split('|');
        data.sort = sort[0];
        data.direction = sort[1] || 'asc';
        data.filter = activeFilter;
      }
    },
    ordering: false,
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

  wikisTable.on('xhr', function(e, settings, json) {
    if (json) { renderSummary(json.totals || {}, json.counts || {}); }
  });

  function renderSummary(totals, counts) {
    var $totals = $('#ferme-totals').empty();
    var measured = totals.measured || 0;
    var partial = measured > 0 && measured < (totals.wikis || 0)
      ? String(i18n.i18nTotalsPartial || '').replace('%{measured}', measured).replace('%{wikis}', totals.wikis)
      : '';

    [
      ['wikis', i18n.totalWikis, true],
      ['entries', i18n.totalEntries, false],
      ['pages', i18n.totalPages, false],
      ['users', i18n.totalUsers, false],
      ['disk', i18n.totalDisk, false]
    ].forEach(function(entry) {
      if (totals[entry[0]] === undefined) { return; }
      var known = entry[2] || measured > 0;
      $totals.append($('<div class="ferme-total">')
        .attr('title', known ? (entry[2] ? '' : partial) : i18n.i18nNeverMeasured)
        .append($('<strong>').text(known ? totals[entry[0]] : '?'))
        .append($('<span>').text(entry[1])));
    });

    var $chips = $('#ferme-chips').empty();
    chips.forEach(function(chip) {
      var count = counts[chip.key] || 0;
      if (count === 0 && activeFilter !== chip.key) { return; }
      $chips.append($('<button type="button">')
        .addClass('btn btn-xs btn-' + chip.kind + ' ferme-chip')
        .toggleClass('active', activeFilter === chip.key)
        .attr('data-filter', chip.key)
        .html('<i class="fas fa-' + chip.icon + '"></i> ' + esc(i18n[chip.label]) + ' <span class="badge">' + count + '</span>'));
    });
  }

  $(document).on('click', '.ferme-chip', function() {
    var wanted = $(this).data('filter');
    activeFilter = activeFilter === wanted ? '' : wanted;
    wikisTable.ajax.reload();
  });

  $('#ferme-sort').on('change', function() {
    wikisTable.ajax.reload();
  });

  $(document).on('click', '.ferme-detail-toggle', function() {
    var row = wikisTable.row($(this).closest('tr'));
    var $icon = $(this).find('i');
    if (row.child.isShown()) {
      row.child.hide();
      $icon.attr('class', 'fas fa-chevron-down');
      return;
    }
    var $detail = $(detail(row.data()));
    row.child($detail).show();
    $icon.attr('class', 'fas fa-chevron-up');
    loadCalendar($detail);
  });

  function loadCalendar($detail) {
    var $slot = $detail.find('.ferme-calendar-slot').addBack('.ferme-calendar-slot');
    if (!$slot.length || !activityUrl || $slot.data('loaded')) { return; }
    $slot.data('loaded', true);

    $.ajax({
      url: activityUrl,
      method: 'POST',
      data: { folder: $slot.data('folder'), 'csrf-token': csrfToken },
      dataType: 'json',
      success: function(response) {
        $slot.html(response && response.success ? response.calendar : esc((response && response.error) || ''));
      },
      error: function(xhr, status, error) {
        $slot.text((xhr.responseJSON && xhr.responseJSON.error) || ('HTTP error: ' + error));
      }
    });
  }

  function detail(row) {
    if (!row.stats) {
      return '<div class="ferme-detail">' + esc(i18n.i18nNeverMeasured) + '</div>';
    }
    var lines = [
      [i18n.totalEntries, row.stats.entries],
      [i18n.totalPages, row.stats.pages],
      [i18n.totalForms, row.stats.forms],
      [i18n.totalUsers, row.stats.users],
      [i18n.i18nFiles, row.stats.files + ' · ' + row.stats.disk_detail],
      [i18n.i18nMeasuredAt, row.stats.computed_at + ' (' + row.stats.computed_age + ')']
    ];
    var html = '<div class="ferme-detail"><dl class="dl-horizontal">';
    lines.forEach(function(line) {
      html += '<dt>' + esc(line[0]) + '</dt><dd>' + esc(String(line[1])) + '</dd>';
    });
    if (row.stats.failed && row.stats.error) {
      html += '<dt>' + esc(i18n.chipFailed) + '</dt><dd><code>' + esc(row.stats.error) + '</code></dd>';
    }
    return html + '</dl><div class="ferme-calendar-slot text-muted" data-folder="' + esc(row.folder) + '">'
      + esc(i18n.i18nLoading) + '</div></div>';
  }

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

  function selectionCount() {
    return Object.keys(selectedWikis).length;
  }

  function selectedList() {
    return Object.keys(selectedWikis).map(function(folder) {
      return {
        folder: folder,
        title: selectedWikis[folder].title,
        idFiche: selectedWikis[folder].idFiche
      };
    });
  }

  function updateBulkBtns() {
    var count = selectionCount();
    $('#btn-bulk-actions').prop('disabled', count === 0);
    if (count > 0) {
      $('#bulk-selected-count').text(count).show();
    } else {
      $('#bulk-selected-count').hide();
      $('#btn-bulk-actions').closest('.btn-group').removeClass('open');
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

  runModes = {
    core: { url: upgradeUrl, icon: 'fas fa-sync-alt', title: i18n.upgradeTitle, intro: i18n.upgradeIntro },
    extensions: { url: upgradeExtensionsUrl, icon: 'fas fa-puzzle-piece', title: i18n.upgradeExtTitle, intro: i18n.upgradeExtIntro },
    recover: { url: recoverCustomUrl, icon: 'fas fa-undo', title: i18n.recoverTitle, intro: i18n.recoverIntro },
    stats: { url: refreshStatsUrl, icon: 'fas fa-chart-column', title: i18n.refreshTitle, intro: i18n.refreshIntro }
  };
  runMode = runModes.core;

  $('#btn-upgrade-selected').on('click', function(event) {
    event.preventDefault();
    openUpgradeModal('core');
  });

  $('#btn-upgrade-extensions-selected').on('click', function(event) {
    event.preventDefault();
    openUpgradeModal('extensions');
  });

  $('#btn-refresh-stats-selected').on('click', function(event) {
    event.preventDefault();
    openUpgradeModal('stats');
  });

  $('#btn-recover-custom-selected').on('click', function(event) {
    event.preventDefault();
    openUpgradeModal('recover');
  });

  function openUpgradeModal(mode) {
    if (selectionCount() === 0) { return; }
    runMode = runModes[mode];
    $('#upgrade-selected-modal-label-text').text(runMode.title);
    $('#upgrade-selected-modal-icon').attr('class', runMode.icon);
    $('#upgrade-selected-intro').text(runMode.intro);
    $('#btn-close-upgrade-modal').prop('disabled', true);
    $('#upgrade-selected-modal').modal('show');
  }

  $('#upgrade-selected-modal').on('shown.bs.modal', function() {
    var wikis = selectedList();

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

  $('#btn-delete-selected').on('click', function(event) {
    event.preventDefault();
    if (selectionCount() === 0) { return; }
    var wikis = selectedList();
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
    var wikis = selectedList();
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

  function deleteSequential(wikis, index, done) {
    done = done || { ok: 0, failed: 0 };

    if (index >= wikis.length) {
      summarise($('#delete-wikis-list'), done.ok, done.failed);
      $('#btn-close-delete-modal').prop('disabled', false);
      wikisTable.ajax.reload(null, false);
      return;
    }

    var wiki = wikis[index];
    var $item = $('#delete-item-' + wiki.folder);
    $item.find('.delete-icon').attr('class', 'fas fa-spinner fa-spin delete-icon text-info');
    $item.find('.delete-badge').text(i18n.deleting).css('background-color', '#5bc0de');

    postWithToken(deleteUrl, { id_fiche: wiki.idFiche }).done(function(response) {
      if (response.success) {
        done.ok++;
        $item.find('.delete-icon').attr('class', 'fas fa-check delete-icon text-success');
        $item.find('.delete-badge').text(i18n.deleteSuccess).css('background-color', '#5cb85c');
        delete selectedWikis[wiki.folder];
        updateBulkBtns();
      } else {
        done.failed++;
        $item.find('.delete-icon').attr('class', 'fas fa-times delete-icon text-danger');
        $item.find('.delete-badge').text(i18n.deleteError).css('background-color', '#d9534f');
        $item.find('.delete-output pre').text(response.error || '');
        $item.find('.delete-output').show();
      }
      deleteSequential(wikis, index + 1, done);
    });
  }

  function adminAjax(folder, action) {
    return postWithToken(action === 'remove' ? adminRemoveUrl : adminAddUrl, { folder: folder });
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

    adminAjax(wiki, action).done(function(response) {
      if (response && response.success) {
        wikisTable.ajax.reload(null, false);

        return;
      }
      $btn.prop('disabled', false).find('.fa-spinner').remove();
      $btn.attr('title', (response && response.error) || i18n.adminError);
    });
  });

  $('#btn-admin-add-selected').on('click', function(event) { event.preventDefault(); openAdminModal('add'); });
  $('#btn-admin-remove-selected').on('click', function(event) { event.preventDefault(); openAdminModal('remove'); });

  function openAdminModal(action) {
    if (selectionCount() === 0) { return; }

    var wikis = selectedList();

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

  function adminSequential(wikis, index, action, done) {
    done = done || { ok: 0, failed: 0 };

    if (index >= wikis.length) {
      summarise($('#admin-wikis-list'), done.ok, done.failed);
      $('#btn-close-admin-modal').prop('disabled', false);
      wikisTable.ajax.reload(null, false);
      return;
    }

    var wiki = wikis[index];
    var $item = $('#admin-item-' + wiki.folder);
    $item.find('.admin-icon').attr('class', 'fas fa-spinner fa-spin admin-icon text-info');
    $item.find('.admin-badge').text(i18n.inProgress).css('background-color', '#5bc0de');

    postWithToken(action === 'remove' ? adminRemoveUrl : adminAddUrl, { folder: wiki.folder }).done(function(response) {
      if (response.success) {
        done.ok++;
        $item.find('.admin-icon').attr('class', 'fas fa-check admin-icon text-success');
        $item.find('.admin-badge')
          .text(action === 'add' ? i18n.adminAdded : i18n.adminRemoved)
          .css('background-color', '#5cb85c');
      } else {
        done.failed++;
        $item.find('.admin-icon').attr('class', 'fas fa-times admin-icon text-danger');
        $item.find('.admin-badge').text(i18n.adminError).css('background-color', '#d9534f');
        $item.find('.admin-output pre').text(response.error || '');
        $item.find('.admin-output').show();
      }
      adminSequential(wikis, index + 1, action, done);
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

  function upgradeSequential(wikis, index, done) {
    done = done || { ok: 0, failed: 0 };

    if (index >= wikis.length) {
      summarise($('#upgrade-wikis-list'), done.ok, done.failed);
      $('#btn-close-upgrade-modal').prop('disabled', false);
      wikisTable.ajax.reload(null, false);
      return;
    }

    var wiki = wikis[index];
    var $item = $('#upgrade-item-' + wiki.folder);

    $item.find('.upgrade-icon').attr('class', 'fas fa-spinner fa-spin upgrade-icon text-info');
    $item.find('.upgrade-badge').text(i18n.inProgress).css('background-color', '#5bc0de');

    postWithToken(runMode.url, { folder: wiki.folder }).done(function(response) {
      var text = [response.output, response.success ? '' : response.error].filter(Boolean).join('\n\n');
      if (text) {
        $item.find('.upgrade-output pre').text(text);
        $item.find('.upgrade-output').show();
      }
      if (response.success) {
        done.ok++;
        $item.find('.upgrade-icon').attr('class', 'fas fa-check upgrade-icon text-success');
        $item.find('.upgrade-badge').text(i18n.success).css('background-color', '#5cb85c');
      } else {
        done.failed++;
        $item.find('.upgrade-icon').attr('class', 'fas fa-times upgrade-icon text-danger');
        $item.find('.upgrade-badge').text(i18n.error).css('background-color', '#d9534f');
      }
      upgradeSequential(wikis, index + 1, done);
    });
  }
});