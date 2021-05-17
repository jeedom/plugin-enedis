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
  $eqLogics = eqLogic::byType('enedis');
  foreach ($eqLogics as $eqLogic) {
    $crons = cron::searchClassAndFunction('enedis', 'pull', '"enedis_id":' . intval($eqLogic->getId()));
    if ($eqLogic->getIsEnable() == 1 && empty($crons)) {
      $eqLogic->refreshData();
    }
  }
}

function enedis_update() {
  $cronMinute = config::byKey('cronMinute', 'enedis');
  if (!empty($cronMinute)) {
    config::remove('cronMinute', 'enedis');
  }
  if (is_dir('/var/www/html/plugins/enedis/data')) {
    rrmdir('/var/www/html/plugins/enedis/data');
  }

  $eqLogics = eqLogic::byType('enedis');
  foreach ($eqLogics as $eqLogic) {
    $crons = cron::searchClassAndFunction('enedis', 'pull', '"enedis_id":' . intval($eqLogic->getId()));
    if ($eqLogic->getIsEnable() == 1 && empty($crons)) {
      $eqLogic->refreshData();
    }
    if ($eqLogic->getConfiguration('login') != '' || $eqLogic->getConfiguration('password') != '') {
      $eqLogic->setConfiguration('login', null);
      $eqLogic->setConfiguration('password', null);
      $eqLogic->setIsEnable(0);
      $eqLogic->save(true);
    }
  }
}

?>
