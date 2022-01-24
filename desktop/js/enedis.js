
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

$('.eqLogicAttr[data-l2key=widgetTemplate]').on('change', function() {
  if( $(this).is(':checked')){
    $('#templateParams').show()
  }
  else {
    $('#templateParams').hide()
  }
})

$("#table_cmd").sortable({axis: "y", cursor: "move", items: ".cmd", placeholder: "ui-state-highlight", tolerance: "intersect", forcePlaceholderSize: true})
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = {configuration: {}}
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">'
  tr += '<td class="hidden-xs">'
  tr += '<span class="cmdAttr" data-l1key="id"></span>'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="type" value="info" style="display:none;">'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="subType" value="numeric" style="display:none;">'
  tr += '</td>'
  tr += '<td>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label> '
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="unite" placeholder="Unité" title="{{Unité}}" style="width:30%;max-width:80px;margin-top:7px;">'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> Tester</a>'
    if ((_cmd.logicalId).includes('daily') || (_cmd.logicalId).includes('load')) {
      tr += ' <a class="btn btn-primary btn-xs cmdAction" data-action="addEnedisData" data-logicalId="'+_cmd.logicalId+'"><i class="fas fa-calendar-plus"></i> {{Ajout historiques}}</a>'
    }
  }
  tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer la commande}}"></i></td>'
  tr += '</tr>'
  $('#table_cmd tbody').append(tr)
  var tr = $('#table_cmd tbody tr').last()
  jeedom.eqLogic.builSelectCmd({
    id:  $('.eqLogicAttr[data-l1key=id]').value(),
    filter: {type: 'info'},
    error: function (error) {
      $('#div_alert').showAlert({message: error.message, level: 'danger'})
    },
    success: function (result) {
      tr.find('.cmdAttr[data-l1key=value]').append(result)
      tr.setValues(_cmd, '.cmdAttr')
      jeedom.cmd.changeType(tr, init(_cmd.subType))
    }
  })

  $('.cmdAction[data-action=addEnedisData]').off().on('click', function() {
    var tr = $(this).closest('tr')
    var d = new Date()
    var min = (d.getFullYear()-3)+'-'+("0" + (d.getMonth() + 1)).slice(-2)+'-'+("0" + d.getDate()).slice(-2)
    if ($(this).attr('data-logicalId').includes('daily')) {
      var message = '<p>{{Intégrer les historiques de la date choisie jusqu\'au 1er janvier}} '+d.getFullYear()+'</p>'
      var max = d.getFullYear()+'-01-01'
    }
    else {
      var message = '<p>{{Intégrer les historiques horaires jusqu\'à 7 jours après la date choisie}}</p>'
      var loadDate = d.getDate() - 7
      d.setDate(loadDate)
      var max = d.getFullYear()+'-'+("0" + (d.getMonth() + 1)).slice(-2)+'-'+("0" + d.getDate()).slice(-2)
    }
    message += '{{Choisir la date de début}}'
    bootbox.prompt({
      title: '{{Ajout de données}} '+tr.find('.cmdAttr[data-l1key=name]').value(),
      message: message,
      inputType: 'date',
      min: min,
      max: max,
      size: 'small',
      callback: function (result) {
        if (result) {
          $('#div_alert').showAlert({message: '{{En cours d\'intégration d\'historiques...}}', level: 'warning'})
          $.ajax({
            type: "POST",
            url: "plugins/enedis/core/ajax/enedis.ajax.php",
            data: {
              action: "addEnedisHistory",
              cmd_id: tr.attr('data-cmd_id'),
              start: result,
            },
            dataType: 'json',
            error: function (request, status, error) {
              handleAjaxError(request, status, error, $('#div_alert'))
            },
            success: function (data) {
              $('#div_alert').showAlert({message: '{{Historiques intégrés avec succès}}', level: 'success'})
            }
          })
        }
      }
    })
  })

}
