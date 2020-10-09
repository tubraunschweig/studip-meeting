<?php

require __DIR__.'/../vendor/autoload.php';

/**
 * Remove old config options and add a new one, holding all config data
 *
 * @author Till Glöggler <tgloeggl@uos.de>
 */

class AddGeneralOptions extends Migration {

    /**
     * {@inheritdoc}
     */
    public function description()
    {
        return "rearrange data structure to allow general (driver independent) options for meetings";
    }

    /**
     * {@inheritdoc}
     */
    public function up()
    {
        try {
            //migrate current settings
            if ($config = \Config::get()->VC_CONFIG) {

                $config['drivers'] = $config;
                $config['general'] = [
                    'allow_feedback' => 1
                ];

                \Config::get()->store('VC_CONFIG', json_encode($config));
            }
        } catch (InvalidArgumentException $ex) {

        }
    }

    /**
     * {@inheritdoc}
     */
    public function down()
    {
        if ($config = \Config::get()->VC_CONFIG) {

            $config = $config['drivers'];
            \Config::get()->store('VC_CONFIG', json_encode($config));
        }
    }
}
