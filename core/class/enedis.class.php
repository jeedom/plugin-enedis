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

  public static function dependancy_info() {
    $return = array();
    $return['progress_file'] = jeedom::getTmpFolder('enedis') . '/dependance';
    $return['state'] = 'ok';
    if (exec("dpkg-query -W -f='\${Status}\n' php-mbstring") == 'unknown ok not-installed') {
      $return['state'] = 'nok';
    }
    /* Since v4.2 */
    // $packages = system::checkAndInstall(json_decode(file_get_contents(__DIR__.'/../../plugin_info/packages.json'),true));
    // $return['state'] = ($packages['apt::php-mbstring']['status'] == 1) ? 'ok' : 'nok';
    return $return;
  }

  public static function dependancy_install() {
    log::remove(__CLASS__ . '_update');
    return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder('enedis') . '/dependance', 'log' => log::getPathToLog(__CLASS__ . '_update'));
  }

  public static function pull($_options) {
    $eqLogic = enedis::byId($_options['enedis_id']);
    if (!is_object($eqLogic)) {
      $cron = cron::byClassAndFunction(__CLASS__, 'pull', $_options);
      if (is_object($cron)) {
        $cron->remove();
      }
      throw new Exception(__('Tâche supprimée car équipement non trouvé (ID) : ', __FILE__) . $_options['enedis_id']);
    }
    sleep(rand(1,59));
    $eqLogic->refreshData();
  }

  public function reschedule($_next = null) {
    if (empty($_next)) {
      $_next = strtotime('+1 day ' . date('Y-m-d 07:'.rand(1,59)));
    }
    log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Prochaine programmation : ',__FILE__) . date('d/m/Y H:i', $_next));
    $options = array('enedis_id' => intval($this->getId()));
    $cron = cron::byClassAndFunction(__CLASS__, 'pull', $options);
    if (is_object($cron)) {
      $cron->remove(false);
    }
    $cron = (new cron)
    ->setClass(__CLASS__)
    ->setFunction('pull')
    ->setOption($options)
    ->setTimeout(1440)
    ->setOnce(1);
    $cron->setSchedule(cron::convertDateToCron($_next));
    $cron->save();
  }

  public function refreshData($startDate = null) {
    if ($this->getIsEnable() == 1) {
      log::add(__CLASS__, 'debug', $this->getHumanName() .' -----------------------------------------------------------------------');
      log::add(__CLASS__, 'debug', $this->getHumanName() . __(' *** Interrogation des serveurs Enedis ***',__FILE__));
      $usagePointId = $this->getConfiguration('usage_point_id');
      $measureTypes = ($this->getConfiguration('measure_type') != 'both') ? [$this->getConfiguration('measure_type')] : array('consumption', 'production');
      $start_date = (empty($startDate)) ? date('Y-m-d', strtotime('first day of January')) : $startDate;
      $start_date_load = (empty($startDate)) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d', strtotime('-7 days'));
      $end_date = date('Y-m-d');
      $refresh_day = true;
      $refresh_power = true;
      $refresh_load = true;

      foreach ($measureTypes as $measureType) {
        $dailyCmd = $this->getCmd('info', 'daily_'.$measureType);
        $monthlyCmd = $this->getCmd('info', 'monthly_'.$measureType);
        $yearlyCmd = $this->getCmd('info', 'yearly_'.$measureType);
        $returnMonthValue = 0;
        $returnYearValue = 0;
        $data = $this->getData('/metering_data/daily_'.$measureType.'?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if(isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])) {
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $valueTimestamp = strtotime($value['date']);
            $this->checkData($dailyCmd, $value['value'], date('Y-m-d 00:00:00', $valueTimestamp));

            if (date('Y-m-d', $valueTimestamp) >= date('Y-m-d', strtotime('-1 day'))) {
              $refresh_day = false;
            }
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

        $dailyMaxCmd = $this->getCmd('info', 'daily_'.$measureType.'_max_power');
        $data = $this->getData('/metering_data/daily_'.$measureType.'_max_power?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if(isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])){
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $this->checkData($dailyMaxCmd, $value['value'], $value['date']);
            if ($value['date'] >= date('Y-m-d', strtotime('-1 day'))) {
              $refresh_power = false;
            }
          }
        }
        else if (isset($data['error'])) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $dailyMaxCmd->getName() . __('] Erreur sur la récupération des données : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
        }

        $loadCmd = $this->getCmd('info', $measureType.'_load_curve');
        $data = $this->getData('/metering_data/'.$measureType.'_load_curve?start='.$start_date_load.'&end='.$end_date.'&usage_point_id='.$usagePointId);
        if (isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])) {
          foreach ($data['meter_reading']['interval_reading'] as $value) {
            $this->checkData($loadCmd, $value['value'], $value['date']);
            if ($value['date'] >= date('Y-m-d')) {
              $refresh_load = false;
            }
          }
        }
        else if (isset($data['error'])) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $loadCmd->getName() . __('] Erreur sur la récupération des données : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
        }
      }

      if (!$refresh_day && !$refresh_power && !$refresh_load) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Toutes les données sont à jour',__FILE__));
        $this->reschedule();
      }
      else if (date('H') >= 19) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Fin des interrogations pour aujourd\'hui',__FILE__));
        $this->reschedule();
      }
      else {
        $next_launch = strtotime('+1 hour ' . date('Y-m-d H:i'));
        log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Certaines données n\'ont pas été récupérées',__FILE__));
        $this->reschedule($next_launch);
      }
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
      $rounded->setTime($rounded->format('H'), floor($rounded->format('i') / 5) * 5, 0);
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
    $options = array('enedis_id' => intval($this->getId()));
    $cron = cron::byClassAndFunction(__CLASS__, 'pull', $options);
    if ($this->getIsEnable() == 1) {
      if (!is_object($cron)) {
        $this->reschedule();
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
        ->setOrder(1)
        ->save();
      }

      if (!is_file(dirname(__FILE__) . '/../../data/cmds/commands.json')) {
        log::add(__CLASS__, 'error', $this->getHumanName() . __('Fichier de création de commandes non trouvé',__FILE__));
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
        else if ($this->createCommands($cmdsArray[$measureType])) {
          $this->refreshData(date('Y-m-d', strtotime('-3 years')));
        }
      }
    }
    else if (is_object($cron)) {
      $cron->remove(false);
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
