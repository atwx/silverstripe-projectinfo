<?php

namespace Atwx\ProjectInfo\Extensions;

use SilverStripe\Core\Extension;
use Spatie\DbDumper\Databases\MySql;
use SilverStripe\Core\Environment;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;


class LeftAndMainBackupExport extends Extension
{
    private static $allowed_actions = array(
        'doBackup',
        'doDownloadAssets',
    );

    public function doBackup() {
        $host = Environment::getEnv('SS_DATABASE_SERVER');
        $user = Environment::getEnv('SS_DATABASE_USERNAME');
        $pass = Environment::getEnv('SS_DATABASE_PASSWORD');
        $name = Environment::getEnv('SS_DATABASE_NAME');
        MySql::create()
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

    public function doDownloadAssets() {
        // Initialize archive object
        $zip = new ZipArchive();
        $zip->open('assets.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $rootPath = ASSETS_PATH;

        // Create recursive directory iterator
        /** @var SplFileInfo[] $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file)
        {
            // Skip directories (they would be added automatically)
            if (!$file->isDir())
            {
                // Get real and relative path for current file
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath) + 1);

                // Add current file to archive
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();
        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename="assets.zip"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Content-Length: ' . filesize('assets.zip'));
        readfile('assets.zip');
        unlink('assets.zip');
    }
}
