$(document).ready(function () {
  function htmlEntities(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  var importedData = { forms: {}, lists: {}, entries: {}, pages: {} };

  // import de formes à partir d'un yeswiki
  var $form = $('#yw-import-from-url');
  var $btnimport = $('#btn-import-wiki');
  var $results = $('#wiki-response');
  var $translate = $('#yw-import-from-url-translations').data();

  function buildSection(type, count, itemsHtml, titleKey) {
    return (
      '<div class="import-section" data-type="' + type + '">' +
      '<h5>' + $translate[titleKey] + ' : ' + count + '</h5>' +
      '<div class="checkbox">' +
      '<label>' +
      '<input type="checkbox" class="import-toggle-all" data-type="' + type + '" checked>' +
      '<span><strong>' + $translate.selectall + '</strong></span>' +
      '</label>' +
      '</div>' +
      '<div class="cols3 import-items-' + type + '">' + itemsHtml + '</div>' +
      '</div><hr>'
    );
  }

  function makeCheckbox(type, key, label) {
    return (
      '<div class="checkbox">' +
      '<label>' +
      '<input type="checkbox" class="import-cb import-cb-' + type + '" data-key="' + htmlEntities(String(key)) + '" checked>' +
      '<span>' + htmlEntities(label) + '</span>' +
      '</label>' +
      '</div>'
    );
  }

  $btnimport.click(function (e) {
    e.preventDefault();
    e.stopPropagation();

    // on enleve les anciens contenus
    $results.html('');
    $form.find(':hidden').val('');
    importedData = { forms: {}, lists: {}, entries: {}, pages: {} };

    // url saisie
    var url = $('#url-import').val();

    // expression réguliere pour trouver une url valide
    var rgHttpUrl = new RegExp(
      /((([A-Za-z]{3,9}:(?:\/\/)?)(?:[\-;:&=\+\$,\w]+@)?[A-Za-z0-9\.\-]+|(?:www\.|[\-;:&=\+\$,\w]+@)[A-Za-z0-9\.\-]+)((?:\/[\+~%\/\.\w\-_]*)?\??(?:[\-\+=&;%@\.\w_]*)#?(?:[\.\!\/\\\w]*))?)/,
      'i',
    );

    // on formate l url pour acceder au service json de yeswiki
    url = url.split('/wakka.php');
    url = url[0].split('/index.php');
    url = url[0].split('/?');
    url = url[0].replace(/\/+$/g, '') + '/';

    if (rgHttpUrl.test(url)) {
      // formulaires
      $results.append(
        '<div class="loading alert alert-info">' +
          '<span class="throbber">' +
          $translate.loading +
          '...</span> ' +
          $translate.recuperation +
          ' ' +
          url +
          '</div>',
      );
      $.ajax({
        method: 'GET',
        url: url + '?api/forms',
      })
        .done(function (data) {
          $results.find('.loading').remove();
          importedData.forms = data;
          var count = 0;
          var output = '';
          for (var key in data) {
            if (data.hasOwnProperty(key)) {
              count++;
              output += makeCheckbox('forms', key, data[key].bn_label_nature);
            }
          }
          $results.append(buildSection('forms', count, output, 'nbformsfound'));
        })
        .fail(function () {
          $results.append(
            '<div class="alert alert-danger">' + $translate.noanswers + '.</div>',
          );
        });

      // listes
      $results.append(
        '<div class="loading alert alert-info">' +
          '<span class="throbber">' +
          $translate.loading +
          '...</span> ' +
          $translate.recuperation +
          ' ' +
          url +
          '</div>',
      );
      $.ajax({
        method: 'GET',
        url: url + '?BazaR/json&demand=lists',
      })
        .done(function (data) {
          $results.find('.loading').remove();
          importedData.lists = data;
          var count = 0;
          var output = '';
          for (var key in data) {
            if (data.hasOwnProperty(key)) {
              count++;
              output += makeCheckbox('lists', key, data[key].titre_liste ?? data[key].title);
            }
          }
          $results.append(buildSection('lists', count, output, 'nblistsfound'));
        })
        .fail(function () {
          $results.append(
            '<div class="alert alert-danger">' + $translate.noanswers + '.</div>',
          );
        });

      // fiches
      $results.append(
        '<div class="loading alert alert-info">' +
          '<span class="throbber">' +
          $translate.loading +
          '...</span> ' +
          $translate.recuperation +
          ' ' +
          url +
          '</div>',
      );
      $.ajax({
        method: 'GET',
        url: url + '?api/entries',
      })
        .done(function (data) {
          $results.find('.loading').remove();
          importedData.entries = data;
          var count = 0;
          var output = '';
          for (var key in data) {
            if (data.hasOwnProperty(key)) {
              count++;
              output += makeCheckbox('entries', key, data[key].bf_titre);
            }
          }
          $results.append(buildSection('entries', count, output, 'nbentriesfound'));
        })
        .fail(function () {
          $results.append(
            '<div class="alert alert-danger">' + $translate.noanswers + '.</div>',
          );
        });

      // pages
      $results.append(
        '<div class="loading alert alert-info">' +
          '<span class="throbber">' +
          $translate.loading +
          '...</span> ' +
          $translate.recuperation +
          ' ' +
          url +
          '</div>',
      );
      $.ajax({
        method: 'GET',
        url: url + '?api/pages',
      })
        .done(function (data) {
          $results.find('.loading').remove();
          importedData.pages = data;
          var count = 0;
          var output = '';
          for (var key in data) {
            if (data.hasOwnProperty(key)) {
              count++;
              output += makeCheckbox('pages', key, data[key].tag);
            }
          }
          $results.append(buildSection('pages', count, output, 'nbpagesfound'));
        })
        .fail(function () {
          $results.append(
            '<div class="alert alert-danger">' + $translate.noanswers + '.</div>',
          );
        });
    } else {
      $results.append(
        '<div class="alert alert-danger">' +
          $translate.notvalidurl +
          ' : ' +
          url +
          '</div>',
      );
    }

    $results.prepend(
      '<button type="submit" class="btn btn-lg btn-block btn-primary">' +
        $translate.generatesqlfile +
        '</button>',
    );

    return false;
  });

  // Inline model label editing
  $(document).on('click', '.model-label-edit-btn', function () {
    var $container = $(this).closest('.model-label');
    $container.find('.model-label-text, .model-label-edit-btn').hide();
    $container.find('.model-label-input').show().trigger('focus').select();
  });

  $(document).on('blur keydown', '.model-label-input', function (e) {
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== 'Escape') return;
    var $input = $(this);
    var $container = $input.closest('.model-label');
    if (e.key === 'Escape') {
      $input.val($container.find('.model-label-text').text());
    } else {
      var newLabel = $input.val().trim();
      if (newLabel) {
        $container.find('.model-label-text').text(newLabel);
      } else {
        $input.val($container.find('.model-label-text').text());
      }
    }
    $container.find('.model-label-text, .model-label-edit-btn').show();
    $input.hide();
  });

  // Toggle all checkboxes in a section
  $(document).on('change', '.import-toggle-all', function () {
    var type = $(this).data('type');
    var checked = $(this).prop('checked');
    $('.import-cb-' + type).prop('checked', checked);
  });

  // Sync toggle-all state when individual checkboxes change
  $(document).on('change', '.import-cb', function () {
    var type = $(this).closest('.import-section').data('type');
    var total = $('.import-cb-' + type).length;
    var checkedCount = $('.import-cb-' + type + ':checked').length;
    $('.import-toggle-all[data-type="' + type + '"]').prop('checked', total === checkedCount);
  });

  // On submit, filter data to only include checked items
  $form.on('submit', function () {
    var sections = [
      { fieldId: 'wiki-import-forms', type: 'forms' },
      { fieldId: 'wiki-import-lists', type: 'lists' },
      { fieldId: 'wiki-import-entries', type: 'entries' },
      { fieldId: 'wiki-import-pages', type: 'pages' },
    ];
    sections.forEach(function (section) {
      var filtered = {};
      $('.import-cb-' + section.type + ':checked').each(function () {
        var key = $(this).data('key');
        if (importedData[section.type].hasOwnProperty(key)) {
          filtered[key] = importedData[section.type][key];
        }
      });
      $('#' + section.fieldId).val(htmlEntities(JSON.stringify(filtered)));
    });
  });
});
