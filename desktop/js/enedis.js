
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

document.getElementById('div_pageContainer').addEventListener('click', function(event) {
  let _target = null

  if (_target = event.target.closest('.cmdAction[data-action="addEnedisData"]')) {
    const tr = _target.closest('tr')
    const cmdId = tr.getAttribute('data-cmd_id')
    const title = '{{Ajout de données}} ' + tr.querySelector('.cmdAttr[data-l1key="name"]').value
    const today = new Date()

    if (_target.getAttribute('data-logicalId').includes('daily')) {
      const years = []
      for (let year = today.getFullYear() - 1; year >= today.getFullYear() - 3; year--) {
        years.push({ text: year, value: year + '-01-01' })
      }
      jeeDialog.prompt({
        title: title,
        message: "<p>{{Intégrer les historiques du 1er janvier de l'année choisie jusqu'au 1er janvier}} " + today.getFullYear() + "</p>{{Choisir l'année de début}}",
        inputType: 'select',
        inputOptions: years,
        callback: function(result) {
          addEnedisHistory(cmdId, result)
        }
      })
      return
    }

    let picker = null
    jeeDialog.prompt({
      title: title,
      message: "<p>{{Intégrer les historiques horaires jusqu'à 7 jours après la date choisie}}</p>{{Choisir la date de début}}",
      inputType: 'date',
      onShown: function(_dialog) {
        picker = flatpickr(_dialog.querySelector('.promptAttr'), {
          dateFormat: 'Y-m-d',
          minDate: new Date(today.getFullYear() - 3, today.getMonth(), today.getDate()),
          maxDate: new Date(today.getFullYear(), today.getMonth(), today.getDate() - 7),
          locale: flatpickr.l10ns[jeeFrontEnd.language.substring(0, 2)] || 'default'
        })
      },
      beforeClose: function() {
        picker?.destroy()
      },
      callback: function(result) {
        addEnedisHistory(cmdId, result)
      }
    })
    return
  }
})

function addEnedisHistory(_cmdId, _start) {
  if (_start === null) {
    return
  }
  jeedomUtils.showAlert({ message: "{{En cours d'intégration d'historiques...}}", level: 'warning' })
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/enedis/core/ajax/enedis.ajax.php',
    data: {
      action: 'addEnedisHistory',
      cmd_id: _cmdId,
      start: _start
    },
    dataType: 'json',
    error: function(error) {
      jeedomUtils.showAlert({ message: error.message, level: 'danger' })
    },
    success: function(data) {
      jeedomUtils.hideAlert()
      if (data.state != 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        return
      }
      jeedomUtils.showAlert({ message: '{{Historiques intégrés avec succès}}', level: 'success' })
    }
  })
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  let tr = '<td class="hidden-xs">'
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
  tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label> '
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="unite" placeholder="Unité" title="{{Unité}}" style="width:30%;max-width:80px;margin-top:7px;">'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
    if ((_cmd.logicalId).includes('daily') || (_cmd.logicalId).includes('load')) {
      tr += ' <a class="btn btn-primary btn-xs cmdAction" data-action="addEnedisData" data-logicalId="' + _cmd.logicalId + '"><i class="fas fa-calendar-plus"></i> {{Ajout historiques}}</a>'
    }
  }
  tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer la commande}}"></i>'
  tr += '</td>'

  const newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.addClass('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}
