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

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class enedis extends eqLogic {

  public static function cron() {
    $cronMinute = config::byKey('cronMinute', __CLASS__);
    if (!empty($cronMinute) && date('i') != $cronMinute) return;

    $eqLogics = self::byType(__CLASS__, true);

    foreach ($eqLogics as $eqLogic) {
      if (date('G') < 4 || date('G') >= 22) {
        if ($eqLogic->getCache('getEnedisData') == 'done') {
          $eqLogic->setCache('getEnedisData', null);
        }
        return;
      }

      if ($eqLogic->getCache('getEnedisData') != 'done') {
        $eqLogic->refreshData();
      }
    }
  }

  public function refreshData() {
    log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Interrogation des serveurs Enedis',__FILE__));
    $usagePointId = $this->getConfiguration('usage_point_id');
    $measureType = $this->getConfiguration('measure_type');
    $start_date = date('Y-m-d', strtotime('first day of January'));
    $end_date = date('Y-m-d');
    $need_refresh = false;

    if ($measureType === 'consumption' || $measureType === 'both') {
      $dailyCCmd = $this->getCmd('info', 'daily_consumption');
      $dailyCCmd->execCmd();
      if ($dailyCCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyCCmd->getName() . __('] Données déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
      }
      else {
        $need_refresh = true;
        $data = $this->getData('/metering_data/daily_consumption?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if(isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])) {
          $year = 0;
          $month = 0;
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $this->checkData($dailyCCmd, $value['value'], $value['date']);
            if ($value['date'] >= date('Y-m-d', strtotime('first day of this month'))) {
              $month += $value['value'];
            }
            $year += $value['value'];
          }
          $this->checkAndUpdateCmd('yearly_consumption', $year);
          $this->checkAndUpdateCmd('monthly_consumption', $month);
        }
        else if (isset($data['error'])) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyCMaxCmd->getName() . __('] Erreur sur la récupération des données : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
        }
      }

      $dailyCMaxCmd = $this->getCmd('info', 'daily_consumption_max_power');
      $dailyCMaxCmd->execCmd();
      if ($dailyCMaxCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyCMaxCmd->getName() . __('] Données déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
      }
      else {
        $need_refresh = true;
        $data = $this->getData('/metering_data/daily_consumption_max_power?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if(isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])){
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $this->checkData($dailyCMaxCmd, $value['value'], $value['date']);
          }
        }
        else if (isset($data['error'])) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyCMaxCmd->getName() . __('] Erreur sur la récupération des données : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
        }
      }
    }

    if ($measureType === 'production' || $measureType === 'both') {

      $dailyPCmd = $this->getCmd('info', 'daily_production');
      $dailyPCmd->execCmd();
      if ($dailyPCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyPCmd->getName() . __('] Données déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
      }
      else {
        $need_refresh = true;
        $data = $this->getData('/metering_data/daily_production?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if(isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])) {
          $year = 0;
          $month = 0;
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $this->checkData($dailyPCmd, $value['value'], $value['date']);
            if ($value['date'] >= date('Y-m-d', strtotime('first day of this month'))) {
              $month += $value['value'];
            }
            $year += $value['value'];
          }
          $this->checkAndUpdateCmd('yearly_production', $year);
          $this->checkAndUpdateCmd('monthly_production', $month);
        }
        else if (isset($data['error'])) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyPCmd->getName() . __('] Erreur sur la récupération des données : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
        }
      }

      $dailyPMaxCmd = $this->getCmd('info', 'daily_production_max_power');
      $dailyPMaxCmd->execCmd();
      if ($dailyPMaxCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyPMaxCmd->getName() . __('] Données déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
      }
      else {
        $need_refresh = true;
        $data = $this->getData('/metering_data/daily_production_max_power?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if (isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])) {
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $this->checkData($dailyPMaxCmd, $value['value'], $value['date']);
          }
        }
        else if (isset($data['error'])) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyPMaxCmd->getName() . __('] Erreur sur la récupération des données : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
        }
      }
    }

    $start_date = date('Y-m-d',strtotime('now -7 days'));
    $end_date = date('Y-m-d');

    if ($measureType === 'consumption' || $measureType === 'both') {
      $consLoadCmd = $this->getCmd('info', 'consumption_load_curve');
      $consLoadCmd->execCmd();
      if ($consLoadCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $consLoadCmd->getName() . __('] Données déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
      }
      else {
        $need_refresh = true;
        $data = $this->getData('/metering_data/consumption_load_curve?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if (isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])) {
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $this->checkData($consLoadCmd, $value['value'], $value['date']);
          }
        }
        else if (isset($data['error'])) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $consLoadCmd->getName() . __('] Erreur sur la récupération des données : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
        }
      }
    }

    if ($measureType === 'production' || $measureType === 'both') {
      $prodLoadCmd = $this->getCmd('info', 'production_load_curve');
      $prodLoadCmd->execCmd();
      if ($prodLoadCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $prodLoadCmd->getName() . __('] Données déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
      }
      else {
        $need_refresh = true;
        $data = $this->getData('/metering_data/production_load_curve?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if (isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])) {
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $this->checkData($prodLoadCmd, $value['value'], $value['date']);
          }
        }
        else if (isset($data['error'])) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $prodLoadCmd->getName() . __('] Erreur sur la récupération des données : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
        }
      }
    }

    if ($need_refresh === false && $this->getCache('getEnedisData') != 'done') {
          $this->setCache('getEnedisData', 'done');
          log::add(__CLASS__, 'info', $this->getHumanName() . __(' Toutes les données sont à jour - désactivation de la vérification automatique pour aujourd\'hui',__FILE__));
        }
  }

  public function getData($_path){
    $url = config::byKey('service::cloud::url').'/service/enedis?path='.urlencode($_path);
    $request_http = new com_http($url);
    $request_http->setHeader(array('Content-Type: application/json','Autorization: '.sha512(mb_strtolower(config::byKey('market::username')).':'.config::byKey('market::password'))));
    $result = json_decode($request_http->exec(30,1),true);
    //  log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Données brutes : ',__FILE__) . print_r($result, true));
    // if (isset($result['error']) && !in_array($result['error'],array('Not found')) && isset($result['error_description'])){
    //   throw new \Exception($_path.' : '.$result['error'].' => '.$result['error_description']);
    // }
    return $result;
  }

  public function checkData($cmd, $value, $date) {
    if (strlen($date) === 19) {
      $rounded = new DateTime($date);
      $rounded->setTime(
        $rounded->format('H'),
        floor($rounded->format('i') / 5) * 5,
        0
      );
      $date = $rounded->format("Y-m-d H:i:s");
    }

    $cmdHistory = history::byCmdIdDatetime($cmd->getId(), $date);
    if (is_object($cmdHistory)) {
      log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $cmd->getName() . __('] Mesure en historique - Aucune action : Date = ',__FILE__) . $date . __(' => Mesure = ',__FILE__) . $value);
    }
    else {
      log::add(__CLASS__, 'info', $this->getHumanName() . '[' . $cmd->getName() . __('] Enregistrement mesure : Date = ',__FILE__) . $date . __(' => Mesure = ',__FILE__) . $value);
      $cmd->event($value, $date);
    }
  }

  public function preInsert() {
    $this->setDisplay('height','332px');
    $this->setDisplay('width', '192px');
    $this->setCategory('energy', 1);
    $this->setIsEnable(1);
    $this->setIsVisible(1);
  }

  public function preUpdate() {
    $usagePointId = $this->getConfiguration('usage_point_id');
    if (empty($usagePointId)) {
      throw new Exception(__('L\'identifiant du point de livraison (PDL) doit être renseigné',__FILE__));
    }
    if (strlen($usagePointId) != 14) {
      throw new Exception(__('L\'identifiant du point de livraison (PDL) doit contenir 14 caractères',__FILE__));
    }
  }

  public function postUpdate() {
    if (!is_file(dirname(__FILE__) . '/../../data/cmds/commands.json')) {
      log::add(__CLASS__, 'debug', $this->getHumanName() . __('Fichier de création de commandes non trouvé',__FILE__));
    }
    else {
      $cmdsJson = file_get_contents(dirname(__FILE__) . '/../../data/cmds/commands.json');
      $cmdsArray = json_decode($cmdsJson, true);
      $measureType = $this->getConfiguration('measure_type');

      if ($measureType === 'consumption' || $measureType === 'production') {
        $this->createCommands($cmdsArray[$measureType]);
      }
      else if ($measureType === 'both') {
        $this->createCommands($cmdsArray['consumption']);
        $this->createCommands($cmdsArray['production']);
      }
    }

    $refreshCmd = $this->getCmd(null, 'refresh');
    if (!is_object($refreshCmd)) {
      log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Création commande : refresh/Rafraîchir',__FILE__));
      $refreshCmd = (new enedisCmd)
      ->setLogicalId('refresh')
      ->setEqLogic_id($this->getId())
      ->setName('Rafraîchir')
      ->setType('action')
      ->setSubType('other')
      ->setOrder(0)
      ->save();
    }
  }

  public function createCommands($type) {
    foreach ($type as $cmd2create) {
      $cmd = $this->getCmd(null, $cmd2create['logicalId']);
      if (!is_object($cmd)) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Création commande : ',__FILE__) . $cmd2create['logicalId'].'/'.$cmd2create['name']);
        $cmd = (new enedisCmd)
        ->setLogicalId($cmd2create['logicalId'])
        ->setEqLogic_id($this->getId())
        ->setName($cmd2create['name'])
        ->setType('info')
        ->setSubType('numeric')
        ->setTemplate('dashboard','tile')
        ->setTemplate('mobile','tile')
        ->setDisplay('showStatsOndashboard', 0)
        ->setDisplay('showStatsOnmobile', 0)
        ->setUnite($cmd2create["unite"])
        ->setOrder($cmd2create["order"])
        ->setIsVisible($cmd2create['isVisible'])
        ->setIsHistorized($cmd2create['isHistorized']);
        if(isset($cmd2create['generic_type'])) {
          $cmd->setGeneric_type($cmd2create['generic_type']);
        }
        if (isset($cmd2create['configuration'])) {
          foreach ($cmd2create['configuration'] as $key => $value) {
            $cmd->setConfiguration($key, $value);
          }
        }
        $cmd->save();
      }
    }
  }

  // Non obligatoire : permet de modifier l'affichage du widget (également utilisable par les commandes)
  //  public function toHtml($_version = 'dashboard') {
  // if ($this->getConfiguration('widgetTemplate') != 1)
  // {
  // 	return parent::toHtml($_version);
  // }
  //
  // $replace = $this->preToHtml($_version);
  // if (!is_array($replace)) {
  //   return $replace;
  // }
  // $version = jeedom::versionAlias($_version);
  //
  // foreach ($this->getCmd('info') as $cmd) {
  //   $replace['#' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
  //   $replace['#' . $cmd->getLogicalId() . '#'] = $cmd->execCmd();
  //   $replace['#' . $cmd->getLogicalId() . '_collect#'] = $cmd->getCollectDate();
  // }
  //
  // $html = template_replace($replace, getTemplate('core', $version, 'enedis.template', __CLASS__));
  // cache::set('widgetHtml' . $_version . $this->getId(), $html, 0);
  // return $html;
  //}

}

class enedisCmd extends cmd {

  public function execute($_options = array()) {
    if ($this->getLogicalId() == 'refresh') {
      $eqLogic = $this->getEqLogic();
      return $eqLogic->refreshData($eqLogic);
    }
  }

}
