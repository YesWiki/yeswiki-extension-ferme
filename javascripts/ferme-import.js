$(document).ready(function () {
  function htmlEntities(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // import de formes à partir d'un yeswiki
  var $form = $('#yw-import-from-url');
  var $btnimport = $('#btn-import-wiki');
  var $results = $('#wiki-response');
  var $translate = $('#yw-import-from-url-translations').data();
  $btnimport.click(function (e) {
    e.preventDefault();
    e.stopPropagation();

    // on enleve les anciens contenus
    $results.html('');
    $form.find(':hidden').val('');

    // url saisie
    var url = $('#url-import').val();

    // expression réguliere pour trouver une url valide
    var rgHttpUrl = new RegExp(/((([A-Za-z]{3,9}:(?:\/\/)?)(?:[\-;:&=\+\$,\w]+@)?[A-Za-z0-9\.\-]+|(?:www\.|[\-;:&=\+\$,\w]+@)[A-Za-z0-9\.\-]+)((?:\/[\+~%\/\.\w\-_]*)?\??(?:[\-\+=&;%@\.\w_]*)#?(?:[\.\!\/\\\w]*))?)/, 'i');
   
    // on formate l url pour acceder au service json de yeswiki
    url = url.split('/wakka.php');
    url = url[0].split('/index.php');
    url = url[0].split('/?');
    url = url[0].replace(/\/+$/g, '') + '/';
    
    if (rgHttpUrl.test(url)) {

      // formulaires
      $results.append('<div class="loading alert alert-info">'
        + '<span class="throbber">' + $translate.loading + '...</span> '
        + $translate.recuperation + ' ' + url
        + '</div>');
      $.ajax({
        method: 'GET',
        url: url + '?BazaR/json&demand=forms',
      }).done(function (data) {
        $results.find('.loading').remove();
        var count = 0;
        $('#wiki-import-forms').val(htmlEntities(JSON.stringify(data)));
        var output = '';
        for (var form in data) {
          if (data.hasOwnProperty(form)) {
            count++;
            output += data[form].bn_label_nature + '<br>';
          }
        }

        $results.append('<h5>'
          + $translate.nbformsfound + ' : ' + count + '</h5><div class="cols3">' + output
          + '</div><hr>');
      }).fail(function (jqXHR, textStatus, errorThrown) {
        $results.append('<div class="alert alert-danger">' + $translate.noanswers + '.</div>');
      });

      // listes
      $results.append('<div class="loading alert alert-info">'
        + '<span class="throbber">' + $translate.loading + '...</span> '
        + $translate.recuperation + ' ' + url
        + '</div>');
      $.ajax({
        method: 'GET',
        url: url + '?BazaR/json&demand=lists',
      }).done(function (data) {
        $results.find('.loading').remove();
        var count = 0;
        $('#wiki-import-lists').val(htmlEntities(JSON.stringify(data)));
        var output = '';
        for (var list in data) {
          if (data.hasOwnProperty(list)) {
            count++;
            output += data[list].titre_liste + '<br>';
          }
        }

        $results.append('<h5>'
          + $translate.nblistsfound + ' : ' + count + '</h5><div class="cols3">' + output
          + '</div><hr>');
      }).fail(function (jqXHR, textStatus, errorThrown) {
        $results.append('<div class="alert alert-danger">'
          + $translate.noanswers + '.</div>');
      });

      // fiches
      $results.append('<div class="loading alert alert-info">'
        + '<span class="throbber">' + $translate.loading + '...</span> '
        + $translate.recuperation + ' ' + url
        + '</div>');
      $.ajax({
        method: 'GET',
        url: url + '?BazaR/json&demand=entries',
      }).done(function (data) {
        $results.find('.loading').remove();
        var count = 0;
        $('#wiki-import-entries').val(htmlEntities(JSON.stringify(data)));
        var output = '';
        for (var item in data) {
          if (data.hasOwnProperty(item)) {
            count++;
            output += data[item].bf_titre + '<br>';
          }
        }

        $results.append('<h5>'
          + $translate.nbentriesfound + ' : ' + count + '</h5><div class="cols3">' + output
          + '</div><hr>');
      }).fail(function (jqXHR, textStatus, errorThrown) {
        $results.append('<div class="alert alert-danger">'
          + $translate.noanswers + '.</div>');
      });

      // pages
      $results.append('<div class="loading alert alert-info">'
        + '<span class="throbber">' + $translate.loading + '...</span> '
        + $translate.recuperation + ' ' + url
        + '</div>');
      $.ajax({
        method: 'GET',
        url: url + '?BazaR/json&demand=pages',
      }).done(function (data) {
        $results.find('.loading').remove();
        var count = 0;
        $('#wiki-import-pages').val(htmlEntities(JSON.stringify(data)));
        var output = '';
        for (var page in data) {
          if (data.hasOwnProperty(page)) {
            count++;
            output += data[page].tag + '<br>';
          }
        }

        $results.append('<h5>'
          + $translate.nbpagesfound + ' : ' + count + '</h5><div class="cols3">' + output
          + '</div><hr>');
      }).fail(function (jqXHR, textStatus, errorThrown) {
        $results.append('<div class="alert alert-danger">'
          + $translate.noanswers + '.</div>');
      });
    } else {
      $results.append('<div class="alert alert-danger">'
        + $translate.notvalidurl + ' : ' + url
        + '</div>');
    }

    $results.prepend('<button type="submit" class="btn btn-lg btn-block btn-primary">'
      + $translate.generatesqlfile
      + '</button>');

    return false;
  });
});
