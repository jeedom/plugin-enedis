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

function enedis_update() {
  $eqLogics = eqLogic::byType('enedis');
  foreach ($eqLogics as $eqLogic) {
    $options = array('enedis_id' => intval($eqLogic->getId()));
    $cron = cron::byClassAndFunction(__CLASS__, 'pull', $options);
    if ($eqLogic->getIsEnable() == 1 && !is_object($cron)) {
      $eqLogic->reschedule();
    }

    // Remove old confs
    $cronMinute = config::byKey('cronMinute', 'enedis');
    if (!empty($cronMinute)) {
      config::remove('cronMinute', 'enedis');
    }
    if (!empty($eqLogic->getConfiguration('login'))) {
      $eqLogic->setConfiguration('login', null);
      $update = true;
    }
    if (!empty($eqLogic->getConfiguration('password'))) {
      $eqLogic->setConfiguration('password', null);
      $update = true;
    }
    if (isset($update) && $update === true) {
      $eqLogic->setIsEnable(0);
      $eqLogic->save(true);
    }
  }
}

function enedis_remove() {
  $eqLogics = eqLogic::byType('enedis');
  foreach ($eqLogics as $eqLogic) {
    $options = array('enedis_id' => intval($eqLogic->getId()));
    $cron = cron::byClassAndFunction(__CLASS__, 'pull', $options);
    if (is_object($cron)) {
      $cron->remove(false);
    }
  }
}
?>
