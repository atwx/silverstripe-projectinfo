<?php

namespace Atwx\ProjectInfo\Extensions;

use SilverStripe\Core\Extension;
use Spatie\DbDumper\Databases\MySql;
use SilverStripe\Core\Environment;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

class LeftAndMainBackupExport extends Extension
{
    private static $allowed_actions = [
        'doBackup',
        'doDownloadAssets',
        'doListAssets',
        'doDownloadAsset',
    ];

    public function doBackup(): void
    {
        $host = Environment::getEnv('SS_DATABASE_SERVER');
        $user = Environment::getEnv('SS_DATABASE_USERNAME');
        $pass = Environment::getEnv('SS_DATABASE_PASSWORD');
        $name = Environment::getEnv('SS_DATABASE_NAME');

        $file = BASE_PATH . '/dump-' . $name . '-' . date('Y-m-d-H-i-s') . '.sql';

        MySql::create()
            ->setHost($host)
            ->setDbName($name)
            ->setUserName($user)
            ->setPassword($pass)
            ->dumpToFile($file);

        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        unlink($file);
        exit;
    }

    public function doDownloadAssets(): void
    {
        $zipFile = sys_get_temp_dir() . '/assets-' . date('Y-m-d-H-i-s') . '.zip';
        $rootPath = ASSETS_PATH;

        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();

        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename="assets.zip"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        unlink($zipFile);
        exit;
    }

    public function doListAssets(HTTPRequest $request): HTTPResponse
    {
        $rootPath = realpath(ASSETS_PATH) ?: ASSETS_PATH;
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isDir()) {
                $realPath = $file->getRealPath();
                $relativePath = substr($realPath, strlen($rootPath) + 1);
                $files[] = [
                    'path' => $relativePath,
                    'size' => $file->getSize(),
                    'mtime' => $file->getMTime(),
                    'md5' => md5_file($realPath),
                ];
            }
        }

        return HTTPResponse::create()
            ->addHeader('Content-Type', 'application/json')
            ->setBody(json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function doDownloadAsset(HTTPRequest $request): void
    {
        $relativePath = $request->getVar('path');

        if (!$relativePath) {
            http_response_code(400);
            echo 'Missing path parameter';
            exit;
        }

        // Security: reject path traversal attempts
        if (str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
            http_response_code(403);
            echo 'Invalid path';
            exit;
        }

        $realRoot = realpath(ASSETS_PATH) ?: ASSETS_PATH;
        $fullPath = $realRoot . DIRECTORY_SEPARATOR . $relativePath;

        if (!is_file($fullPath)) {
            http_response_code(404);
            echo 'File not found';
            exit;
        }

        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
}
