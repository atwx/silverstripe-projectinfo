<?php

namespace Atwx\ProjectInfo\Extensions;

use SilverStripe\Core\Environment;
use SilverStripe\Admin\LeftAndMainExtension;
use SilverStripe\ORM\DB;

class LeftAndMainBackupExport extends LeftAndMainExtension
{
    private static $allowed_actions = array(
        'doBackup'
    );

    public function doBackup(){
        $host = Environment::getEnv('SS_DATABASE_SERVER');
        $user = Environment::getEnv('SS_DATABASE_USERNAME');
        $pass = Environment::getEnv('SS_DATABASE_PASSWORD');
        $name = Environment::getEnv('SS_DATABASE_NAME');
        \Spatie\DbDumper\Databases\MySql::create()
        ->setHost($host)
        ->setDbName($name)
        ->setUserName($user)
        ->setPassword($pass)
        ->dumpToFile(BASE_PATH.'/dump-'.$name.'-'.date('Y-m-d-H-i-s').'.sql');

        //Download file
        $file = BASE_PATH.'/dump-'.$name.'-'.date('Y-m-d-H-i-s').'.sql';
        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename="'.basename($file).'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        unlink($file);
        exit;
    }
}
