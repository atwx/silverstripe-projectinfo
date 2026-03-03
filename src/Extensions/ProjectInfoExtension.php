<?php

namespace Atwx\ProjectInfo\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Forms\FieldList;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Control\Controller;

/**
 * @property SiteConfig|ProjectInfoExtension $owner
 */
class ProjectInfoExtension extends Extension
{
    public function updateCMSFields(FieldList $fields): void
    {
        $controller = Controller::curr();
        $assetsDirSize = shell_exec("du -sh " . ASSETS_PATH . " | awk '{ print $1 }'");
        $assetListUrl = $controller->Link('doListAssets');

        $fields->addFieldToTab("Root.ProjectInfo", LiteralField::create("PIHeading", "<h2>Project Info</h2>"));
        $fields->addFieldToTab("Root.ProjectInfo", LiteralField::create("PIDatabase", "<p>Database: <strong>" . $this->getDatabaseName() . "</strong></p>"));

        $fields->addFieldToTab("Root.ProjectInfo", LiteralField::create("PIBackup",
            "<p><a class='btn btn-primary' href='" . $controller->Link('doBackup') . "' target='_blank'>Create database dump</a></p>"
        ));

        $fields->addFieldToTab("Root.ProjectInfo", LiteralField::create("PIAssets",
            "<p>Assets: <strong>$assetsDirSize</strong></p>"
            . "<p>"
            . "<a class='btn btn-primary' href='" . $controller->Link('doDownloadAssets') . "' target='_blank'>Download all assets (ZIP)</a>&nbsp;&nbsp;"
            . "<a class='btn btn-secondary' href='" . $assetListUrl . "' target='_blank'>Asset list (JSON)</a>"
            . "</p>"
            . "<p><small>To pull individual assets: <code>GET " . $assetListUrl . "</code> → then fetch each file via <code>" . $controller->Link('doDownloadAsset') . "?path=&lt;relative-path&gt;</code></small></p>"
        ));
    }

    public function getDatabaseName(): string
    {
        return (string) Environment::getEnv('SS_DATABASE_NAME');
    }
}
