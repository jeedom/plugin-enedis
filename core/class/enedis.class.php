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

  public static $_widgetPossibility = array(
    'custom' => true,
    'parameters' => array(
      'BGEnedis' => array(
        'name' => 'Template : background-color',
        'type' => 'color',
        'default' => '#a3cc28',
        'allow_transparent' => true,
        'allow_displayType' => true
      ),
      'BGTitle' => array(
        'name' => 'Template : titlebar-color',
        'type' => 'color',
        'default' => '',
        'allow_transparent' => true,
        'allow_displayType' => true
      )
    )
  );

  public static function cleanCrons($eqLogicId) {
    $crons = cron::searchClassAndFunction(__CLASS__, 'pull', '"enedis_id":' . $eqLogicId);
    if (!empty($crons)) {
      foreach ($crons as $cron) {
        $cron->remove(false);
      }
    }
  }

  public static function pull($options) {
    $eqLogic = self::byId($options['enedis_id']);
    if (!is_object($eqLogic)) {
      self::cleanCrons($options['enedis_id']);
      throw new Exception(__('Tâche supprimée car équipement non trouvé', __FILE__) . ' (ID) : ' . $options['enedis_id']);
    }
    unset($options['enedis_id']);
    sleep(mt_rand(1, 59));
    $eqLogic->refreshData(null, $options);
  }

  public function reschedule($options = array()) {
    if (empty($options)) {
      $next_launch = strtotime('+1 day ' . date('Y-m-d ' . mt_rand(3, 6) . ':' . mt_rand(1, 59)));
    } else {
      $next_launch = strtotime('+30 minutes ' . date('Y-m-d H:i'));
    }
    log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Prochaine programmation', __FILE__) . ' : ' . date('d/m/Y H:i', $next_launch));
    $options['enedis_id'] = intval($this->getId());
    self::cleanCrons($options['enedis_id']);
    $cron = (new cron)
      ->setClass(__CLASS__)
      ->setFunction('pull')
      ->setOption($options)
      ->setTimeout(25)
      ->setOnce(1);
    $cron->setSchedule(cron::convertDateToCron($next_launch));
    $cron->save();
  }

  public function refreshData($_startDate = null, $_toRefresh = array()) {
    if ($this->getIsEnable() == 1) {
      log::add(__CLASS__, 'debug', $this->getHumanName() . ' -----------------------------------------------------------------------');
      log::add(__CLASS__, 'debug', $this->getHumanName() . ' *** ' . __("Début d'interrogation des serveurs Enedis", __FILE__) . ' ***');
      if (empty($_startDate)) {
        $start_date = date('Y-01-01', strtotime('-1 day'));
        $start_date_load = date('Y-m-d', strtotime('-7 days'));
        $end_date = $end_date_load = date('Y-m-d');
      } else {
        $start_date = $start_date_load = $_startDate;
        $end_date = date('Y-01-01');
        $end_date_load = date('Y-m-d', strtotime('+7 days ' . $_startDate));
      }

      $measureTypes = ($this->getConfiguration('measure_type') != 'both') ? [$this->getConfiguration('measure_type')] : ['consumption', 'production'];
      foreach ($measureTypes as $measureType) {
        $measureName = ($measureType == 'consumption') ? 'consommation' : 'production';
        $dailyCmd = $this->getCmd('info', 'daily_' . $measureType);
        $dailyCmd->execCmd();
        if (empty($_startDate) && $dailyCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Données journalières déjà enregistrées pour le', __FILE__) . ' ' . date('d/m/Y', strtotime('-1 day')));
        } else if (empty($_toRefresh) || isset($_toRefresh['daily_' . $measureType])) {
          $to_refresh['daily_' . $measureType] = true;
          $monthlyCmd = $this->getCmd('info', 'monthly_' . $measureType);
          $yearlyCmd = $this->getCmd('info', 'yearly_' . $measureType);
          $currentMonth = $currentYear = '';

          log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Récupération des données journalières', __FILE__) . ' : ' . $measureType . '?start=' . $start_date . '&end=' . $end_date);
          foreach ($this->getMeasures($measureName . '_quotidienne', $start_date, $end_date) as $date => $value) {
            $valueTimestamp = strtotime($date);

            if (date('Y-m', $valueTimestamp) != $currentMonth) {
              $currentMonth = date('Y-m', $valueTimestamp);
              $returnMonthValue = 0;
            }
            if (date('Y', $valueTimestamp) != $currentYear) {
              $currentYear = date('Y', $valueTimestamp);
              $returnYearValue = 0;
            }
            $returnMonthValue += $value;
            $returnYearValue += $value;

            if (empty($_startDate) && date('Y-m-d', $valueTimestamp) >= date('Y-m-d', strtotime('-1 day ' . $end_date))) {
              unset($to_refresh['daily_' . $measureType]);
              $this->recordData($dailyCmd, $value, date('Y-m-d 00:00:00', $valueTimestamp), 'event');
              $this->recordData($monthlyCmd, $returnMonthValue, date('Y-m-d 00:00:00', $valueTimestamp), 'event');
              $this->recordData($yearlyCmd, $returnYearValue, date('Y-m-d 00:00:00', $valueTimestamp), 'event');
            } else {
              $this->recordData($dailyCmd, $value, date('Y-m-d 00:00:00', $valueTimestamp));
              $this->recordData($monthlyCmd, $returnMonthValue, date('Y-m-d 00:00:00', $valueTimestamp));
              $this->recordData($yearlyCmd, $returnYearValue, date('Y-m-d 00:00:00', $valueTimestamp));
            }
          }
        }

        if ($this->getConfiguration('no_load_curve', 0) != 1) {
          $loadCmd = $this->getCmd('info', $measureType . '_load_curve');
          $loadCmd->execCmd();
          if (empty($_startDate) && $loadCmd->getCollectDate() >= date('Y-m-d')) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Données horaires déjà enregistrées pour le', __FILE__) . ' ' . date('d/m/Y', strtotime('-1 day')));
          } else if (empty($_toRefresh) || isset($_toRefresh[$measureType . '_load_curve'])) {
            $to_refresh[$measureType . '_load_curve'] = true;

            log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Récupération des données horaires', __FILE__) . ' : ' . $measureType . '?start=' . $start_date_load . '&end=' . $end_date_load);
            foreach ($this->getMeasures('courbe_de_charge_' . $measureName, $start_date_load, $end_date_load) as $date => $value) {
              if (empty($_startDate) && $date >= $end_date_load) {
                unset($to_refresh[$measureType . '_load_curve']);
                $this->recordData($loadCmd, $value, $date, 'event');
              } else {
                $this->recordData($loadCmd, $value, $date);
              }
            }
          }
        }

        if ($measureType == 'consumption') {
          $dailyMaxCmd = $this->getCmd('info', 'daily_' . $measureType . '_max_power');
          $dailyMaxCmd->execCmd();
          if (empty($_startDate) && $dailyMaxCmd->getCollectDate() >= date('Y-m-d', strtotime('-1 day'))) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Données de puissance déjà enregistrées pour le', __FILE__) . ' ' . date('d/m/Y', strtotime('-1 day')));
          } else if (empty($_toRefresh) || isset($_toRefresh['daily_' . $measureType . '_max_power'])) {
            $to_refresh['daily_' . $measureType . '_max_power'] = true;

            log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Récupération des données de puissance', __FILE__) . ' : ' . $measureType . '?start=' . $start_date . '&end=' . $end_date);
            foreach ($this->getMeasures('puissance_conso_max_quotidienne', $start_date, $end_date, '&mesuresPas=P1D&grandeurPhysique=PMA') as $date => $value) {
              if (empty($_startDate) && $date >= date('Y-m-d', strtotime('-1 day ' . $end_date))) {
                unset($to_refresh['daily_' . $measureType . '_max_power']);
                $this->recordData($dailyMaxCmd, $value, $date, 'event');
              } else {
                $this->recordData($dailyMaxCmd, $value, $date);
              }
            }
          }
        }
      }

      if (empty($_startDate)) {
        $this->refreshWidget();
        if (empty($to_refresh)) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Toutes les données ont été récupérées', __FILE__));
          $this->reschedule();
        } else if (date('G') >= 19) {
          log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Arrêt des appels aux serveurs Enedis', __FILE__));
          $this->reschedule();
        } else {
          log::add(__CLASS__, 'warning', $this->getHumanName() . ' ' . __("Certaines données n'ont pas été récupérées", __FILE__) . ' : ' . implode(' ', array_keys($to_refresh)));
          $this->reschedule($to_refresh);
        }
      }
      log::add(__CLASS__, 'debug', $this->getHumanName() . ' *** ' . __("Fin d'interrogation des serveurs Enedis", __FILE__) . ' ***');
    }
  }

  public function callEnedis($_path) {
    try {
      $url = config::byKey('service::cloud::url') . '/service/enedis2?path=' . urlencode($_path);
      $request_http = new com_http($url);
      $request_http->setHeader(array('Content-Type: application/json', 'Autorization: ' . sha512(mb_strtolower(config::byKey('market::username')) . ':' . config::byKey('market::password'))));
      $result = json_decode($request_http->exec(30, 1), true);
    } catch (exception $e) {
      $result = array('error' => $e);
    }
    return $result;
  }

  public function getMeasures(string $_resource, string $_start, string $_end, string $_params = ''): array {
    $data = $this->callEnedis('/mesure_synchrone_auto/v2/' . $_resource . '?pointId=' . $this->getConfiguration('usage_point_id') . '&dateDebut=' . $_start . '&dateFin=' . $_end . $_params);
    if (!isset($data['grandeur'][0]['points'])) {
      if (isset($data['error'])) {
        log::add(__CLASS__, 'warning', $this->getHumanName() . ' ' . __('Erreur lors de la récupération des données', __FILE__) . ' ' . $_resource . ' : ' . $data['error'] . (isset($data['error_description']) ? ' ' . $data['error_description'] : ''));
      } else {
        log::add(__CLASS__, 'warning', $this->getHumanName() . ' ' . __('Réponse inattendue pour', __FILE__) . ' ' . $_resource . ' : ' . json_encode($data));
      }
      return array();
    }
    $measures = array();
    foreach ($data['grandeur'][0]['points'] as $point) {
      $measures[$point['d']] = $point['v'];
    }
    return $measures;
  }

  public function recordData($cmd, $value, $date, $function = 'addHistoryValue') {
    $record = false;
    $formattedValue = $cmd->formatValue($value);
    if (strpos($cmd->getLogicalId(), 'load') !== false) {
      if (date('Gi', strtotime($date)) == 2330) {
        if (!is_object(history::byCmdIdDatetime($cmd->getId(), date('Y-m-d 00:00:00', strtotime('+1 day ' . $date))))) {
          $record = true;
        }
      } else if (date('Gi', strtotime($date)) == 000) {
        if (!is_object(history::byCmdIdDatetime($cmd->getId(), $date))) {
          $record = true;
        }
      } else if (empty($cmd->getHistory($date, date('Y-m-d 23:59:59', strtotime($date))))) {
        $record = true;
      }
    } else {
      $day = date('Y-m-d', strtotime($date));
      $histories = $cmd->getHistory($day . ' 00:00:00', $day . ' 23:59:59');
      if (empty($histories)) {
        $record = true;
      } else if (count($histories) > 1 || $histories[0]->getValue() != $formattedValue) {
        log::add(__CLASS__, 'debug', $cmd->getHumanName() . ' ' . __("Correction de l'historique", __FILE__) . ' : ' . $day . ' => ' . $histories[0]->getValue() . ' > ' . $formattedValue);
        history::removes($cmd->getId(), $day . ' 00:00:00', $day . ' 23:59:59');
        $record = true;
      }
    }

    if ($record) {
      if ($function === 'event') {
        log::add(__CLASS__, 'debug', $cmd->getHumanName() . ' ' . __('Mise à jour de la valeur', __FILE__) . ' : ' . $date . ' => ' . $formattedValue);
        $cmd->event($value, $date);
      } else {
        log::add(__CLASS__, 'debug', $cmd->getHumanName() . ' ' . __('Enregistrement historique', __FILE__) . ' : ' . $date . ' => ' . $formattedValue);
        $cmd->addHistoryValue($formattedValue, $date);
      }
    }
  }

  public function preInsert() {
    $this->setDisplay('height', '332px');
    $this->setDisplay('width', '192px');
    $this->setCategory('energy', 1);
    $this->setIsEnable(1);
    $this->setIsVisible(1);
    $this->setDisplay('advanceWidgetParameterBGTitledashboard-default', 0);
    $this->setDisplay('advanceWidgetParameterBGTitlemobile-default', 0);
    $this->setDisplay('advanceWidgetParameterBGTitledashboard-transparent', 1);
    $this->setDisplay('advanceWidgetParameterBGTitlemobile-transparent', 1);
  }

  public function preUpdate() {
    if ($this->getIsEnable() == 1) {
      $usagePointId = $this->getConfiguration('usage_point_id');
      if (empty($usagePointId)) {
        throw new Exception(__("L'identifiant du point de livraison (PDL) doit être renseigné", __FILE__));
      }
      if (strlen($usagePointId) != 14) {
        throw new Exception(__("L'identifiant du point de livraison (PDL) doit contenir 14 caractères", __FILE__));
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
        log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Création commande : refresh/Rafraîchir', __FILE__));
        $refreshCmd = (new enedisCmd)
          ->setLogicalId('refresh')
          ->setEqLogic_id($this->getId())
          ->setName(__('Rafraîchir', __FILE__))
          ->setType('action')
          ->setSubType('other')
          ->setOrder(0)
          ->save();
      }

      $cmdsArray = json_decode(file_get_contents(dirname(__FILE__) . '/../config/cmds/commands.json'), true);
      $measureTypes = ($this->getConfiguration('measure_type') != 'both') ? [$this->getConfiguration('measure_type')] : ['consumption', 'production'];
      foreach ($measureTypes as $measureType) {
        $this->createCommands($cmdsArray[$measureType]);
      }
      $this->refreshData();
    } else {
      self::cleanCrons(intval($this->getId()));
    }
  }

  public function preRemove() {
    self::cleanCrons(intval($this->getId()));
  }

  public function createCommands($type) {
    foreach ($type as $cmd2create) {
      if ($this->getConfiguration('no_load_curve', 0) == 1 && in_array($cmd2create['logicalId'], ['consumption_load_curve', 'production_load_curve'])) {
        continue;
      }
      $cmd = $this->getCmd(null, $cmd2create['logicalId']);
      if (!is_object($cmd)) {
        log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('Création commande', __FILE__) . ' : ' . $cmd2create['logicalId'] . '/' . $cmd2create['name']);
        $cmd = (new enedisCmd)
          ->setLogicalId($cmd2create['logicalId'])
          ->setEqLogic_id($this->getId())
          ->setName($cmd2create['name'])
          ->setType('info')
          ->setSubType('numeric')
          ->setTemplate('dashboard', 'tile')
          ->setTemplate('mobile', 'tile')
          ->setDisplay('showStatsOndashboard', 0)
          ->setDisplay('showStatsOnmobile', 0)
          ->setDisplay('graphType', $cmd2create['graphType'])
          ->setUnite($cmd2create["unite"])
          ->setOrder($cmd2create["order"])
          ->setIsVisible($cmd2create['isVisible'])
          ->setIsHistorized($cmd2create['isHistorized']);
        if (isset($cmd2create['generic_type'])) {
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
    $replace = $this->preToHtml($_version);
    if (!is_array($replace)) {
      return $replace;
    }
    $version = jeedom::versionAlias($_version);

    foreach (($this->getCmd('info')) as $cmd) {
      $logical = $cmd->getLogicalId();
      $collectDate = $cmd->getCollectDate();
      $expectedCollectDate = (in_array($logical, ['consumption_load_curve', 'production_load_curve'])) ? date('Y-m-d') : date('Y-m-d', strtotime('-1 day'));

      $replace['#' . $logical . '_id#'] = $cmd->getId();
      $replace['#' . $logical . '#'] = $cmd->execCmd();
      $replace['#' . $logical . '_unite#'] = $cmd->getUnite();
      $replace['#' . $logical . '_collect#'] = $collectDate;
      $replace['#' . $logical . '_toDate#'] = ($collectDate >= $expectedCollectDate) ? 1 : 0;
    }
    $replace['#measureType#'] = $this->getConfiguration('measure_type');
    $replace['#noLoadCurve#'] = $this->getConfiguration('no_load_curve', 0);

    return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'enedis.template', __CLASS__)));
  }
}

class enedisCmd extends cmd {
  public function execute($_options = array()) {
    if ($this->getLogicalId() == 'refresh') {
      return $this->getEqLogic()->refreshData();
    }
  }
}
