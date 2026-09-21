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
  var mailUrl = $config.data('mail-url');
  var importUrl = $config.data('import-url');
  var deleteUrl = $config.data('delete-url');
  var searchUrl = $config.data('search-url');
  var archiveUrl = $config.data('archive-url');
  var selectUrl = $config.data('select-url');
  var cleanSpamUrl = $config.data('clean-spam-url');
  var hibernateUrl = $config.data('hibernate-url');
  var wakeUrl = $config.data('wake-url');
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
    del: $table.data('i18n-delete')
  };

  var DELETE_CHUNK = 5;
  var DELETE_PARALLEL = 5;
  var selectedWikis = {};
  var filteredCount = 0;
  var activeFilter = '';
  var chips = [
    { key: 'toUpdate', label: 'chipToUpdate', kind: 'danger', icon: 'sync-alt' },
    { key: 'dormant', label: 'chipDormant', kind: 'default', icon: 'moon' },
    { key: 'heavyArchives', label: 'chipHeavyArchives', kind: 'warning', icon: 'archive' },
    { key: 'suspect', label: 'chipSuspect', kind: 'danger', icon: 'ban' },
    { key: 'failed', label: 'chipFailed', kind: 'danger', icon: 'exclamation-triangle' },
    { key: 'unmeasured', label: 'chipUnmeasured', kind: 'default', icon: 'question' },
    { key: 'hibernating', label: 'chipHibernating', kind: 'default', icon: 'moon' },
    { key: 'spammed', label: 'chipSpammed', kind: 'warning', icon: 'link' }
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

  /**
   * Keeps the wiki being worked on in sight, and says where the run is in the
   * title, because a batch of two hundred otherwise scrolls away from the reader.
   */
  function follow(modal, listSelector, $item, index, total) {
    $(modal).find('.ferme-progress').text(total > 0 ? (index + 1) + ' / ' + total : '');

    var $list = $(listSelector);
    if (!$list.length || !$item.length || !$item[0]) { return; }

    var haut = $item[0].offsetTop - $list[0].offsetTop;
    $list.stop(true).animate({ scrollTop: haut - ($list.height() - $item.outerHeight()) / 2 }, 150);
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
    return badge('success', label);
  }

  function adminBadge(row) {
    if (!row.admin) { return ''; }
    return row.admin.present
      ? badge('success', row.admin.name, i18n.i18nAdminPresent)
      : badge('warning', i18n.i18nAdminNone, row.admin.name + ' ' + i18n.i18nAdminAbsent);
  }

  function statusBadge(row) {
    if (!row.status || !row.status.asleep) { return ''; }
    return '<span class="label label-default" title="' + esc(row.status.value) + '">'
      + '<i class="fas fa-moon"></i> ' + esc(row.status.label) + '</span>';
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
          + ' data-id-fiche="' + esc(row.id_fiche) + '"'
          + ' data-mail="' + esc(row.mail) + '">'
          + '<span></span></label></div>';
      }
    },
    {
      data: null,
      defaultContent: '',
      orderable: false,
      render: function(data, type, row) {
        var html = '<div class="ferme-identity">'
          + '<strong><a href="' + esc(row.url) + '">' + esc(row.title) + '</a>'
          + (row.url
            ? ' <a class="ferme-open-wiki" href="' + esc(row.url) + '" target="_blank" rel="noopener"'
              + ' title="' + esc(i18n.i18nOpenWiki) + '"><i class="fas fa-external-link-alt"></i></a>'
            : '')
          + '</strong>'
          + '<small>' + esc(row.referent || '')
          + (row.mail ? ' · <a href="mailto:' + esc(row.mail) + '">' + esc(row.mail) + '</a>' : '')
          + '</small>'
          + '<small>' + versionBadge(row) + ' ' + adminBadge(row) + ' ' + statusBadge(row) + '</small>'
          + '</div>';
        if (row.stats && row.stats.spammed) {
          html +=
            '<div><span class="label label-warning" title="' +
            esc(row.stats.spam_hosts) +
            '"><i class="fas fa-link"></i> ' +
            esc(i18n.chipSpammed) +
            '</span></div>';
        }
        if (row.stats && row.stats.suspect) {
          html += '<div><span class="label label-danger" title="' + esc((row.stats.suspect_why || []).join(', ')) + '">'
            + '<i class="fas fa-ban"></i> ' + esc(i18n.chipSuspect) + '</span></div>';
        }
        (row.problems || []).forEach(function(problem) {
          html += '<div><span class="label label-danger"><i class="fas fa-exclamation-triangle"></i> '
            + esc(problem.label) + '</span></div>';
        });
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
          + figure('address-card', row.stats.entries, i18n.totalEntries)
          + figure('file-alt', row.stats.pages, i18n.totalPages)
          + figure('clipboard-list', row.stats.forms, i18n.totalForms)
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
        if (row.delete_url) {
          items += '<li role="separator" class="divider"></li>'
            + '<li><a href="' + esc(row.delete_url) + '" class="text-danger" target="_blank" rel="noopener">'
            + '<i class="fa fa-trash fa-fw"></i> ' + esc(tableI18n.del) + '</a></li>';
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
    if (!json) { return; }
    renderSummary(json.totals || {}, json.counts || {});
    filteredCount = json.recordsFiltered || 0;
    updateSelectEverything();
  });

  function renderSummary(totals, counts) {
    var $totals = $('#ferme-totals').empty();
    var measured = totals.measured || 0;
    var partial = measured > 0 && measured < (totals.wikis || 0)
      ? String(i18n.i18nTotalsPartial || '').replace('%{measured}', measured).replace('%{wikis}', totals.wikis)
      : '';

    [
      ['wikis', i18n.totalWikis, true],
      ['running', i18n.i18nTotalRunning, true],
      ['hibernating', i18n.i18nTotalHibernating, true],
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

  function toggleDetail($tr) {
    var row = wikisTable.row($tr);
    if (!row || !row.data()) { return; }

    var $icon = $tr.find('.ferme-detail-toggle i');
    if (row.child.isShown()) {
      row.child.hide();
      $tr.removeClass('ferme-open');
      $icon.attr('class', 'fas fa-chevron-down');

      return;
    }

    var $detail = detail(row.data());
    row.child($detail, 'ferme-child').show();
    $tr.addClass('ferme-open');
    $icon.attr('class', 'fas fa-chevron-up');
    loadCalendar($detail);
  }

  // anywhere in the row opens it, except what is already something to click
  $(document).on('click', '#wikis-table tbody tr', function(event) {
    if ($(event.target).closest('a, button, input, label, .dropdown-menu, svg').length) { return; }
    toggleDetail($(this));
  });

  $(document).on('click', '.ferme-detail-toggle', function(event) {
    event.preventDefault();
    event.stopPropagation();
    toggleDetail($(this).closest('tr'));
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
        if (!response || !response.success) {
          $slot.text((response && response.error) || '');

          return;
        }
        $slot.empty()
          .append($('<div class="ferme-detail-title">').text(response.title || ''))
          .append(response.calendar);
      },
      error: function(xhr, status, error) {
        $slot.text((xhr.responseJSON && xhr.responseJSON.error) || ('HTTP error: ' + error));
      }
    });
  }

  function statusFigure(row) {
    return [
      row.status && row.status.asleep ? 'moon' : 'circle-notch',
      i18n.i18nStatus,
      (row.status && row.status.label) || ''
    ];
  }

  function detail(row) {
    if (!row.stats) {
      return $('<div class="ferme-detail">')
        .append(
          $('<div class="ferme-detail-cell">')
            .append($('<span class="ferme-muted">').text(i18n.i18nStatus))
            .append(' ')
            .append($('<strong>').text((row.status && row.status.label) || ''))
        )
        .append($('<div class="ferme-muted ferme-detail-note">').text(i18n.i18nNeverMeasured));
    }

    var figures = [
      ['address-card', i18n.totalEntries, row.stats.entries],
      ['file-alt', i18n.totalPages, row.stats.pages],
      ['clipboard-list', i18n.totalForms, row.stats.forms],
      ['user', i18n.totalUsers, row.stats.users],
      ['paperclip', i18n.i18nFiles, row.stats.files + ' · ' + row.stats.disk],
      ['clock', i18n.i18nMeasuredAt, row.stats.computed_age],
      statusFigure(row),
      ['link', i18n.i18nSpamWords, row.stats.spam_words + ' · ' + row.stats.spam_links + ' ' + i18n.i18nSpamLinks]
    ];

    var html = '<div class="ferme-detail"><div class="ferme-detail-grid">';
    figures.forEach(function(item) {
      html += '<div class="ferme-detail-cell"><i class="fas fa-' + item[0] + ' fa-fw"></i> '
        + '<span class="ferme-muted">' + esc(item[1]) + '</span> <strong>' + esc(String(item[2])) + '</strong></div>';
    });
    html += '</div>';
    if (row.stats.spam_hosts) {
      html += '<div class="ferme-muted ferme-detail-note">' + esc(row.stats.spam_hosts) + '</div>';
    }
    html += '<div class="ferme-muted ferme-detail-note">' + esc(row.stats.disk_detail)
      + ' · ' + esc(i18n.i18nMeasuredAt) + ' ' + esc(row.stats.computed_at || '') + '</div>';

    if (row.stats.failed && row.stats.error) {
      html += '<div class="alert alert-danger" style="margin:8px 0 0;"><code>' + esc(row.stats.error) + '</code></div>';
    }

    html += '<div class="ferme-calendar-slot" data-folder="' + esc(row.folder) + '">'
      + '<span class="ferme-muted">' + esc(i18n.i18nLoading) + '</span></div>';

    return $(html + '</div>');
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
        idFiche: selectedWikis[folder].idFiche,
        mail: selectedWikis[folder].mail
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
    $('#btn-select-none').toggle(count > 0);
  }

  /**
   * The page shows a hundred wikis at a time; the button says how many the current
   * search and chip actually hold, and takes them all.
   */
  function updateSelectEverything() {
    $('#select-everything-label').text(
      filteredCount > 0 ? i18n.i18nSelectAllN.replace('%{n}', filteredCount) : i18n.i18nSelectAll
    );
    $('#btn-select-everything').prop('disabled', filteredCount === 0);
  }

  $('#btn-select-everything').on('click', function() {
    var $button = $(this).prop('disabled', true);
    var was = $('#select-everything-label').text();
    $('#select-everything-label').text(i18n.i18nSelecting);

    postWithToken(selectUrl, { search: currentSearch(), filter: activeFilter }).done(function(response) {
      (response.wikis || []).forEach(function(wiki) {
        selectedWikis[wiki.folder] = { title: wiki.title, idFiche: wiki.id_fiche, mail: wiki.mail };
      });
      $table.find('.wiki-checkbox').prop('checked', true);
      updateSelectAllState();
      updateBulkBtns();
      $('#select-everything-label').text(was);
      $button.prop('disabled', false);
    });
  });

  $('#btn-select-none').on('click', function() {
    selectedWikis = {};
    $table.find('.wiki-checkbox').prop('checked', false);
    updateSelectAllState();
    updateBulkBtns();
  });

  function currentSearch() {
    return String(wikisTable.search() || '');
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
        selectedWikis[folder] = { title: title, idFiche: idFiche, mail: $(this).data('mail') };
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
      selectedWikis[folder] = { title: title, idFiche: idFiche, mail: $(this).data('mail') };
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
    stats: { url: refreshStatsUrl, icon: 'fas fa-chart-bar', title: i18n.refreshTitle, intro: i18n.refreshIntro },
    archive: { url: archiveUrl, icon: 'fas fa-file-archive', title: i18n.archiveTitle, intro: i18n.archiveIntro },
    cleanDry: { url: cleanSpamUrl, icon: 'fas fa-broom', title: i18n.cleanDryTitle, intro: i18n.cleanDryIntro, data: { dry: '1' } },
    clean: { url: cleanSpamUrl, icon: 'fas fa-broom', title: i18n.cleanTitle, intro: i18n.cleanIntro },
    hibernate: { url: hibernateUrl, icon: 'fas fa-moon', title: i18n.hibernateTitle, intro: i18n.hibernateIntro, batch: 10 },
    wake: { url: wakeUrl, icon: 'fas fa-sun', title: i18n.wakeTitle, intro: i18n.wakeIntro, batch: 10 }
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

  $('#btn-archive-selected').on('click', function(event) {
    event.preventDefault();
    openUpgradeModal('archive');
  });

  $('#btn-clean-spam-dry').on('click', function(event) {
    event.preventDefault();
    openUpgradeModal('cleanDry');
  });

  $('#btn-clean-spam').on('click', function(event) {
    event.preventDefault();
    openUpgradeModal('clean');
  });

  $('#btn-hibernate-selected').on('click', function(event) {
    event.preventDefault();
    openUpgradeModal('hibernate');
  });

  $('#btn-wake-selected').on('click', function(event) {
    event.preventDefault();
    openUpgradeModal('wake');
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

  $('#btn-mail-selected').on('click', function(event) {
    event.preventDefault();
    if (selectionCount() === 0) { return; }

    var wikis = selectedList();
    var without = wikis.filter(function(wiki) { return !wiki.mail; }).length;

    $('#mail-recipients').text(
      wikis.length + ' ' + esc(i18n.totalWikis)
      + (without > 0 ? ' · ' + without + ' ' + i18n.i18nMailNoAddress : '')
    );
    $('#mail-compose-stage').show();
    $('#mail-progress-stage').hide();
    $('#mail-footer-compose').show();
    $('#mail-footer-progress').hide();
    $('#btn-close-mail-modal').prop('disabled', true);
    $('#mail-selected-modal').modal('show');
  });

  $('#btn-start-mail').on('click', function() {
    var subject = $.trim($('#mail-subject').val());
    var body = $.trim($('#mail-body').val());
    if (!subject || !body) {
      $('#mail-subject, #mail-body').closest('.form-group').addClass('has-error');

      return;
    }
    $('#mail-subject, #mail-body').closest('.form-group').removeClass('has-error');

    var wikis = selectedList();
    var $list = $('#mail-wikis-list').empty();
    wikis.forEach(function(wiki) {
      var $item = $('<div class="list-group-item">').attr('id', 'mail-item-' + wiki.folder);
      var $header = $('<div>').css('display', 'flex').css('align-items', 'center').css('gap', '8px');
      $header.append($('<i class="fas fa-clock mail-icon text-muted">'));
      $header.append($('<strong class="flex-grow-1">').text(wiki.title || wiki.folder));
      $header.append($('<span class="badge mail-badge">').text(i18n.pending).css('margin-left', 'auto'));
      $item.append($header);
      $item.append(
        $('<div class="mail-output" style="display:none; margin-top:8px;">')
          .append($('<pre style="max-height:100px; overflow-y:auto; margin:0;">'))
      );
      $list.append($item);
    });

    $('#mail-compose-stage').hide();
    $('#mail-footer-compose').hide();
    $('#mail-progress-stage').show();
    $('#mail-footer-progress').show();
    mailSequential(wikis, 0, { subject: subject, body: body });
  });

  function mailSequential(wikis, index, letter, done) {
    done = done || { ok: 0, failed: 0 };

    if (index >= wikis.length) {
      summarise($('#mail-wikis-list'), done.ok, done.failed);
      $('#mail-selected-modal').find('.ferme-progress').text('');
      $('#btn-close-mail-modal').prop('disabled', false);

      return;
    }

    var wiki = wikis[index];
    var $item = $('#mail-item-' + wiki.folder);
    follow('#mail-selected-modal', '#mail-wikis-list', $item, index, wikis.length);

    $item.find('.mail-icon').attr('class', 'fas fa-spinner fa-spin mail-icon text-info');
    $item.find('.mail-badge').text(i18n.i18nMailSending).css('background-color', '#5bc0de');

    postWithToken(mailUrl, { id_fiche: wiki.idFiche, subject: letter.subject, body: letter.body })
      .done(function(response) {
        if (response.success) {
          done.ok++;
          $item.find('.mail-icon').attr('class', 'fas fa-check mail-icon text-success');
          $item.find('.mail-badge').text(i18n.i18nMailSent).css('background-color', '#5cb85c');
        } else {
          done.failed++;
          $item.find('.mail-icon').attr('class', 'fas fa-times mail-icon text-danger');
          $item.find('.mail-badge').text(i18n.error).css('background-color', '#d9534f');
        }
        if (response.output || response.error) {
          $item.find('.mail-output pre').text(response.output || response.error);
          $item.find('.mail-output').show();
        }
        mailSequential(wikis, index + 1, letter, done);
      });
  }

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
    deleteAll(wikis);
  });

  function deleteAll(wikis) {
    var chunks = [];
    for (var i = 0; i < wikis.length; i += DELETE_CHUNK) {
      chunks.push(wikis.slice(i, i + DELETE_CHUNK));
    }

    var done = { ok: 0, failed: 0 };
    var next = 0;
    var running = 0;

    function pump() {
      while (running < DELETE_PARALLEL && next < chunks.length) {
        send(chunks[next]);
        next++;
      }
      if (running === 0 && next >= chunks.length) {
        summarise($('#delete-wikis-list'), done.ok, done.failed);
        $('#delete-selected-modal').find('.ferme-progress').text('');
        $('#btn-close-delete-modal').prop('disabled', false);
        wikisTable.ajax.reload(null, false);
      }
    }

    function send(chunk) {
      running++;
      var byIdFiche = {};

      chunk.forEach(function (wiki) {
        byIdFiche[wiki.idFiche] = wiki;
        var $item = $('#delete-item-' + wiki.folder);
        $item.find('.delete-icon').attr('class', 'fas fa-spinner fa-spin delete-icon text-info');
        $item.find('.delete-badge').text(i18n.deleting).css('background-color', '#5bc0de');
      });

      postWithToken(deleteUrl, {
        id_fiches: chunk.map(function (wiki) {
          return wiki.idFiche;
        })
      }).done(function (response) {
        var results = (response && response.results) || [];
        var seen = {};

        results.forEach(function (result) {
          var wiki = byIdFiche[result.id_fiche];
          if (!wiki) {
            return;
          }
          seen[result.id_fiche] = true;
          showDeleted(wiki, result, done);
        });

        chunk.forEach(function (wiki) {
          if (!seen[wiki.idFiche]) {
            showDeleted(wiki, { success: false, error: (response && response.error) || i18n.deleteError }, done);
          }
        });

        running--;
        follow('#delete-selected-modal', '#delete-wikis-list', firstPending(wikis), done.ok + done.failed, wikis.length);
        pump();
      });
    }

    follow('#delete-selected-modal', '#delete-wikis-list', $('#delete-item-' + wikis[0].folder), 0, wikis.length);
    pump();
  }

  function firstPending(wikis) {
    for (var i = 0; i < wikis.length; i++) {
      var $item = $('#delete-item-' + wikis[i].folder);
      if ($item.find('.fa-spinner, .fa-clock').length) {
        return $item;
      }
    }

    return $('#delete-item-' + wikis[wikis.length - 1].folder);
  }

  function showDeleted(wiki, result, done) {
    var $item = $('#delete-item-' + wiki.folder);

    if (result.success) {
      done.ok++;
      $item.find('.delete-icon').attr('class', 'fas fa-check delete-icon text-success');
      $item.find('.delete-badge').text(i18n.deleteSuccess).css('background-color', '#5cb85c');
      delete selectedWikis[wiki.folder];
      updateBulkBtns();
    } else {
      done.failed++;
      $item.find('.delete-icon').attr('class', 'fas fa-times delete-icon text-danger');
      $item.find('.delete-badge').text(i18n.deleteError).css('background-color', '#d9534f');
    }

    var output = result.success ? result.output : result.error;
    if (output) {
      $item.find('.delete-output pre').text(output);
      $item.find('.delete-output').show();
    }
  }

  /**
   * Some of these are one line written in a wiki's configuration; sending them one
   * request at a time costs far more than doing them. Those modes go by the handful.
   */
  function upgradeBatch(wikis, index, done) {
    var chunk = wikis.slice(index, index + runMode.batch);
    var byFolder = {};

    chunk.forEach(function(wiki) {
      byFolder[wiki.folder] = wiki;
      var $item = $('#upgrade-item-' + wiki.folder);
      $item.find('.upgrade-icon').attr('class', 'fas fa-spinner fa-spin upgrade-icon text-info');
      $item.find('.upgrade-badge').text(i18n.inProgress).css('background-color', '#5bc0de');
    });
    follow('#upgrade-selected-modal', '#upgrade-wikis-list', $('#upgrade-item-' + chunk[0].folder), index, wikis.length);

    postWithToken(runMode.url, {
      folders: chunk.map(function(wiki) { return wiki.folder; })
    }).done(function(response) {
      var seen = {};
      (response.results || []).forEach(function(result) {
        if (!byFolder[result.folder]) { return; }
        seen[result.folder] = true;
        showRun(byFolder[result.folder], result, done);
      });
      chunk.forEach(function(wiki) {
        if (!seen[wiki.folder]) {
          showRun(wiki, { success: false, error: (response && response.error) || i18n.error }, done);
        }
      });

      upgradeSequential(wikis, index + chunk.length, done);
    });
  }

  function showRun(wiki, result, done) {
    var $item = $('#upgrade-item-' + wiki.folder);
    var text = [result.output, result.success ? '' : result.error].filter(Boolean).join('\n\n');
    if (text) {
      $item.find('.upgrade-output pre').text(text);
      $item.find('.upgrade-output').show();
    }
    if (result.success) {
      done.ok++;
      $item.find('.upgrade-icon').attr('class', 'fas fa-check upgrade-icon text-success');
      $item.find('.upgrade-badge').text(i18n.success).css('background-color', '#5cb85c');
    } else {
      done.failed++;
      $item.find('.upgrade-icon').attr('class', 'fas fa-times upgrade-icon text-danger');
      $item.find('.upgrade-badge').text(i18n.error).css('background-color', '#d9534f');
    }
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
      $('#admin-selected-modal').find('.ferme-progress').text('');
      $('#btn-close-admin-modal').prop('disabled', false);
      wikisTable.ajax.reload(null, false);
      return;
    }

    var wiki = wikis[index];
    var $item = $('#admin-item-' + wiki.folder);
    follow('#admin-selected-modal', '#admin-wikis-list', $item, index, wikis.length);

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
    postWithToken(searchUrl, {}).done(function(response) {
      $('#search-loading-stage').hide();
      $('#search-results-stage').show();
      $('#btn-close-search-modal').prop('disabled', false);

      if (!response || response.success === false) {
        $('#search-summary').removeClass('alert-info alert-success').addClass('alert-danger')
          .text((response && response.error) || i18n.searchNetworkError);

        return;
      }

      $('#search-summary').removeClass('alert-danger alert-success').addClass('alert-info').text(
        response.wikisOnServer + ' wiki(s) sur le serveur, '
        + response.wikisInBazar + ' dans la ferme, '
        + response.missing + ' sans fiche.'
      );

      var $list = $('#search-wikis-list').empty();
      (response.results || []).forEach(function(wiki) {
        var $item = $('<label class="list-group-item">').css({ display: 'flex', 'align-items': 'center', gap: '8px', 'font-weight': 'normal' });
        $item.append($('<input type="checkbox" class="search-checkbox">').val(wiki.folder));

        var $name = $('<span>').css('flex-grow', 1);
        $name.append(wiki.url
          ? $('<a>').attr({ href: wiki.url, target: '_blank' }).text(wiki.folder)
          : $('<strong>').text(wiki.folder));
        $item.append($name);

        if (wiki.pages === null) {
          $item.append($('<span class="ferme-muted">').text(i18n.i18nNeverEdited));
        } else {
          $item.append($('<span class="ferme-figures">').html(
            figure('file-alt', wiki.pages, i18n.totalPages)
            + figure('address-card', wiki.entries, i18n.totalEntries)
            + figure('user', wiki.users, i18n.totalUsers)
          ));
          $item.append($('<small class="ferme-muted">').text(wiki.lastActivity ? wiki.lastActivity.slice(0, 10) : ''));
        }
        $list.append($item);
      });

      updateImportButton();
    });
  });

  function updateImportButton() {
    var picked = $('#search-wikis-list .search-checkbox:checked').length;
    $('#btn-import-selected').prop('disabled', picked === 0);
    $('#import-selected-label').text(picked === 0
      ? i18n.i18nImportNone
      : String(i18n.i18nImportSelected).replace('%{n}', picked));
  }

  $(document).on('change', '.search-checkbox', updateImportButton);

  $('#search-select-all').on('change', function() {
    $('#search-wikis-list .search-checkbox').prop('checked', $(this).is(':checked'));
    updateImportButton();
  });

  $('#btn-import-selected').on('click', function() {
    var folders = $('#search-wikis-list .search-checkbox:checked').map(function() { return this.value; }).get();
    if (!folders.length) { return; }

    var $button = $(this).prop('disabled', true);
    $('#import-selected-label').text(i18n.inProgress);

    postWithToken(importUrl, { folders: folders }).done(function(response) {
      $('#search-summary')
        .removeClass('alert-info alert-danger')
        .addClass(response.success ? 'alert-success' : 'alert-danger')
        .text(response.success ? response.output : (response.error || ''));
      $('#search-wikis-list').empty();
      $('#search-select-all').prop('checked', false);
      $button.prop('disabled', true);
      $('#import-selected-label').text(i18n.i18nImportNone);
      wikisTable.ajax.reload(null, false);
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

    if (runMode.batch && index < wikis.length) {
      upgradeBatch(wikis, index, done);
      return;
    }

    if (index >= wikis.length) {
      summarise($('#upgrade-wikis-list'), done.ok, done.failed);
      $('#upgrade-selected-modal').find('.ferme-progress').text('');
      $('#btn-close-upgrade-modal').prop('disabled', false);
      wikisTable.ajax.reload(null, false);
      return;
    }

    var wiki = wikis[index];
    var $item = $('#upgrade-item-' + wiki.folder);
    follow('#upgrade-selected-modal', '#upgrade-wikis-list', $item, index, wikis.length);

    $item.find('.upgrade-icon').attr('class', 'fas fa-spinner fa-spin upgrade-icon text-info');
    $item.find('.upgrade-badge').text(i18n.inProgress).css('background-color', '#5bc0de');

    postWithToken(runMode.url, $.extend({ folder: wiki.folder }, runMode.data || {})).done(function(response) {
      var text = [response.output, response.success ? '' : response.error].filter(Boolean).join('\n\n');
      if (text) {
        $item.find('.upgrade-output pre').text(text);
        $item.find('.upgrade-output').show();
      }
      if (response.download_url) {
        $item.find('.upgrade-output').append(
          $('<a class="btn btn-default btn-xs" style="margin-top:6px;">')
            .attr('href', response.download_url)
            .html('<i class="fas fa-download"></i> ' + esc(i18n.i18nDownload) + ' — ' + esc(response.size || ''))
        ).show();
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