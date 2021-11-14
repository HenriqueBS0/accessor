<?php

namespace HenriqueBS0\Accessor;

use PDO;
use PDOException;

class Connection {

    private static PDO $instance;

    public static function get(): PDO|PDOException
    {
        if(empty(self::$instance)) {
            try {

                $dns = ACCESSOR_CONFIG['driver'] . ':';

                $dns .= 'host=' . ACCESSOR_CONFIG['host'] . ';';
                $dns .= 'dbname=' . ACCESSOR_CONFIG['dbname'] . ';';
                $dns .= 'port=' . ACCESSOR_CONFIG['port'];

                self::$instance = new PDO(
                    $dns,
                    ACCESSOR_CONFIG['username'],
                    ACCESSOR_CONFIG['password'],
                    ACCESSOR_CONFIG['options']
                );

            }
            catch(PDOException $exeption) {
                return $exeption;
            }
        }

        return self::$instance;
    }
}