<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function enedis_install() {
  foreach (eqLogic::byType('enedis') as $eqLogic) {
    $crons = cron::searchClassAndFunction('enedis', 'pull', '"enedis_id":' . intval($eqLogic->getId()));
    if ($eqLogic->getIsEnable() == 1 && empty($crons)) {
      $eqLogic->refreshData();
    }
  }
}

function enedis_update() {
  foreach (eqLogic::byType('enedis') as $eqLogic) {
    $crons = cron::searchClassAndFunction('enedis', 'pull', '"enedis_id":' . intval($eqLogic->getId()));
    if ($eqLogic->getIsEnable() == 1 && empty($crons)) {
      $eqLogic->refreshData();
    }
    if ($eqLogic->getConfiguration('widgetBGColor') != '') {
      if ($eqLogic->getConfiguration('widgetTemplate') == 1) {
        $params = $eqLogic->getdisplay('parameters', array());
        if ($eqLogic->getConfiguration('widgetTransparent') == 1) {
          $params['style'] = 'background-color:transparent!important;';
        } else {
          $params['style'] = 'background-color:rgb(' . implode(',', hex2rgb($eqLogic->getConfiguration('widgetBGColor'))) . ')!important;';
        }
        $eqLogic->setDisplay('parameters', $params);
      }
      $eqLogic->setConfiguration('widgetBGColor', null);
      $eqLogic->setConfiguration('widgetTransparent', null);
      $eqLogic->save(true);
    }
    if (is_object($prodMaxPower = $eqLogic->getCmd('info', 'daily_production_max_power'))) {
      $prodMaxPower->remove();
    }
  }
}
