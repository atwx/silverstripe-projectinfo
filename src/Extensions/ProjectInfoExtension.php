<?php

namespace Atwx\ProjectInfo\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Forms\FieldList;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Control\Controller;

/**
 * Class \App\CustomSiteConfig
 *
 * @property SiteConfig|\App\CustomSiteConfig $owner
 * @property string $DateText
 * @property string $PlaceText
 * @property bool $ShowBanner
 * @property string $BannerText
 * @property string $AckMessageSubject
 * @property string $AckMessageContent
 */
class ProjectInfoExtension extends Extension
{
    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldToTab("Root.ProjectInfo", LiteralField::create("ProjectInfo", "<h2>Project Info</h2>"));
//        $fields->addFieldToTab("Root.ProjectInfo", new LiteralField("ProjectInfo", "<h3>Here is Info about this project:</h3>"));
        $fields->addFieldToTab("Root.ProjectInfo", LiteralField::create("ProjectInfo", "<p>Database Name: <strong>" . $this->getDatabaseName() . "</strong></p>"), "Intro");

        $controller = Controller::curr();
        $fields->addFieldToTab("Root.ProjectInfo", LiteralField::create("ProjectInfo", "<p><a class='btn btn-primary' href='".$controller->Link('doBackup')."' target='_blank'>Create database dump</a></p>"));
        $assetsDirSize = shell_exec("du -sh ".ASSETS_PATH."| awk '{ print $1 }'");
        $fields->addFieldToTab("Root.ProjectInfo", LiteralField::create("ProjectInfo", "<p><a class='btn btn-primary' href='".$controller->Link('doDownloadAssets')."' target='_blank'>Download assets</a></p><p>Assets: $assetsDirSize</p>"));
    }

    public function getDatabaseName()
    {
        return Environment::getEnv('SS_DATABASE_NAME');
    }
}
