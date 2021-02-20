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
      if (date('G') < 5 || date('G') >= 20) {
        if ($eqLogic->getCache('getEnedisData') == 'done') {
          $eqLogic->setCache('getEnedisData', null);
        }
      }
      else if ($eqLogic->getCache('getEnedisData') != 'done') {
        $eqLogic->refreshData();
      }
    }
  }

  public static function dependancy_info() {
    $return = array();
    $return['progress_file'] = jeedom::getTmpFolder('enedis') . '/dependance';
    $return['state'] = 'ok';
    if (exec("dpkg-query -W -f='\${Status}\n' php-mbstring") == 'unknown ok not-installed') {
      $return['state'] = 'nok';
    }
    // $packages = system::checkAndInstall(json_decode(file_get_contents(__DIR__.'/../../plugin_info/packages.json'),true));
    // $return['state'] = ($packages['apt::php-mbstring']['status'] == 1) ? 'ok' : 'nok';
    return $return;
  }

  public static function dependancy_install() {
    log::remove(__CLASS__ . '_update');
    return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder('enedis') . '/dependance', 'log' => log::getPathToLog(__CLASS__ . '_update'));
  }

  public function refreshData($startDate = null) {
    log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Interrogation des serveurs Enedis',__FILE__));
    $usagePointId = $this->getConfiguration('usage_point_id');
    $measureTypes = ($this->getConfiguration('measure_type') != 'both') ? [$this->getConfiguration('measure_type')] : array('consumption', 'production');
    $start_date = (empty($startDate)) ? date('Y-m-d', strtotime('first day of January')) : $startDate;
    $start_date_load = (empty($startDate)) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d', strtotime('-7 days'));
    $end_date = date('Y-m-d');
    $need_refresh = false;

    foreach ($measureTypes as $measureType) {
      $dailyCmd = $this->getCmd('info', 'daily_'.$measureType);
      $dailyCmd->execCmd();
      if (empty($startDate) && $dailyCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyCmd->getName() . __('] Données déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
      }
      else {
        $monthlyCmd = $this->getCmd('info', 'monthly_'.$measureType);
        $yearlyCmd = $this->getCmd('info', 'yearly_'.$measureType);
        $returnMonthValue = 0;
        $returnYearValue = 0;
        $need_refresh = true;
        $data = $this->getData('/metering_data/daily_'.$measureType.'?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if(isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])) {
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $valueTimestamp = strtotime($value['date']);
            $this->checkData($dailyCmd, $value['value'], date('Y-m-d 00:00:00', $valueTimestamp));

            if ($value['date'] == date('Y-m-01', $valueTimestamp)) {
              $returnMonthValue = $value['value'];
            }
            else {
              $returnMonthValue += $value['value'];
            }
            $this->checkData($monthlyCmd, $returnMonthValue, date('Y-m-d 00:00:00', $valueTimestamp));

            if ($value['date'] == date('Y-01-01', $valueTimestamp)) {
              $returnYearValue = $value['value'];
            }
            else {
              $returnYearValue += $value['value'];
            }
            $this->checkData($yearlyCmd, $returnYearValue, date('Y-m-d 00:00:00', $valueTimestamp));
          }
        }
        else if (isset($data['error'])) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyCmd->getName() . __('] Erreur sur la récupération des données : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
        }
      }

      $dailyMaxCmd = $this->getCmd('info', 'daily_'.$measureType.'_max_power');
      $dailyMaxCmd->execCmd();
      if (empty($startDate) && $dailyMaxCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyMaxCmd->getName() . __('] Données déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
      }
      else {
        $need_refresh = true;
        $data = $this->getData('/metering_data/daily_'.$measureType.'_max_power?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if(isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])){
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $this->checkData($dailyMaxCmd, $value['value'], $value['date']);
          }
        }
        else if (isset($data['error'])) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyMaxCmd->getName() . __('] Erreur sur la récupération des données : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
        }
      }

      $loadCmd = $this->getCmd('info', $measureType.'_load_curve');
      $loadCmd->execCmd();
      if ($loadCmd->getCollectDate() >= date('Y-m-d', strtotime('today'))) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $loadCmd->getName() . __('] Données déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
      }
      else {
        $need_refresh = true;
        $data = $this->getData('/metering_data/'.$measureType.'_load_curve?start='.$start_date_load.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if (isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])) {
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $this->checkData($loadCmd, $value['value'], $value['date']);
          }
        }
        else if (isset($data['error'])) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $loadCmd->getName() . __('] Erreur sur la récupération des données : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
        }
      }
    }

    if ($need_refresh === false && $this->getCache('getEnedisData') != 'done') {
      $this->setCache('getEnedisData', 'done');
      log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Toutes les données sont à jour - désactivation de la vérification automatique pour aujourd\'hui',__FILE__));
    }
  }

  public function getData($_path){
    $url = config::byKey('service::cloud::url').'/service/enedis?path='.urlencode($_path);
    $request_http = new com_http($url);
    $request_http->setHeader(array('Content-Type: application/json','Autorization: '.sha512(mb_strtolower(config::byKey('market::username')).':'.config::byKey('market::password'))));
    $result = json_decode($request_http->exec(30,1),true);
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
    if (!is_object($cmdHistory)) {
      log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $cmd->getName() . __('] Enregistrement mesure : Date = ',__FILE__) . $date . __(' => Mesure = ',__FILE__) . $value);
      $cmd->event($value, $date);
    }
  }

  public function preInsert() {
    $this->setDisplay('height','332px');
    $this->setDisplay('width', '192px');
    $this->setCategory('energy', 1);
    $this->setIsEnable(1);
    $this->setIsVisible(1);
    $this->setConfiguration('widgetTemplate', 1);
    $this->setConfiguration('widgetBGColor', '#A3CC28');
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
    if ($this->getIsEnable() == 1) {
      $refreshCmd = $this->getCmd(null, 'refresh');
      if (!is_object($refreshCmd)) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Création commande : refresh/Rafraîchir',__FILE__));
        $refreshCmd = (new enedisCmd)
        ->setLogicalId('refresh')
        ->setEqLogic_id($this->getId())
        ->setName('Rafraîchir')
        ->setType('action')
        ->setSubType('other')
        ->setOrder(1)
        ->save();
      }

      if (!is_file(dirname(__FILE__) . '/../../data/cmds/commands.json')) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . __('Fichier de création de commandes non trouvé',__FILE__));
      }
      else {
        $cmdsJson = file_get_contents(dirname(__FILE__) . '/../../data/cmds/commands.json');
        $cmdsArray = json_decode($cmdsJson, true);
        $measureType = $this->getConfiguration('measure_type');

        if ($measureType == 'both') {
          $cons = $this->createCommands($cmdsArray['consumption']);
          $prod = $this->createCommands($cmdsArray['production']);
          if ($cons === true || $prod === true) {
            $this->refreshData(date('Y-m-d', strtotime('-3 years')));
          }
        }
        else if ($this->createCommands($cmdsArray[$measureType])){
          $this->refreshData(date('Y-m-d', strtotime('-3 years')));
        }
      }
    }

  }

  public function createCommands($type) {
    $cmdsCreation = false;
    foreach ($type as $cmd2create) {
      $cmd = $this->getCmd(null, $cmd2create['logicalId']);
      if (!is_object($cmd)) {
        $cmdsCreation = true;
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
    return $cmdsCreation;
  }

  public function toHtml($_version = 'dashboard') {
    if ($this->getConfiguration('widgetTemplate') != 1)
    {
      return parent::toHtml($_version);
    }

    $replace = $this->preToHtml($_version);
    if (!is_array($replace)) {
      return $replace;
    }
    $version = jeedom::versionAlias($_version);

    foreach (($this->getCmd('info')) as $cmd) {
      $replace['#' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
      $replace['#' . $cmd->getLogicalId() . '#'] = $cmd->execCmd();
      $replace['#' . $cmd->getLogicalId() . '_collect#'] = $cmd->getCollectDate();
    }
    $replace['#refresh_id#'] = $this->getCmd('action', 'refresh')->getId();
    $replace['#BGEnedis#'] = ($this->getConfiguration('widgetTransparent') == 1) ? 'transparent' : $this->getConfiguration('widgetBGColor');
    $replace['#measureType#'] = $this->getConfiguration('measure_type');

    $html = template_replace($replace, getTemplate('core', $version, 'enedis.template', __CLASS__));
    cache::set('widgetHtml' . $_version . $this->getId(), $html, 0);
    return $html;
  }

}

class enedisCmd extends cmd {

  public function execute($_options = array()) {
    if ($this->getLogicalId() == 'refresh') {
      $eqLogic = $this->getEqLogic();
      return $eqLogic->refreshData();
    }
  }

}
