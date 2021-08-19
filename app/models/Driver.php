<?php

/**
 * Driver.php - class to manage the registerable drivers
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Affero General Public License
 * version 3 as published by the Free Software Foundation.
 *
 * @author      Till Glöggler <tgloeggl@uos.de>
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL version 3
 */

namespace ElanEv\Model;

use MeetingPlugin;
use ElanEv\Driver\DriverFactory;
use ElanEv\Model\Meeting;

class Driver
{
    static
        $config;

    static function discover($toArray = false)
    {
        $drivers = array();

        foreach (glob(__DIR__ . '/../../Driver/*.php') as $filename) {
            $class = 'ElanEv\\Driver\\' . substr(basename($filename), 0, -4);
            $title = '';
            $config_options = [];
            $recording_options = [];
            $preupload_option = [];
            if (in_array('ElanEv\Driver\DriverInterface', class_implements($class)) !== false) {
                $title          = substr(basename($filename), 0, -4);
                $config_options = $class::getConfigOptions();
            }

            if (in_array('ElanEv\Driver\RecordingInterface', class_implements($class)) !== false) {
                //If there is RecordingInterface then the field 'record' is considered as a must later on in the logic
                //that means, if admin set record to true then every other setting like opencast can be used
                $recording_options['record'] = new \ElanEv\Driver\ConfigOption('record', dgettext(MeetingPlugin::GETTEXT_DOMAIN, 'Normale Aufzeichnungen zulassen'), false);
                if ($oc_config = $class::useOpenCastForRecording()) {
                    $recording_options['opencast'] = $oc_config;
                }
            }

            if (in_array('ElanEv\Driver\FolderManagementInterface', class_implements($class)) !== false) {
                $preupload_option['preupload'] = new \ElanEv\Driver\ConfigOption('preupload', dgettext(MeetingPlugin::GETTEXT_DOMAIN, 'Automatisches Hochladen von Folien zulassen'), true); // Translation: Allow automatic upload of slides;
            }

            if ($title && $config_options) {
                $drivers[$title] = array(
                    'title'        => $title,
                    'display_name' => $title,
                    'config'    => $toArray ? self::convertDriverConfigToArray($config_options) : $config_options,
                );

                !$recording_options ?:  $drivers[$title]['recording'] = $toArray ? self::convertDriverConfigToArray($recording_options) : $recording_options;
                !$preupload_option ?:  $drivers[$title]['preupload'] = $toArray ? self::convertDriverConfigToArray($preupload_option) : $preupload_option;
            }
        }

        return $drivers;
    }

    static function loadConfig()
    {
        if (!self::$config) {
            self::$config = json_decode(\Config::get()->getValue('VC_CONFIG'), true);
        }

        $drivers = Driver::discover(true);

        // remove non existent drivers from config
        foreach (self::$config as $driver_name => $data) {
            if (!$drivers[$driver_name]) {
                unset (self::$config[$driver_name]);
            }
        }

        // add newly found drivers
        foreach ($drivers as $driver_name => $data) {
            if (!self::$config[$driver_name]) {
                self::$config[$driver_name] = [];
            }
        }

        if (empty(self::$config)) {
            return;
        }

        $is_config_corrected = false;
        foreach (self::$config as $driver_name => $config) {
            $class = 'ElanEv\\Driver\\' . $driver_name;

            if (!isset(self::$config[$driver_name]['title'])) {
                self::$config[$driver_name]['title'] = $driver_name;
            }

            if (!isset(self::$config[$driver_name]['config'])) {
                self::$config[$driver_name]['config'] = $class::getConfigOptions();
            }

            if (!isset(self::$config[$driver_name]['display_name'])) {
                self::$config[$driver_name]['display_name'] = $driver_name;
            }

            if (in_array('ElanEv\Driver\DriverInterface', class_implements($class)) !== false) {
                if ($create_features = $class::getCreateFeatures()) {
                    self::$config[$driver_name]['features']['create'] = self::convertDriverConfigToArray($create_features);
                }
            }
            if (in_array('ElanEv\Driver\RecordingInterface', class_implements($class)) !== false) {
                self::$config[$driver_name]['features']['record'] = self::convertDriverConfigToArray($class::getRecordFeature());
            }

            // Make sure Opencast Plugin is activated
            if (isset(self::$config[$driver_name]['opencast']) && !MeetingPlugin::checkOpenCast()) {
                unset(self::$config[$driver_name]['opencast']);
                $is_config_corrected = true;
            }
        }

        // When the config is corrected in between, it should be saved again.
        if ($is_config_corrected) {
            \Config::get()->store('VC_CONFIG', json_encode(self::$config));
        }
    }

    static function convertDriverConfigToArray($config_options)
    {
        $array = [];
        foreach ($config_options as $option) {
            $array[] = $option->toArray();
        }
        return $array;
    }

    static function getConfigByDriver($driver_name, $config_options)
    {
        self::loadConfig();

        $new_config = array();

        foreach ($config_options as $config) {
            if ($value = self::$config[$driver_name][0][$config->getName()]) {
                $config->setValue($value);
            }

            $new_config[$config->getName()] = $config;
        }

        return $new_config;
    }

    static function setConfigByDriver($driver_name, $config_options)
    {
        self::loadConfig();

        $valid_servers = true;
        if ($config_options['enable'] == 1 && (isset($config_options['servers']) && !count($config_options['servers']) || !isset($config_options['servers']))) {
            $config_options['enable'] = 0;
            $valid_servers = false;
        }
        $approved_servers = [];
        $unapproved_server_indices = [];
        $all_servers = [];
        foreach ($config_options as $key => $value) {
            if ($key == 'servers') {
                $config_tmp[$driver_name] = $config_options;
                $config_tmp[$driver_name]['enable'] = 1;
                $driver_factory = new DriverFactory($config_tmp);
                foreach ($value as $index => $server_info) {
                    $server_info['url'] = trim(rtrim($server_info['url'], '/'));
                    if (!$server_info['url']) {
                        $valid_servers = false;
                        continue;
                    }
                    $driver = $driver_factory->getDriver($driver_name, $index, true);
                    if (!$driver->checkServer()) {
                        $valid_servers = false;
                    }

                    if (!self::validateRoomSizes($server_info)) {
                        $valid_servers = false;
                    }

                    self::adjustCurrentMeetingsDefaultSettings($driver_name, $index, $server_info);

                    if ($valid_servers) {
                        $approved_servers[] = $server_info;
                    } else {
                        // Deactivate the server if it is not valid, to prevent consumption.
                        $server_info['active'] = false;
                        $unapproved_server_indices[] = '#' . ($index + 1);
                    }
                    // Keep them into $all_servers array, so the sorting remains the same.
                    $all_servers[] = $server_info;
                }
                self::$config[$driver_name][$key] = $all_servers;
            } else {
                self::$config[$driver_name][$key] = $value;
            }
        }

        if (count($approved_servers) == 0) {
            self::$config[$driver_name]['enable'] = 0;
        }

        \Config::get()->store('VC_CONFIG', json_encode(self::$config));

        $result = [
            "valid_servers" => $valid_servers,
            "invalid_indices" => $unapproved_server_indices
        ];

        return $result;
    }

    static function getConfig()
    {
        self::loadConfig();

        return self::$config;
    }

    static function getGeneralConfig() {
        return json_decode(\Config::get()->getValue('VC_GENERAL_CONFIG'), true);
    }

    static function setGeneralConfig($general_configs) {
        \Config::get()->store('VC_GENERAL_CONFIG', json_encode($general_configs));
    }

    static function getGeneralConfigValue($key) {
        $general_config = json_decode(\Config::get()->getValue('VC_GENERAL_CONFIG'), true);
        if (isset($general_config[$key])) {
            return $general_config[$key];
        }
        return false;
    }

    static function getConfigValueByDriver($driver_name, $key)
    {
        $config = json_decode(\Config::get()->getValue('VC_CONFIG'), true);

        foreach ($config as $dname => $dvals) {
            if ($driver_name == $dname && isset($dvals[$key])) {
                return $dvals[$key];
            }
        }

        return false;
    }

    //LOCAL PRIVATE FUNCTIONS

    private static function adjustCurrentMeetingsDefaultSettings($driver_name, $server_index, $server_info) {
        $meetings = Meeting::findBySQL('driver = ? AND server_index = ?', [$driver_name, $server_index]);
        foreach ($meetings as $meeting) {
            $features = json_decode($meeting->features, true);
            //take care of server maxParticipants
            if ((isset($server_info['maxParticipants']) && $server_info['maxParticipants'] > 0)
                && $features['maxParticipants'] > $server_info['maxParticipants']) {
                $features['maxParticipants'] = $server_info['maxParticipants'];
            }

            //take care of server room-sizes
            if (isset($server_info['roomsize-presets']) && count($server_info['roomsize-presets']) > 0) {
                foreach ($server_info['roomsize-presets'] as $size => $values) {
                    if ($features['maxParticipants'] >= $values['minParticipants']) {
                        unset($values['minParticipants']);
                        foreach ($values as $feature_names => $feature_value) {
                            $value = $feature_value;
                            if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
                                $value = filter_var($feature_value, FILTER_VALIDATE_BOOLEAN);
                            }
                            $features[$feature_names] = filter_var($feature_value, FILTER_VALIDATE_BOOLEAN);
                        }
                    }
                }
            }

            $meeting->features = json_encode($features);
            $meeting->store();
        }
    }

    private static function validateRoomSizes($server_info)
    {
        $min_participants_arr = [];
        $isValid = true;

        if (isset($server_info['roomsize-presets']) && count($server_info['roomsize-presets']) > 0) {
            foreach ($server_info['roomsize-presets'] as $size => $values) {
                if ($values['minParticipants'] < 0) {
                    $isValid = false;
                    break;
                }
                if (isset($values['minParticipants']) && in_array($values['minParticipants'], array_values($min_participants_arr))) {
                    $isValid = false;
                    break;
                }
                if ($server_info['maxParticipants'] != '' && $server_info['maxParticipants'] > 0) {
                    if ($values['minParticipants'] > $server_info['maxParticipants']) {
                        $isValid = false;
                        break;
                    }
                }
                $min_participants_arr[$size] = $values['minParticipants'];
            }
            if ($isValid) {
                foreach ($min_participants_arr as $size => $value) {
                    if ($size == 'small') {
                        if (isset($min_participants_arr['medium']) && $value >= $min_participants_arr['medium']) {
                            $isValid = false;
                            break;
                        }
                        if (isset($min_participants_arr['large']) && $value >= $min_participants_arr['large']) {
                            $isValid = false;
                            break;
                        }
                    }
                    if ($size == 'medium') {
                        if (isset($min_participants_arr['small']) && $value <= $min_participants_arr['small']) {
                            $isValid = false;
                            break;
                        }
                        if (isset($min_participants_arr['large']) && $value >= $min_participants_arr['large']) {
                            $isValid = false;
                            break;
                        }
                    }
                    if ($size == 'large') {
                        if (isset($min_participants_arr['small']) && $value <= $min_participants_arr['small']) {
                            $isValid = false;
                            break;
                        }
                        if (isset($min_participants_arr['medium']) && $value <= $min_participants_arr['medium']) {
                            $isValid = false;
                            break;
                        }
                    }
                }
            }
        }
        return $isValid;
    }

}
