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
    else if (config::byKey('lastDependancyInstallTime', 'enedis') == '') {
      config::save('lastDependancyInstallTime', date('Y-m-d H:i:s'), 'enedis');
    }
    return $return;
  }

  public static function dependancy_install() {
    log::remove(__CLASS__ . '_update');
    return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder('enedis') . '/dependance', 'log' => log::getPathToLog(__CLASS__ . '_update'));
  }

  public static function cleanCrons($eqLogicId) {
    $crons = cron::searchClassAndFunction('enedis', 'pull', '"enedis_id":' . $eqLogicId);
    if (is_array($crons)) {
      foreach ($crons as $cron) {
        $cron->remove(false);
      }
    }
  }

  public static function pull($options) {
    $eqLogic = enedis::byId($options['enedis_id']);
    if (!is_object($eqLogic)) {
      throw new Exception(__('Tâche supprimée car équipement non trouvé (ID) : ', __FILE__) . $options['enedis_id']);
      enedis::cleanCrons($options['enedis_id']);
    }
    $options = $eqLogic->cleanArray($options, 'enedis_id');
    sleep(rand(1,59));
    $eqLogic->refreshData(null, $options);
  }

  public function reschedule($options = array()) {
    if (empty($options)) {
      $next_launch = strtotime('+1 day ' . date('Y-m-d 07:'.rand(1,59)));
    }
    else {
      $next_launch = strtotime('+1 hour ' . date('Y-m-d H:i'));
    }
    log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Prochaine programmation : ',__FILE__) . date('d/m/Y H:i', $next_launch));
    $options['enedis_id'] = intval($this->getId());
    self::cleanCrons($options['enedis_id']);
    $cron = (new cron)
    ->setClass(__CLASS__)
    ->setFunction('pull')
    ->setOption($options)
    ->setTimeout(120)
    ->setOnce(1);
    $cron->setSchedule(cron::convertDateToCron($next_launch));
    $cron->save();
  }

  public function refreshData($_startDate = null, $_toRefresh = array()) {
    if ($this->getIsEnable() == 1) {
      log::add(__CLASS__, 'debug', $this->getHumanName() .' -----------------------------------------------------------------------');
      log::add(__CLASS__, 'debug', $this->getHumanName() . __(' *** Début d\'interrogation des serveurs Enedis ***',__FILE__));
      $usagePointId = $this->getConfiguration('usage_point_id');
      if (empty($_startDate)) {
        $start_date = date('Y-m-d', strtotime('first day of January'));
        $start_date_load = date('Y-m-d', strtotime('-7 days'));
        $end_date = $end_date_load = date('Y-m-d');
      }
      else {
        $start_date = $start_date_load = $_startDate;
        $end_date = date('Y-m-d', strtotime('first day of January'));
        $end_date_load = date('Y-m-d', strtotime('+7 days '.$_startDate));
      }

      $measureTypes = ($this->getConfiguration('measure_type') != 'both') ? [$this->getConfiguration('measure_type')] : array('consumption', 'production');
      foreach ($measureTypes as $measureType) {
        $dailyCmd = $this->getCmd('info', 'daily_'.$measureType);
        $dailyCmd->execCmd();
        if (empty($_startDate) && $dailyCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Données journalières déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
        }
        else if (empty($_toRefresh) || $_toRefresh['daily_'.$measureType]) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Récupération des données journalières',__FILE__));
          $to_refresh['daily_'.$measureType] = true;
          $monthlyCmd = $this->getCmd('info', 'monthly_'.$measureType);
          $yearlyCmd = $this->getCmd('info', 'yearly_'.$measureType);
          $returnMonthValue = $returnYearValue = 0;

          $data = $this->callEnedis('/metering_data/daily_'.$measureType.'?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
          if(isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])) {
            foreach ($data['meter_reading']['interval_reading'] as $value) {
              $valueTimestamp = strtotime($value['date']);

              if ($value['date'] == date('Y-m-01', $valueTimestamp)) {
                $returnMonthValue = $value['value'];
              }
              else {
                $returnMonthValue += $value['value'];
              }

              if ($value['date'] == date('Y-01-01', $valueTimestamp)) {
                $returnYearValue = $value['value'];
              }
              else {
                $returnYearValue += $value['value'];
              }

              if (empty($_startDate) && date('Y-m-d', $valueTimestamp) >= date('Y-m-d', strtotime('-1 day '.$end_date))) {
                $to_refresh = $this->cleanArray($to_refresh, 'daily_'.$measureType);
                $this->recordData($dailyCmd, $value['value'], date('Y-m-d 00:00:00', $valueTimestamp), 'event');
                $this->recordData($monthlyCmd, $returnMonthValue, date('Y-m-d 00:00:00', $valueTimestamp), 'event');
                $this->recordData($yearlyCmd, $returnYearValue, date('Y-m-d 00:00:00', $valueTimestamp), 'event');
              }
              else {
                $this->recordData($dailyCmd, $value['value'], date('Y-m-d 00:00:00', $valueTimestamp));
                $this->recordData($monthlyCmd, $returnMonthValue, date('Y-m-d 00:00:00', $valueTimestamp));
                $this->recordData($yearlyCmd, $returnYearValue, date('Y-m-d 00:00:00', $valueTimestamp));
              }
            }
          }
          else if (isset($data['error'])) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Erreur lors de la récupération des données journalières : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
          }
        }

        $loadCmd = $this->getCmd('info', $measureType.'_load_curve');
        $loadCmd->execCmd();
        if (empty($_startDate) && $loadCmd->getCollectDate() >= date('Y-m-d')) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Données horaires déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
        }
        else if (empty($_toRefresh) || $_toRefresh[$measureType.'_load_curve']) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Récupération des données horaires',__FILE__));
          $to_refresh[$measureType.'_load_curve'] = true;
          $data = $this->callEnedis('/metering_data/'.$measureType.'_load_curve?start='.$start_date_load.'&end='.$end_date_load.'&usage_point_id='.$usagePointId);
          if (isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])) {
            foreach ($data['meter_reading']['interval_reading'] as $value) {
              if (empty($_startDate) && $value['date'] >= $end_date_load) {
                $to_refresh = $this->cleanArray($to_refresh, $measureType.'_load_curve');
                $this->recordData($loadCmd, $value['value'], $value['date'], 'event');
              }
              else {
                $this->recordData($loadCmd, $value['value'], $value['date']);
              }
            }
          }
          else if (isset($data['error'])) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Erreur lors de la récupération des données horaires : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
          }
        }

        $dailyMaxCmd = $this->getCmd('info', 'daily_'.$measureType.'_max_power');
        $dailyMaxCmd->execCmd();
        if (empty($_startDate) && $dailyMaxCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Données de puissance déjà enregistrées pour le ',__FILE__) . date('d/m/Y', strtotime('-1 day')));
        }
        else if (empty($_toRefresh) || $_toRefresh['daily_'.$measureType.'_max_power']) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Récupération des données de puissance',__FILE__));
          $to_refresh['daily_'.$measureType.'_max_power'] = true;
          $data = $this->callEnedis('/metering_data/daily_'.$measureType.'_max_power?start='.$start_date.'&end='.$end_date.'&usage_point_id='.$usagePointId);
          if(isset($data['meter_reading']) && isset($data['meter_reading']['interval_reading'])){
            foreach ($data['meter_reading']['interval_reading'] as $value) {
              if (empty($_startDate) && $value['date'] >= date('Y-m-d', strtotime('-1 day '.$end_date))) {
                $to_refresh = $this->cleanArray($to_refresh, 'daily_'.$measureType.'_max_power');
                $this->recordData($dailyMaxCmd, $value['value'], $value['date'], 'event');
              }
              else {
                $this->recordData($dailyMaxCmd, $value['value'], $value['date']);
              }
            }
          }
          else if (isset($data['error'])) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Erreur lors de la récupération des données de puissance : ',__FILE__) . $data['error'] . ' ' . $data['error_description']);
          }
        }
      }

      if (empty($_startDate)) {
        if (empty($to_refresh)) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Toutes les données ont été récupérées',__FILE__));
          $this->reschedule();
        }
        else if (date('G') >= 19) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Arrêt des appels aux serveurs Enedis',__FILE__));
          $this->reschedule();
        }
        else {
          log::add(__CLASS__, 'debug', $this->getHumanName() . __(' Certaines données n\'ont pas été récupérées : ',__FILE__) . implode(' ', array_keys($to_refresh)));
          $this->reschedule($to_refresh);
        }
      }
      log::add(__CLASS__, 'debug', $this->getHumanName() . __(' *** Fin d\'interrogation des serveurs Enedis ***',__FILE__));
    }
  }

  public function callEnedis($_path){
    $url = config::byKey('service::cloud::url').'/service/enedis?path='.urlencode($_path);
    $request_http = new com_http($url);
    $request_http->setHeader(array('Content-Type: application/json','Autorization: '.sha512(mb_strtolower(config::byKey('market::username')).':'.config::byKey('market::password'))));
    $result = json_decode($request_http->exec(30,1),true);
    return $result;
  }

  public function recordData($cmd, $value, $date, $function = 'addHistoryValue') {
    if (date('Gi', strtotime($date)) == 000 && !is_object(history::byCmdIdDatetime($cmd->getId(), $date)) || date('Gi', strtotime($date)) != 000 && date('Gi', strtotime($date)) != 2330 && empty($cmd->getHistory(date('Y-m-d H:i:s', strtotime('-29 minutes '.$date)), date('Y-m-d 23:59:59', strtotime($date)))) || date('Gi', strtotime($date)) == 2330 && !is_object(history::byCmdIdDatetime($cmd->getId(), date('Y-m-d 00:00:00', strtotime('+1 day ' . $date))))) {
      if ($function === 'event') {
        log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $cmd->getName() . __('] Mise à jour de la valeur : Date = ',__FILE__) . $date . __(' => Mesure = ',__FILE__) . $value);
        $cmd->event($value, $date);
      }
      else {
        log::add(__CLASS__, 'debug', $this->getHumanName() . '[' . $cmd->getName() . __('] Enregistrement historique : Date = ',__FILE__) . $date . __(' => Mesure = ',__FILE__) . $value);
        $cmd->addHistoryValue($value/1000, $date);
      }
    }
  }

  public function cleanArray($array, $logical) {
    if (count($array) > 1) {
      unset($array[$logical]);
    }
    else {
      $array = array();
    }
    return $array;
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
    if ($this->getIsEnable() == 1) {
      $usagePointId = $this->getConfiguration('usage_point_id');
      if (empty($usagePointId)) {
        throw new Exception(__('L\'identifiant du point de livraison (PDL) doit être renseigné',__FILE__));
      }
      if (strlen($usagePointId) != 14) {
        throw new Exception(__('L\'identifiant du point de livraison (PDL) doit contenir 14 caractères',__FILE__));
      }
    }
  }

  public function postUpdate() {
    if ($this->getIsEnable() == 1) {
      if (!is_file(dirname(__FILE__) . '/../config/cmds/commands.json')) {
        throw new Exception(__('Fichier de création de commandes non trouvé', __FILE__));
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

      $cmdsArray = json_decode(file_get_contents(dirname(__FILE__) . '/../config/cmds/commands.json'), true);
      $measureTypes = ($this->getConfiguration('measure_type') != 'both') ? [$this->getConfiguration('measure_type')] : array('consumption', 'production');
      foreach ($measureTypes as $measureType) {
        $this->createCommands($cmdsArray[$measureType]);
      }
      $this->refreshData();
    }
    else {
      self::cleanCrons(intval($this->getId()));
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
