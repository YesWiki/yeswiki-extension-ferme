$(document).ready(function () {
  var $config = $('#upgrade-wikis-config');
  var upgradeUrl = $config.data('upgrade-url');
  var i18n = $config.data();

  // Initialise DataTables with pagination (100 rows per page).
  // We use prevent-auto-init on the table so the global YesWiki init is skipped.
  var dtOptions = typeof DATATABLE_OPTIONS !== 'undefined'
    ? $.extend({}, DATATABLE_OPTIONS, { paging: true, pageLength: 100 })
    : { paging: true, pageLength: 100 };
  var wikisTable = $('#wikis-table').DataTable(dtOptions);

  function updateSelectAllState() {
    // Count across ALL rows (all pages) — hidden rows are still in the DOM.
    var total = $('#wikis-table .wiki-checkbox').length;
    var checked = $('#wikis-table .wiki-checkbox:checked').length;
    $('#select-all-wikis')
      .prop('indeterminate', checked > 0 && checked < total)
      .prop('checked', total > 0 && checked === total);
  }

  function updateUpgradeBtn() {
    var count = $('#wikis-table .wiki-checkbox:checked').length;
    var $btn = $('#btn-upgrade-selected');
    var $badge = $('#upgrade-selected-count');
    $btn.prop('disabled', count === 0);
    if (count > 0) {
      $badge.text(count).show();
    } else {
      $badge.hide();
    }
  }

  // Re-sync select-all indicator when the page changes.
  wikisTable.on('draw', function () {
    updateSelectAllState();
  });

  $('#select-all-wikis').on('change', function () {
    // Select/deselect ALL rows across all pages.
    $('#wikis-table .wiki-checkbox').prop('checked', $(this).is(':checked'));
    updateUpgradeBtn();
  });

  // Individual checkboxes — delegated on document for DataTables re-render safety
  $(document).on('change', '#wikis-table .wiki-checkbox', function () {
    updateSelectAllState();
    updateUpgradeBtn();
  });

  // Open modal programmatically — Bootstrap 3 ignores data-toggle on disabled buttons
  $('#btn-upgrade-selected').on('click', function () {
    if (!$(this).prop('disabled')) {
      $('#upgrade-selected-modal').modal('show');
    }
  });

  // Populate list and start upgrades immediately once the modal is visible
  $('#upgrade-selected-modal').on('shown.bs.modal', function () {
    var wikis = [];
    $('#wikis-table .wiki-checkbox:checked').each(function () {
      wikis.push({ folder: $(this).val(), title: $(this).data('title') });
    });

    var $list = $('#upgrade-wikis-list').empty();
    wikis.forEach(function (wiki) {
      var $item = $('<div class="list-group-item">').attr('id', 'upgrade-item-' + wiki.folder);
      var $header = $('<div>').css('display', 'flex').css('align-items', 'center').css('gap', '8px');
      $header.append($('<i class="fas fa-clock upgrade-icon text-muted">'));
      $header.append($('<strong class="upgrade-wiki-name flex-grow-1">').text(wiki.title));
      $header.append($('<span class="badge upgrade-badge">').text(i18n.pending).css('margin-left', 'auto'));
      $item.append($header);
      $item.append(
        $('<div class="upgrade-output" style="display:none; margin-top:8px;">')
          .append($('<pre style="max-height:200px; overflow-y:auto; margin:0;">')),
      );
      $list.append($item);
    });

    upgradeSequential(wikis, 0);
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
      data: { wiki: wiki.folder },
      dataType: 'json',
      success: function (response) {
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
      error: function (xhr, status, error) {
        $item.find('.upgrade-icon').attr('class', 'fas fa-times upgrade-icon text-danger');
        $item.find('.upgrade-badge').text(i18n.error).css('background-color', '#d9534f');
        $item.find('.upgrade-output pre').text('HTTP error: ' + error);
        $item.find('.upgrade-output').show();
        $('#btn-close-upgrade-modal').prop('disabled', false);
      },
    });
  }
});
